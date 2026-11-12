<?php
declare(strict_types=1);
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\ValueObject\{MoodleBinding, MoodleAccountDraft};
use Domain\Shared\ValueObject\EntityId;
use GuzzleHttp\{Client, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Infrastructure\Lms\RestMoodleGateway;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;
final class MoodleAccountProvisionerTest extends TestCase
{
    public function testReceiptReadWorksWithProcessingDisabledAndSendsOnlyExactReferences(): void
    {
        $config = $this->config();
        $config->setConfigKey("moodle", ["provisioning_enabled" => false]);
        $binding = $this->binding();
        $operation = EntityId::generate();
        $hash = str_repeat("c", 64);
        $history = [];
        $handler = HandlerStack::create(
            new MockHandler([
                $this->handshake(),
                new Response(
                    200,
                    [],
                    json_encode([
                        "protocol" => 1,
                        "instance_hash" => $binding->instanceFingerprint,
                        "operation" => $operation->value,
                        "request_hash" => $hash,
                        "status" => "recorded",
                        "user_id" => "123",
                        "sign_in_status" => "email_review_required",
                    ]),
                ),
            ]),
        );
        $handler->push(Middleware::history($history));
        $receipt = new RestMoodleGateway($config, new Client(["handler" => $handler]))->receipt(
            $binding,
            $operation,
            $hash,
        );
        self::assertSame("recorded", $receipt->status);
        self::assertSame("123", $receipt->account->user->value);
        self::assertSame("email_review_required", $receipt->account->signInStatus);
        self::assertCount(2, $history);
        parse_str((string) $history[1]["request"]->getBody(), $body);
        self::assertSame("local_etsis_get_account_receipt", $body["wsfunction"]);
        self::assertSame($operation->value, $body["operation"]);
        self::assertSame($hash, $body["request_hash"]);
        foreach (
            ["firstname", "lastname", "username", "email", "password", "reason", "policy_hash"]
            as $field
        ) {
            self::assertArrayNotHasKey($field, $body);
        }
        self::assertSame("", $history[1]["request"]->getUri()->getQuery());
        self::assertSame("POST", $history[1]["request"]->getMethod());
        self::assertTrue($history[1]["options"]["verify"]);
        self::assertFalse($history[1]["options"]["allow_redirects"]);
    }

    public function testReceiptReaderAcceptsUnknownAndRestrictedWithoutInventingAnAccount(): void
    {
        foreach (["not_observed", "restricted"] as $status) {
            $binding = $this->binding();
            $operation = EntityId::generate();
            $hash = str_repeat("c", 64);
            $mock = new MockHandler([
                $this->handshake(),
                new Response(
                    200,
                    [],
                    json_encode([
                        "protocol" => 1,
                        "instance_hash" => $binding->instanceFingerprint,
                        "operation" => $operation->value,
                        "request_hash" => $hash,
                        "status" => $status,
                        "user_id" => "0",
                        "sign_in_status" => "unknown",
                    ]),
                ),
            ]);
            $receipt = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]))->receipt(
                $binding,
                $operation,
                $hash,
            );
            self::assertSame($status, $receipt->status);
            self::assertNull($receipt->account);
        }
    }

    public function testReceiptReaderRejectsMalformedOrCrossOperationResultsWithoutProviderDetails(): void
    {
        $binding = $this->binding();
        $operation = EntityId::generate();
        $hash = str_repeat("c", 64);
        $result = [
            "protocol" => 1,
            "instance_hash" => $binding->instanceFingerprint,
            "operation" => $operation->value,
            "request_hash" => $hash,
            "status" => "recorded",
            "user_id" => "123",
            "sign_in_status" => "email_sent",
        ];
        foreach (
            [
                ["operation" => EntityId::generate()->value],
                ["request_hash" => str_repeat("d", 64)],
                ["instance_hash" => str_repeat("d", 64)],
                ["protocol" => 2],
                ["status" => "fictional-private-upstream"],
                ["user_id" => 123],
                ["user_id" => "0"],
                ["sign_in_status" => "email_pending"],
                ["status" => "not_observed"],
                ["private" => "fictional-private-upstream"],
            ]
            as $changes
        ) {
            $mock = new MockHandler([
                $this->handshake(),
                new Response(200, [], json_encode(array_replace($result, $changes))),
            ]);
            try {
                new RestMoodleGateway($this->config(), new Client(["handler" => $mock]))->receipt(
                    $binding,
                    $operation,
                    $hash,
                );
                self::fail("Malformed receipt accepted.");
            } catch (MoodleConnectionUnavailableException $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString("fictional-private-upstream", $error->getMessage());
            }
        }
    }

    private function config(): Collection
    {
        $config = Collection::factory([]);
        $config->setConfigKey("moodle", [
            "enabled" => true,
            "provisioning_enabled" => true,
            "url" => "https://moodle.example.invalid",
            "token" => "fictional-private-token",
            "ca_file" => "",
        ]);
        return $config;
    }
    private function binding(): MoodleBinding
    {
        return new MoodleBinding(
            EntityId::generate(),
            hash("sha256", "https://moodle.example.invalid"),
            str_repeat("a", 64),
            EntityId::generate(),
            "2026-11-12T12:00:00Z",
        );
    }
    private function handshake(string $url = "https://moodle.example.invalid"): Response
    {
        return new Response(
            200,
            [],
            json_encode(["siteurl" => $url, "functions" => [["name" => "core_webservice_get_site_info"]]]),
        );
    }
    public function testPolicyAndCreationUseBoundedPrivatePostAndExactReceipt(): void
    {
        $binding = $this->binding();
        $operation = EntityId::generate();
        $history = [];
        $mock = new MockHandler([
            $this->handshake(),
            new Response(
                200,
                [],
                json_encode([
                    "protocol" => 1,
                    "instance_hash" => $binding->instanceFingerprint,
                    "policy_hash" => str_repeat("b", 64),
                    "sso" => true,
                    "password_email" => true,
                ]),
            ),
            $this->handshake(),
            new Response(
                200,
                [],
                json_encode([
                    "protocol" => 1,
                    "instance_hash" => $binding->instanceFingerprint,
                    "operation" => $operation->value,
                    "user_id" => "123",
                    "sign_in_status" => "email_sent",
                ]),
            ),
        ]);
        $handler = HandlerStack::create($mock);
        $handler->push(Middleware::history($history));
        $gateway = new RestMoodleGateway($this->config(), new Client(["handler" => $handler]));
        $policy = $gateway->policy($binding);
        self::assertTrue($policy->permits("sso"));
        self::assertTrue($policy->permits("password_email"));
        $result = $gateway->provision(
            $binding,
            $operation,
            $policy->hash,
            new MoodleAccountDraft("alex_example", "fictional@example.invalid", "password_email"),
            "Alex",
            "Example",
        );
        self::assertSame("123", $result->user->value);
        self::assertSame("email_sent", $result->signInStatus);
        self::assertCount(4, $history);
        foreach ($history as $call) {
            self::assertSame("POST", $call["request"]->getMethod());
            self::assertSame("", $call["request"]->getUri()->getQuery());
            self::assertFalse($call["options"]["allow_redirects"]);
            self::assertTrue($call["options"]["verify"]);
            self::assertLessThanOrEqual(15, $call["options"]["timeout"]);
        }
        parse_str((string) $history[3]["request"]->getBody(), $body);
        self::assertSame($operation->value, $body["operation"]);
        self::assertSame("local_etsis_provision_account", $body["wsfunction"]);
        self::assertArrayNotHasKey("password", $body);
        self::assertArrayNotHasKey("reason", $body);
    }
    public function testDisabledAndWrongSiteNeverSendAccountAttributes(): void
    {
        $config = $this->config();
        $config->setConfigKey("moodle", ["provisioning_enabled" => false]);
        $mock = new MockHandler([$this->handshake("https://different.example.invalid")]);
        $gateway = new RestMoodleGateway($config, new Client(["handler" => $mock]));
        $binding = $this->binding();
        $operation = EntityId::generate();
        $draft = new MoodleAccountDraft("alex_example", "fictional@example.invalid", "sso");
        try {
            $gateway->provision($binding, $operation, str_repeat("b", 64), $draft, "Alex", "Example");
            self::fail();
        } catch (MoodleConnectionUnavailableException) {
            self::assertNull($mock->getLastRequest());
        }
        $config->setConfigKey("moodle", ["provisioning_enabled" => true]);
        try {
            $gateway->provision($binding, $operation, str_repeat("b", 64), $draft, "Alex", "Example");
            self::fail();
        } catch (MoodleConnectionUnavailableException $error) {
            self::assertNull($error->getPrevious());
            self::assertSame(0, $mock->count());
            self::assertStringNotContainsString(
                "fictional@example.invalid",
                (string) $mock->getLastRequest()->getBody(),
            );
        }
    }
    public function testDisablementDuringHandshakeStopsBeforeAccountAttributesAreSent(): void
    {
        $config = $this->config();
        $calls = 0;
        $handler = function ($request, $options) use ($config, &$calls) {
            $calls++;
            $config->setConfigKey("moodle", ["provisioning_enabled" => false]);
            return \GuzzleHttp\Promise\Create::promiseFor($this->handshake());
        };
        $gateway = new RestMoodleGateway($config, new Client(["handler" => $handler]));
        try {
            $gateway->provision(
                $this->binding(),
                EntityId::generate(),
                str_repeat("b", 64),
                new MoodleAccountDraft("alex_example", "fictional@example.invalid", "sso"),
                "Alex",
                "Example",
            );
            self::fail();
        } catch (MoodleConnectionUnavailableException) {
            self::assertSame(1, $calls);
        }
    }
    public function testWrongOperationAndRawErrorsAreNotAcceptedOrExposed(): void
    {
        foreach (
            [
                [
                    "protocol" => 1,
                    "instance_hash" => str_repeat("a", 64),
                    "operation" => EntityId::generate()->value,
                    "user_id" => "123",
                    "sign_in_status" => "sso_ready",
                ],
                [
                    "exception" => "fictional-private-response",
                    "errorcode" => "fictional-secret",
                    "message" => "fictional@example.invalid",
                ],
            ]
            as $response
        ) {
            $mock = new MockHandler([$this->handshake(), new Response(200, [], json_encode($response))]);
            $gateway = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]));
            try {
                $gateway->provision(
                    $this->binding(),
                    EntityId::generate(),
                    str_repeat("b", 64),
                    new MoodleAccountDraft("alex_example", "fictional@example.invalid", "sso"),
                    "Alex",
                    "Example",
                );
                self::fail();
            } catch (MoodleConnectionUnavailableException $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString("fictional", $error->getMessage());
            }
        }
    }
}
