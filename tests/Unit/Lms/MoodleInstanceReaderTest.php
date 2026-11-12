<?php

declare(strict_types=1);

use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\ValueObject\MoodleInstanceIdentity;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Infrastructure\Lms\RestMoodleGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class MoodleInstanceReaderTest extends TestCase
{
    private const ID = "0123456789abcdef0123456789abcdef";

    private function config(): Collection
    {
        $config = Collection::factory([]);
        $config->setConfigKey("moodle", [
            "enabled" => true,
            "url" => "https://moodle.example.invalid",
            "token" => "fictional-private-token",
            "ca_file" => "",
        ]);
        return $config;
    }

    private function handshake(bool $plugin = true): Response
    {
        return new Response(
            200,
            [],
            json_encode([
                "siteurl" => "https://moodle.example.invalid",
                "functions" => array_map(
                    static fn($name) => ["name" => $name],
                    $plugin
                        ? ["core_webservice_get_site_info", "local_etsis_get_instance_info"]
                        : ["core_webservice_get_site_info"],
                ),
            ]),
        );
    }

    public function testIdentityIsComparedPrivatelyAndOnlyFingerprintCanBeDisplayed(): void
    {
        $identity = new MoodleInstanceIdentity(self::ID);
        self::assertTrue($identity->matches(new MoodleInstanceIdentity(self::ID)));
        self::assertFalse($identity->matches(new MoodleInstanceIdentity(str_repeat("a", 32))));
        self::assertSame(hash("sha256", self::ID), $identity->fingerprint());
        self::assertStringNotContainsString(self::ID, json_encode($identity));
        self::assertStringNotContainsString(self::ID, print_r($identity, true));
    }

    public function testOnlyFixedPluginFunctionIsCalledAfterCanonicalHandshake(): void
    {
        $mock = new MockHandler([
            $this->handshake(),
            new Response(200, [], json_encode(["protocol" => 1, "instance_id" => self::ID])),
        ]);
        $gateway = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]));
        self::assertTrue($gateway->identify()->matches(new MoodleInstanceIdentity(self::ID)));
        parse_str((string) $mock->getLastRequest()->getBody(), $body);
        self::assertSame(
            [
                "wstoken" => "fictional-private-token",
                "wsfunction" => "local_etsis_get_instance_info",
                "moodlewsrestformat" => "json",
            ],
            $body,
        );
        self::assertSame("", $mock->getLastRequest()->getUri()->getQuery());
        self::assertCount(0, $mock);
    }

    public function testUnadvertisedPluginStopsBeforeIdentityRequest(): void
    {
        $mock = new MockHandler([$this->handshake(false), new Response(200, [], "{}")]);
        $gateway = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]));
        try {
            $gateway->identify();
            self::fail("Missing plugin accepted.");
        } catch (MoodleConnectionUnavailableException $error) {
            self::assertNull($error->getPrevious());
            self::assertCount(1, $mock);
        }
    }

    public static function invalidIdentities(): iterable
    {
        yield [["protocol" => 2, "instance_id" => self::ID]];
        yield [["protocol" => "1", "instance_id" => self::ID]];
        yield [["protocol" => 1, "instance_id" => strtoupper(self::ID)]];
        yield [["protocol" => 1, "instance_id" => "private-bad-identifier"]];
        yield [["protocol" => 1, "instance_id" => 123]];
        yield [["protocol" => 1]];
        yield [["protocol" => 1, "instance_id" => self::ID, "private" => "unexpected"]];
        yield [["exception" => "private", "errorcode" => "integrationdisabled", "message" => "private"]];
    }

    #[DataProvider("invalidIdentities")]
    public function testUnsupportedMalformedOrLeakyResponsesAreRefused(array $response): void
    {
        $mock = new MockHandler([$this->handshake(), new Response(200, [], json_encode($response))]);
        $gateway = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]));
        try {
            $gateway->identify();
            self::fail("Invalid identity accepted.");
        } catch (MoodleConnectionUnavailableException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString("private", $error->getMessage());
            self::assertFalse($mock->getLastRequest() === null);
        }
    }

    public function testDisablementAfterHandshakePreventsIdentityRead(): void
    {
        $config = $this->config();
        $mock = new MockHandler([$this->handshake()], static function () use ($config): void {
            $config->setConfigKey("moodle", ["enabled" => false]);
        });
        $this->expectException(MoodleConnectionUnavailableException::class);
        new RestMoodleGateway($config, new Client(["handler" => $mock]))->identify();
    }

    public function testConfigurationChangeDuringIdentityReadDiscardsResult(): void
    {
        $config = $this->config();
        $calls = 0;
        $mock = new MockHandler(
            [
                $this->handshake(),
                new Response(200, [], json_encode(["protocol" => 1, "instance_id" => self::ID])),
            ],
            static function () use ($config, &$calls): void {
                if (++$calls === 2) {
                    $config->setConfigKey("moodle", ["token" => "changed-fictional-token"]);
                }
            },
        );
        $this->expectException(MoodleConnectionUnavailableException::class);
        new RestMoodleGateway($config, new Client(["handler" => $mock]))->identify();
    }

    public function testDisabledIntegrationCannotMakeAnyRequest(): void
    {
        $config = $this->config();
        $config->setConfigKey("moodle", ["enabled" => false]);
        $mock = new MockHandler([]);
        try {
            new RestMoodleGateway($config, new Client(["handler" => $mock]))->identify();
            self::fail("Disabled integration accepted.");
        } catch (MoodleConnectionUnavailableException) {
            self::assertNull($mock->getLastRequest());
        }
    }
}
