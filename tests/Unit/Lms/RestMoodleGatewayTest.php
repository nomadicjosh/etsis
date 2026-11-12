<?php

declare(strict_types=1);

use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\ValueObject\MoodleCapabilities;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Infrastructure\Lms\RestMoodleGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class RestMoodleGatewayTest extends TestCase
{
    private function config(array $changes = []): Collection
    {
        $config = Collection::factory([]);
        $config->setConfigKey(
            "moodle",
            array_replace(
                [
                    "enabled" => true,
                    "url" => "https://moodle.example.invalid/campus",
                    "token" => "fictional-private-token",
                    "ca_file" => "",
                ],
                $changes,
            ),
        );
        return $config;
    }
    private function response(string $siteUrl = "https://moodle.example.invalid/campus"): Response
    {
        return new Response(
            200,
            [],
            json_encode([
                "username" => "fictional-private-username",
                "fullname" => "Fictional Private Name",
                "userid" => 999,
                "siteurl" => $siteUrl,
                "functions" => [
                    ["name" => "core_webservice_get_site_info", "version" => "20260101"],
                    ["name" => "core_course_create_courses"],
                    ["name" => "unrelated_private_function"],
                ],
            ]),
        );
    }
    public function testOnlySafeCapabilityFlagsAreReturnedAndTokenNeverAppearsInUrl(): void
    {
        $mock = new MockHandler([$this->response()]);
        $probe = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]));
        $result = $probe->check();
        self::assertSame(MoodleCapabilities::FUNCTIONS, array_keys($result->describe()));
        self::assertTrue($result->describe()["core_course_create_courses"]);
        self::assertFalse($result->describe()["enrol_manual_enrol_users"]);
        $request = $mock->getLastRequest();
        self::assertSame("POST", $request->getMethod());
        self::assertSame(
            "https://moodle.example.invalid/campus/webservice/rest/server.php",
            (string) $request->getUri(),
        );
        parse_str((string) $request->getBody(), $body);
        self::assertSame(
            [
                "wstoken" => "fictional-private-token",
                "wsfunction" => "core_webservice_get_site_info",
                "moodlewsrestformat" => "json",
            ],
            $body,
        );
        $options = $mock->getLastOptions();
        foreach (["allow_redirects", "http_errors", "debug", "cookies"] as $name) {
            self::assertFalse($options[$name]);
        }
        self::assertTrue($options["verify"]);
        self::assertSame(15, $options["timeout"]);
        self::assertSame(3, $options["connect_timeout"]);
        self::assertSame(5, $options["read_timeout"]);
        foreach (
            ["fictional-private-token", "Fictional Private Name", "unrelated_private_function", "999"]
            as $private
        ) {
            self::assertStringNotContainsString($private, json_encode($result));
            self::assertStringNotContainsString($private, print_r($probe, true));
        }
    }
    public function testReportedSiteMustMatchConfiguredInstallationBeforeDiscoveryIsReturned(): void
    {
        $valid = new MockHandler([$this->response("https://MOODLE.EXAMPLE.INVALID:443/campus/")]);
        $probe = new RestMoodleGateway($this->config(), new Client(["handler" => $valid]));
        self::assertTrue($probe->check()->describe()["core_webservice_get_site_info"]);
        foreach (
            [
                "https://other.example.invalid/campus",
                "https://moodle.example.invalid/other",
                "https://moodle.example.invalid:8443/campus",
                "http://moodle.example.invalid/campus",
            ]
            as $site
        ) {
            $mock = new MockHandler([$this->response($site)]);
            $probe = new RestMoodleGateway($this->config(), new Client(["handler" => $mock]));
            try {
                $probe->check();
                self::fail("Mismatched installation accepted.");
            } catch (MoodleConnectionUnavailableException $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString($site, $error->getMessage());
            }
        }
    }
    public static function unsafeConfiguration(): iterable
    {
        yield [["enabled" => false]];
        yield [["enabled" => "true"]];
        yield [["url" => "http://moodle.example.invalid"]];
        yield [["url" => "https://secret@moodle.example.invalid"]];
        yield [["url" => "https://moodle.example.invalid?token=secret"]];
        yield [["url" => "https://moodle.example.invalid#fragment"]];
        yield [["token" => ""]];
        yield [["token" => "secret\r\ninjected"]];
        yield [["ca_file" => "/nonexistent/fictional-moodle-ca.pem"]];
    }
    #[DataProvider("unsafeConfiguration")]
    public function testDisabledOrUnsafeConfigurationCannotCallUpstream(array $changes): void
    {
        $mock = new MockHandler([]);
        $probe = new RestMoodleGateway($this->config($changes), new Client(["handler" => $mock]));
        try {
            $probe->check();
            self::fail();
        } catch (MoodleConnectionUnavailableException $error) {
            self::assertNull($mock->getLastRequest());
            self::assertNull($error->getPrevious());
        }
    }
    public static function invalidResponses(): iterable
    {
        yield [
            new Response(
                200,
                [],
                json_encode(["functions" => [["name" => "core_webservice_get_site_info"]]]),
            ),
        ];
        yield [
            new Response(
                200,
                [],
                json_encode(["siteurl" => 123, "functions" => [["name" => "core_webservice_get_site_info"]]]),
            ),
        ];
        yield [new Response(302, ["Location" => "https://other.example.invalid"])];
        yield [new Response(401, [], "fictional-private-token")];
        yield [new Response(200, [], "not json")];
        yield [
            new Response(
                200,
                [],
                json_encode([
                    "exception" => "private",
                    "errorcode" => "invalidtoken",
                    "message" => "private upstream details",
                ]),
            ),
        ];
        yield [new Response(200, [], str_repeat("x", 524289))];
        yield [
            new Response(
                200,
                [],
                json_encode(["siteurl" => "https://moodle.example.invalid/campus", "functions" => []]),
            ),
        ];
        yield [
            new Response(
                200,
                [],
                json_encode([
                    "siteurl" => "https://moodle.example.invalid/campus",
                    "functions" => [["name" => "<script>private</script>"]],
                ]),
            ),
        ];
        yield [
            new Response(
                200,
                [],
                json_encode([
                    "functions" => array_fill(0, 2001, ["name" => "core_webservice_get_site_info"]),
                ]),
            ),
        ];
        yield [new RuntimeException("fictional-private-token private upstream response")];
    }
    #[DataProvider("invalidResponses")]
    public function testFailuresAreSanitizedAndResponseStreamsAreClosed(Response|Throwable $response): void
    {
        $stream = $response instanceof Response ? $response->getBody() : null;
        $probe = new RestMoodleGateway(
            $this->config(),
            new Client(["handler" => new MockHandler([$response])]),
        );
        try {
            $probe->check();
            self::fail();
        } catch (MoodleConnectionUnavailableException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString("private", $error->getMessage());
            if ($stream !== null) {
                self::assertFalse($stream->isReadable());
            }
        }
    }
}
