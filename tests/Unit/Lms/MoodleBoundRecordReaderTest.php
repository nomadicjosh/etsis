<?php

declare(strict_types=1);

use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodleResourceKind;
use Domain\Lms\ValueObject\MoodleSetupIssue;
use Domain\Shared\ValueObject\EntityId;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Infrastructure\Lms\RestMoodleGateway;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class MoodleBoundRecordReaderTest extends TestCase
{
    private const ID = "0123456789abcdef0123456789abcdef";
    private function config(): Collection
    {
        $config = Collection::factory([]);
        $config->setConfigKey("moodle", [
            "enabled" => true,
            "url" => "https://moodle.example.invalid",
            "token" => "fictional-token",
            "ca_file" => "",
        ]);
        return $config;
    }
    private function handshake(): Response
    {
        return new Response(
            200,
            [],
            json_encode([
                "siteurl" => "https://moodle.example.invalid",
                "functions" => array_map(static fn($name) => ["name" => $name], [
                    "core_webservice_get_site_info",
                    "local_etsis_get_instance_info",
                    "core_user_get_users_by_field",
                ]),
            ]),
        );
    }
    private function identity(string $id = self::ID): Response
    {
        return new Response(200, [], json_encode(["protocol" => 1, "instance_id" => $id]));
    }
    private function binding(): MoodleBinding
    {
        return new MoodleBinding(
            EntityId::generate(),
            hash("sha256", "https://moodle.example.invalid"),
            hash("sha256", self::ID),
            EntityId::generate(),
            "2026-11-11T12:00:00Z",
        );
    }
    public function testOneLookupIsSurroundedByInstanceChecksWithOneShrinkingBudget(): void
    {
        $history = [];
        $mock = new MockHandler([
            $this->handshake(),
            $this->identity(),
            new Response(200, [], '[{"id":123,"suspended":0,"fullname":"Fictional private name"}]'),
            $this->identity(),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $result = new RestMoodleGateway($this->config(), new Client(["handler" => $stack]))->inspectBound(
            $this->binding(),
            MoodleResourceKind::User,
            new MoodleExternalId("123"),
        );
        self::assertTrue($result->returned);
        self::assertSame(["suspended" => false, "deleted" => null], $result->describe());
        $functions = [];
        foreach ($history as $request) {
            parse_str((string) $request["request"]->getBody(), $body);
            $functions[] = $body["wsfunction"];
            self::assertSame("", $request["request"]->getUri()->getQuery());
            self::assertGreaterThan(0, $request["options"]["timeout"]);
            self::assertLessThanOrEqual(15, $request["options"]["timeout"]);
            self::assertTrue($request["options"]["verify"]);
            self::assertFalse($request["options"]["allow_redirects"]);
        }
        self::assertSame(
            [
                "core_webservice_get_site_info",
                "local_etsis_get_instance_info",
                "core_user_get_users_by_field",
                "local_etsis_get_instance_info",
            ],
            $functions,
        );
        self::assertStringNotContainsString("Fictional private name", json_encode($result));
    }
    public function testInitialMismatchStopsBeforeAnyUserLookup(): void
    {
        $mock = new MockHandler([$this->handshake(), $this->identity(str_repeat("a", 32))]);
        try {
            new RestMoodleGateway($this->config(), new Client(["handler" => $mock]))->inspectBound(
                $this->binding(),
                MoodleResourceKind::User,
                new MoodleExternalId("123"),
            );
            self::fail();
        } catch (MoodleConnectionUnavailableException $error) {
            self::assertSame(MoodleSetupIssue::InstallationMismatch, $error->setupIssue);
            self::assertNull($error->getPrevious());
            self::assertCount(0, $mock);
        }
    }
    public function testReplacementDuringLookupDiscardsReturnedRecord(): void
    {
        $mock = new MockHandler([
            $this->handshake(),
            $this->identity(),
            new Response(200, [], '[{"id":123}]'),
            $this->identity(str_repeat("b", 32)),
        ]);
        try {
            new RestMoodleGateway($this->config(), new Client(["handler" => $mock]))->inspectBound(
                $this->binding(),
                MoodleResourceKind::User,
                new MoodleExternalId("123"),
            );
            self::fail();
        } catch (MoodleConnectionUnavailableException $error) {
            self::assertSame(MoodleSetupIssue::InstallationMismatch, $error->setupIssue);
        }
    }
    public function testConfigurationChangeDuringLookupStopsBeforeFinalInstanceCheck(): void
    {
        $config = $this->config();
        $calls = 0;
        $mock = new MockHandler(
            [$this->handshake(), $this->identity(), new Response(200, [], '[{"id":123}]')],
            static function () use ($config, &$calls): void {
                if (++$calls === 3) {
                    $config->setConfigKey("moodle", ["enabled" => false]);
                }
            },
        );
        $this->expectException(MoodleConnectionUnavailableException::class);
        new RestMoodleGateway($config, new Client(["handler" => $mock]))->inspectBound(
            $this->binding(),
            MoodleResourceKind::User,
            new MoodleExternalId("123"),
        );
    }
}
