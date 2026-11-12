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
