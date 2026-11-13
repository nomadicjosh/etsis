<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";

use Domain\Catalog\Event\CourseDefinitionWasPublished;
use Domain\Catalog\ValueObject\CoursePublicationId;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublishedRequirementsReaderTest extends TestCase
{
    private ReusableGroupFixture $f;
    private array $calls = [];

    protected function setUp(): void
    {
        $this->f = new ReusableGroupFixture();
    }

    private function reader(): PublishedRequirementsReader
    {
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::never())->method("append");
        $history->expects(self::never())->method("latest");
        $history->method("page")->willReturnCallback(function ($stream, $cursor, $limit) {
            $this->calls[] = [
                "type" => $stream->type,
                "id" => (string) $stream->aggregateId,
                "cursor" => $cursor,
                "limit" => $limit,
            ];
            return $this->f->history->page($stream, $cursor, $limit);
        });
        return new PublishedRequirementsReader($history);
    }

    private function group(array $definition): array
    {
        $f = $this->f;
        $id = EntityId::generate();
        $f->groups->create($id, new CourseGroupDefinition($definition));
        $review = $f->groups->review($id, "Fictional shared-group publication");
        $receipt = $f->groups->publish($id, $review["review_token"]);
        return ["group_id" => $id->value, "version_id" => $receipt["operation_id"]];
    }

    private function degree(): EntityId
    {
        $this->f->saveDegree();
        return new EntityId($this->f->publishDegree()["operation_id"]);
    }

    private function courseCalls(): array
    {
        return array_values(
            array_filter(
                $this->calls,
                static fn(array $call): bool => $call["type"] === "course_publication",
            ),
        );
    }

    private function courseHistory(int $count): EntityId
    {
        $f = $this->f;
        $course = new EntityId($f->groupDefinition["courses"][0]["course_id"]);
        $version = new EntityId($f->groupDefinition["courses"][0]["version_id"]);
        for ($i = 2; $i <= $count; ++$i) {
            $f->sis->sql->update(
                "sis_courses",
                ["title" => "Fictional revised mathematics " . $i, "credits_milli" => 4000],
                ["id" => $course->value],
            );
            $review = $f->catalog->publications()->review($course, "Fictional course revision");
            $version = new EntityId(
                $f->catalog->publications()->publish($course, $review["review_token"])["operation_id"],
            );
        }
        return $version;
    }

    public function testTenGroupsSharingFiveCoursesReplayEachCourseStreamOnlyOnce(): void
    {
        $f = $this->f;
        for ($i = 1; $i < 10; ++$i) {
            $definition = $f->groupDefinition;
            $definition["name"] = "Fictional shared block " . $i;
            $f->definition["groups"][] = $this->group($definition);
        }
        $version = $this->degree();
        $before = $f->digest("sis_history_events");
        $result = $this->reader()->version($f->degree, $version);
        self::assertCount(10, $result["group_details"]);
        self::assertCount(5, $this->courseCalls());
        self::assertCount(5, array_unique(array_column($this->courseCalls(), "id")));
        self::assertSame($before, $f->digest("sis_history_events"));
        foreach ($result["group_details"] as $group) {
            self::assertCount(5, $group["course_details"]);
            self::assertSame(["definition", "version", "version_id", "course_details"], array_keys($group));
        }
        self::assertSame([10], array_values(array_unique(array_column($this->calls, "limit"))));
    }

    public function testDifferentVersionsOfOneSharedCourseRemainDistinctAcrossGroupsAndPages(): void
    {
        $f = $this->f;
        $latest = $this->courseHistory(12);
        $definition = $f->groupDefinition;
        $definition["name"] = "Fictional later mathematics block";
        $definition["courses"][0]["version_id"] = $latest->value;
        $newGroup = $this->group($definition);
        $f->definition["groups"][] = $newGroup;
        $version = $this->degree();
        $course = $definition["courses"][0]["course_id"];
        $result = $this->reader()->version($f->degree, $version);
        $earlier = $result["group_details"][$f->group->value]["course_details"][$course];
        $later = $result["group_details"][$newGroup["group_id"]]["course_details"][$course];
        self::assertSame(
            ["code" => "MATH.101", "title" => "Fictional MATH.101", "credits_milli" => 3000, "version" => 1],
            $earlier,
        );
        self::assertSame(
            ["code" => "MATH.101", "title" => "Fictional revised mathematics 12", "credits_milli" => 4000, "version" => 12],
            $later,
        );
        $calls = array_values(
            array_filter($this->courseCalls(), static fn(array $call): bool => $call["id"] === $course),
        );
        self::assertCount(2, $calls);
        self::assertNull($calls[0]["cursor"]);
        self::assertNotNull($calls[1]["cursor"]);
        self::assertCount(6, $this->courseCalls());
    }

    private function corruptTail(bool $duplicateVersion = false): void
    {
        $f = $this->f;
        $course = new EntityId($f->groupDefinition["courses"][0]["course_id"]);
        $stream = new StreamReference(new CoursePublicationId($course->value), "course_publication");
        $last = $f->history->latest($stream);
        $operation = EntityId::generate();
        $payload = $last->event->payload();
        $payload["previous_version_id"] = $duplicateVersion
            ? $payload["version_id"]
            : EntityId::generate()->value;
        $payload["version_id"] = $duplicateVersion
            ? $f->groupDefinition["courses"][0]["version_id"]
            : $operation->value;
        // Simulate a buggy historical writer: authenticated storage, invalid domain continuity.
        $event = CourseDefinitionWasPublished::occur($stream->aggregateId, $payload, [
            "__aggregate_type" => "course_publication",
            "schema_version" => 1,
        ])->withPlayhead($last->event->playhead() + 1);
        $f->history->append(
            $stream,
            $operation,
            $last->event->playhead(),
            new EventContext(
                $f->sis->access->actor,
                $f->sis->clock->now(),
                $operation,
                reason: "Fictional invalid predecessor",
            ),
            [$event],
        );
    }

    public static function invalidLaterPublications(): iterable
    {
        yield "invalid predecessor" => [false];
        yield "repeated selected identity" => [true];
    }

    #[DataProvider("invalidLaterPublications")]
    public function testInvalidLaterVersionsAreVerifiedBeyondTheFirstPage(bool $duplicateVersion): void
    {
        $f = $this->f;
        $this->courseHistory(12);
        $version = $this->degree();
        $this->corruptTail($duplicateVersion);
        $reader = $this->reader();
        $this->expectException(CorruptHistoryException::class);
        try {
            $reader->version($f->degree, $version);
        } finally {
            $course = $f->groupDefinition["courses"][0]["course_id"];
            $calls = array_filter(
                $this->courseCalls(),
                static fn(array $call): bool => $call["id"] === $course,
            );
            self::assertCount(2, $calls);
        }
    }

    public function testOneReaderInstanceNeverReusesVerifiedResultsAcrossSeparateInvocations(): void
    {
        $f = $this->f;
        $version = $this->degree();
        $reader = $this->reader();
        $first = $reader->version($f->degree, $version);
        self::assertCount(5, $this->courseCalls());
        self::assertSame($first, $reader->version($f->degree, $version));
        self::assertCount(10, $this->courseCalls());
        $this->corruptTail();
        $this->expectException(CorruptHistoryException::class);
        $reader->version($f->degree, $version);
    }

    public function testMissingAndForeignCourseVersionsAreNotSatisfiedByAnotherStream(): void
    {
        $f = $this->f;
        $course = new EntityId($f->groupDefinition["courses"][0]["course_id"]);
        $reader = $this->reader();
        $versions = [EntityId::generate(), new EntityId($f->groupDefinition["courses"][1]["version_id"])];
        foreach ($versions as $version) {
            try {
                $reader->courseVersion($course, $version);
                self::fail("A missing course publication was resolved from another stream.");
            } catch (RecordNotFoundException) {
                self::assertTrue(true);
            }
        }
        self::assertCount(2, $this->courseCalls());
    }
}
