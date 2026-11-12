<?php

declare(strict_types=1);

use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodleResourceKind;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Infrastructure\Lms\RestMoodleGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class MoodleRecordReaderTest extends TestCase
{
    private function config(): Collection
    {
        $config = Collection::factory([]);
        $config->setConfigKey("moodle", [
            "enabled" => true,
            "url" => "https://moodle.example.invalid/campus",
            "token" => "fictional-secret",
            "ca_file" => "",
        ]);
        return $config;
    }
    private function handshake(bool $lookups = true): Response
    {
        return new Response(
            200,
            [],
            json_encode([
                "siteurl" => "https://moodle.example.invalid/campus",
                "functions" => array_map(
                    static fn($name) => ["name" => $name],
                    $lookups
                        ? [
                            "core_webservice_get_site_info",
                            "core_user_get_users_by_field",
                            "core_course_get_courses_by_field",
                        ]
                        : ["core_webservice_get_site_info"],
                ),
            ]),
        );
    }
    public function testOnlyExplicitIdIsRequestedAndProfileFieldsAreDiscarded(): void
    {
        foreach ([MoodleResourceKind::User, MoodleResourceKind::Course] as $kind) {
            $record = [
                "id" => 123,
                "suspended" => 1,
                "visible" => 0,
                "fullname" => "Fictional private name",
                "email" => "private@example.invalid",
                "summary" => "<script>private</script>",
            ];
            $response =
                $kind === MoodleResourceKind::User ? [$record] : ["courses" => [$record], "warnings" => []];
            $mock = new MockHandler([$this->handshake(), new Response(200, [], json_encode($response))]);
            $gateway = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]));
            $result = $gateway->inspect($kind, new MoodleExternalId("123"));
            self::assertTrue($result->returned);
            self::assertSame(
                $kind === MoodleResourceKind::User
                    ? ["suspended" => true, "deleted" => null]
                    : ["visible" => false],
                $result->describe(),
            );
            parse_str((string) $mock->getLastRequest()->getBody(), $body);
            self::assertSame("id", $body["field"]);
            self::assertSame(
                $kind === MoodleResourceKind::User ? ["123"] : "123",
                $body[$kind === MoodleResourceKind::User ? "values" : "value"],
            );
            self::assertSame(
                $kind === MoodleResourceKind::User
                    ? "core_user_get_users_by_field"
                    : "core_course_get_courses_by_field",
                $body["wsfunction"],
            );
            self::assertSame("", $mock->getLastRequest()->getUri()->getQuery());
            foreach (["Fictional private name", "private@example.invalid", "<script>", "123"] as $private) {
                self::assertStringNotContainsString($private, json_encode($result));
                self::assertStringNotContainsString($private, print_r($result, true));
            }
            self::assertCount(0, $mock);
        }
    }
    public function testEmptyResultsDoNotImplyThatStatusFlagsAreFalse(): void
    {
        foreach ([MoodleResourceKind::User, MoodleResourceKind::Course] as $kind) {
            $body = $kind === MoodleResourceKind::User ? [] : ["courses" => [], "warnings" => []];
            $mock = new MockHandler([$this->handshake(), new Response(200, [], json_encode($body))]);
            $gateway = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]));
            $result = $gateway->inspect($kind, new MoodleExternalId("123"));
            self::assertFalse($result->returned);
            self::assertSame(
                $kind === MoodleResourceKind::User
                    ? ["suspended" => null, "deleted" => null]
                    : ["visible" => null],
                $result->describe(),
            );
        }
    }
    public function testUnadvertisedLookupDoesNotRequestARecord(): void
    {
        $mock = new MockHandler([$this->handshake(false), new Response(200, [], "[]")]);
        $gateway = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]));
        try {
            $gateway->inspect(MoodleResourceKind::User, new MoodleExternalId("123"));
            self::fail();
        } catch (MoodleConnectionUnavailableException) {
            parse_str((string) $mock->getLastRequest()->getBody(), $body);
            self::assertSame("core_webservice_get_site_info", $body["wsfunction"]);
            self::assertCount(1, $mock);
        }
    }
    public static function invalidRecords(): iterable
    {
        yield [[["id" => 124]]];
        yield [[["id" => 123.0]]];
        yield [[["id" => 123, "suspended" => "false"]]];
        yield [[["id" => 123, "suspended" => 2]]];
        yield [[["id" => 123], ["id" => 123]]];
        yield [["exception" => "private", "message" => "fictional-secret"]];
        yield [["unexpected" => "private"]];
    }
    #[DataProvider("invalidRecords")]
    public function testAmbiguousSpoofedOrMalformedResultsCannotBeAccepted(array $result): void
    {
        $mock = new MockHandler([
            $this->handshake(),
            new Response(200, [], json_encode($result, JSON_PRESERVE_ZERO_FRACTION)),
        ]);
        $gateway = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]));
        try {
            $gateway->inspect(MoodleResourceKind::User, new MoodleExternalId("123"));
            self::fail();
        } catch (MoodleConnectionUnavailableException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString("fictional-secret", $error->getMessage());
        }
    }
    public function testConfigurationChangeAfterHandshakePreventsLookup(): void
    {
        $config = $this->config();
        $mock = new MockHandler([$this->handshake()], static function () use ($config): void {
            $config->setConfigKey("moodle", ["enabled" => false]);
        });
        $gateway = new RestMoodleGateway($config, new Client(["handler" => $mock]));
        $this->expectException(MoodleConnectionUnavailableException::class);
        $gateway->inspect(MoodleResourceKind::User, new MoodleExternalId("123"));
    }
    public function testCourseWarningsAndMissingWarningEnvelopeDoNotProduceAPartialMatch(): void
    {
        foreach (
            [
                ["courses" => [["id" => 123]]],
                ["courses" => [["id" => 123]], "warnings" => [["message" => "private"]]],
            ]
            as $body
        ) {
            $mock = new MockHandler([$this->handshake(), new Response(200, [], json_encode($body))]);
            $gateway = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]));
            try {
                $gateway->inspect(MoodleResourceKind::Course, new MoodleExternalId("123"));
                self::fail();
            } catch (MoodleConnectionUnavailableException $error) {
                self::assertNull($error->getPrevious());
            }
        }
    }
    public function testDisabledDuringLookupDiscardsRecordStatus(): void
    {
        $config = $this->config();
        $calls = 0;
        $mock = new MockHandler(
            [$this->handshake(), new Response(200, [], '[{"id":123}]')],
            static function () use ($config, &$calls): void {
                if (++$calls === 2) {
                    $config->setConfigKey("moodle", ["enabled" => false]);
                }
            },
        );
        $gateway = new RestMoodleGateway($config, new Client(["handler" => $mock]));
        $this->expectException(MoodleConnectionUnavailableException::class);
        $gateway->inspect(MoodleResourceKind::User, new MoodleExternalId("123"));
    }
    public function testLargeExternalIdIsNotRounded(): void
    {
        $id = "9007199254740993";
        $mock = new MockHandler([$this->handshake(), new Response(200, [], '[{"id":9007199254740993}]')]);
        $gateway = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]));
        self::assertTrue($gateway->inspect(MoodleResourceKind::User, new MoodleExternalId($id))->returned);
    }
    public static function invalidIds(): iterable
    {
        foreach (
            [
                "",
                "0",
                "-1",
                "01",
                "1.0",
                "1e2",
                " 1",
                "9223372036854775808",
                "123&field=email",
                "01K000000000000000000000001",
            ]
            as $id
        ) {
            yield [$id];
        }
    }
    #[DataProvider("invalidIds")]
    public function testOnlyCanonicalMoodleIdsAreAccepted(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MoodleExternalId($id);
    }
}
