<?php

declare(strict_types=1);

use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Shared\ValueObject\EntityId;
use GuzzleHttp\{Client, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Infrastructure\Lms\RestMoodleGateway;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class MoodleSectionGatewayTest extends TestCase
{
    private function config(): Collection
    {
        $c = Collection::factory([]);
        $c->setConfigKey("moodle", [
            "enabled" => true,
            "section_sync_enabled" => true,
            "url" => "https://moodle.example.invalid",
            "token" => "fictional-private-token",
            "ca_file" => "",
        ]);
        return $c;
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
    private function member(): array
    {
        return [
            "section" => EntityId::generate()->value,
            "course" => "81",
            "person" => EntityId::generate()->value,
            "user" => "501",
            "version" => 3,
            "student" => true,
            "instructor" => false,
        ];
    }
    public function testBoundPolicyAndMembershipUsePrivateMinimalPosts(): void
    {
        $b = $this->binding();
        $p = $this->member();
        $history = [];
        $mock = new MockHandler([
            new Response(
                200,
                [],
                json_encode([
                    "siteurl" => "https://moodle.example.invalid",
                    "functions" => [["name" => "core_webservice_get_site_info"]],
                ]),
            ),
            new Response(
                200,
                [],
                json_encode([
                    "protocol" => 1,
                    "instance_hash" => $b->instanceFingerprint,
                    "policy_hash" => str_repeat("b", 64),
                ]),
            ),
            new Response(
                200,
                [],
                json_encode([
                    "protocol" => 1,
                    "instance_hash" => $b->instanceFingerprint,
                    "section" => $p["section"],
                    "version" => 3,
                    "status" => "synchronized",
                ]),
            ),
        ]);
        $handler = HandlerStack::create($mock);
        $handler->push(Middleware::history($history));
        $g = new RestMoodleGateway($this->config(), new Client(["handler" => $handler]));
        $policy = $g->rosterPolicy($b);
        self::assertSame("synchronized", $g->syncMember($b, $policy["policy_hash"], $p));
        self::assertCount(3, $history);
        parse_str((string) $history[2]["request"]->getBody(), $body);
        self::assertSame($p, json_decode($body["payload"], true));
        self::assertSame("local_etsis_sync_roster_member", $body["wsfunction"]);
        foreach ($history as $call) {
            self::assertSame("POST", $call["request"]->getMethod());
            self::assertSame("", $call["request"]->getUri()->getQuery());
            self::assertTrue($call["options"]["verify"]);
            self::assertFalse($call["options"]["allow_redirects"]);
            self::assertLessThanOrEqual(10, $call["options"]["timeout"]);
        }
        self::assertStringNotContainsString("firstname", $body["payload"]);
        self::assertStringNotContainsString("email", $body["payload"]);
    }
    public function testDisabledWritesMakeNoRequest(): void
    {
        $c = $this->config();
        $c->setConfigKey("moodle", ["section_sync_enabled" => false]);
        $mock = new MockHandler([]);
        $this->expectException(MoodleConnectionUnavailableException::class);
        new RestMoodleGateway($c, new Client(["handler" => $mock]))->syncMember(
            $this->binding(),
            str_repeat("b", 64),
            $this->member(),
        );
    }
    public function testMalformedOrCrossSectionAcknowledgementsAreRefusedWithoutUpstreamDetails(): void
    {
        $b = $this->binding();
        $p = $this->member();
        $base = [
            "protocol" => 1,
            "instance_hash" => $b->instanceFingerprint,
            "section" => $p["section"],
            "version" => 3,
            "status" => "synchronized",
        ];
        foreach (
            [
                ["protocol" => 2],
                ["instance_hash" => str_repeat("c", 64)],
                ["section" => EntityId::generate()->value],
                ["version" => 2],
                ["status" => "fictional-private-provider-message"],
                ["private" => "fictional-private-provider-message"],
            ]
            as $change
        ) {
            $mock = new MockHandler([new Response(200, [], json_encode(array_replace($base, $change)))]);
            try {
                new RestMoodleGateway($this->config(), new Client(["handler" => $mock]))->syncMember(
                    $b,
                    str_repeat("b", 64),
                    $p,
                );
                self::fail("Unexpected acknowledgement accepted.");
            } catch (MoodleConnectionUnavailableException $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString("fictional", $error->getMessage());
            }
        }
    }
    public function testCourseCreationUsesOriginalOperationAndAcceptsOnlyItsBoundReceipt(): void
    {
        $config = $this->config();
        $config->setConfigKey("moodle", ["course_creation_enabled" => true]);
        $b = $this->binding();
        $id = EntityId::generate();
        $course = [
            "shortname" => "etsis-" . EntityId::generate()->value,
            "fullname" => "Fictional Algebra",
            "startdate" => 1790000000,
            "enddate" => 1795000000,
        ];
        $history = [];
        $handler = HandlerStack::create(
            new MockHandler([
                new Response(
                    200,
                    [],
                    json_encode([
                        "siteurl" => "https://moodle.example.invalid",
                        "functions" => [["name" => "core_webservice_get_site_info"]],
                    ]),
                ),
                new Response(
                    200,
                    [],
                    json_encode([
                        "protocol" => 1,
                        "instance_hash" => $b->instanceFingerprint,
                        "operation" => $id->value,
                        "course_id" => "81",
                    ]),
                ),
            ]),
        );
        $handler->push(Middleware::history($history));
        self::assertSame(
            "81",
            new RestMoodleGateway($config, new Client(["handler" => $handler]))->provisionCourse(
                $b,
                $id,
                $course,
            )->value,
        );
        parse_str((string) $history[1]["request"]->getBody(), $body);
        self::assertSame("local_etsis_provision_course", $body["wsfunction"]);
        self::assertSame($id->value, $body["operation"]);
        self::assertSame($course, json_decode($body["payload"], true));
        self::assertSame("", $history[1]["request"]->getUri()->getQuery());
        self::assertTrue($history[1]["options"]["verify"]);
        self::assertFalse($history[1]["options"]["allow_redirects"]);
    }
    public function testDisablingCourseCreationDuringHandshakePreventsTheWrite(): void
    {
        $config = $this->config();
        $config->setConfigKey("moodle", ["course_creation_enabled" => true]);
        $calls = 0;
        $handler = function () use ($config, &$calls) {
            $calls++;
            $config->setConfigKey("moodle", ["course_creation_enabled" => false]);
            return \GuzzleHttp\Promise\Create::promiseFor(
                new Response(
                    200,
                    [],
                    json_encode([
                        "siteurl" => "https://moodle.example.invalid",
                        "functions" => [["name" => "core_webservice_get_site_info"]],
                    ]),
                ),
            );
        };
        try {
            new RestMoodleGateway($config, new Client(["handler" => $handler]))->provisionCourse(
                $this->binding(),
                EntityId::generate(),
                [],
            );
            self::fail("A disabled course write was attempted.");
        } catch (MoodleConnectionUnavailableException $error) {
            self::assertSame(1, $calls);
            self::assertNull($error->getPrevious());
        }
    }
}
