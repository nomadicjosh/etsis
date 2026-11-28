<?php

declare(strict_types=1);

namespace Tests\Unit\Lms;

use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Shared\ValueObject\EntityId;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Infrastructure\Lms\RestMoodleGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class MoodleGradeGatewayTest extends TestCase
{
    private function context(array $response, bool $advertised = true): array
    {
        $url = "https://moodle.example.invalid/campus";
        $binding = new MoodleBinding(
            EntityId::generate(),
            hash("sha256", $url),
            str_repeat("b", 64),
            EntityId::generate(),
            "2026-08-10T10:00:00Z",
        );
        $config = Collection::factory([]);
        $config->setConfigKey("moodle", [
            "enabled" => true,
            "url" => $url,
            "token" => "fictional-grade-token",
            "ca_file" => "",
        ]);
        $mock = new MockHandler([
            new Response(
                200,
                [],
                json_encode([
                    "siteurl" => $url,
                    "functions" => $advertised
                        ? [
                            ["name" => "core_webservice_get_site_info"],
                            ["name" => "local_etsis_get_course_grade"],
                        ]
                        : [["name" => "core_webservice_get_site_info"]],
                ]),
            ),
            new Response(200, [], json_encode($response)),
        ]);
        return [
            new RestMoodleGateway($config, new Client(["handler" => $mock])),
            $binding,
            $mock,
        ];
    }

    public static function gradeResponse(): array
    {
        return [
            "protocol" => 1,
            "instance_hash" => str_repeat("b", 64),
            "course_id" => 42,
            "user_id" => 73,
            "grade" => [
                "state" => "available",
                "item_id" => "97",
                "grade" => "91.25000",
                "minimum" => "0.00000",
                "maximum" => "100.00000",
                "revision" => str_repeat("a", 64),
            ],
        ];
    }

    public function testOneExplicitUserAndCourseAreRequestedWithoutCredentialsInTheUrl(): void
    {
        [$gateway, $binding, $mock] = $this->context(self::gradeResponse());
        $grade = $gateway->courseGrade(
            $binding,
            new MoodleExternalId("42"),
            new MoodleExternalId("73"),
        );
        self::assertSame("91.25000", $grade->facts["grade"]);
        parse_str((string) $mock->getLastRequest()->getBody(), $body);
        self::assertSame("42", $body["course_id"]);
        self::assertSame("73", $body["user_id"]);
        self::assertSame("local_etsis_get_course_grade", $body["wsfunction"]);
        self::assertStringNotContainsString(
            "fictional-grade-token",
            (string) $mock->getLastRequest()->getUri(),
        );
    }

    public static function invalidResponses(): iterable
    {
        yield "foreign user" => [
            array_replace(self::gradeResponse(), ["user_id" => 74]),
        ];
        yield "foreign course" => [
            array_replace(self::gradeResponse(), ["course_id" => 43]),
        ];
        yield "wrong installation" => [
            array_replace(self::gradeResponse(), [
                "instance_hash" => str_repeat("c", 64),
            ]),
        ];
        yield "unexpected payload" => [
            self::gradeResponse() + [
                "feedback" => "fictional forbidden feedback",
            ],
        ];
        $hidden = self::gradeResponse();
        $hidden["grade"]["state"] = "hidden";
        yield "hidden value leaked" => [$hidden];
        $decimal = self::gradeResponse();
        $decimal["grade"]["grade"] = "<b>91</b>";
        yield "formatted markup" => [$decimal];
    }

    #[DataProvider("invalidResponses")]
    public function testForeignOrOverbroadResponsesAreRefusedWithoutUpstreamDetails(
        array $response,
    ): void {
        [$gateway, $binding] = $this->context($response);
        $this->expectException(MoodleConnectionUnavailableException::class);
        $gateway->courseGrade(
            $binding,
            new MoodleExternalId("42"),
            new MoodleExternalId("73"),
        );
    }

    public function testUnavailableFunctionPreventsTheGradeLookup(): void
    {
        [$gateway, $binding, $mock] = $this->context(
            self::gradeResponse(),
            false,
        );
        try {
            $gateway->courseGrade(
                $binding,
                new MoodleExternalId("42"),
                new MoodleExternalId("73"),
            );
            self::fail("Unadvertised function should be refused.");
        } catch (MoodleConnectionUnavailableException) {
            self::assertCount(1, $mock);
        }
    }
}
