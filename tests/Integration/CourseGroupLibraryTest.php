<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
final class CourseGroupLibraryTest extends TestCase
{
    private ReusableGroupFixture $f;
    protected function setUp(): void
    {
        $this->f = new ReusableGroupFixture();
    }
    public function testCreationAndSaveRetriesAreIdempotentAndConflictingIdentitiesFail(): void
    {
        $f = $this->f;
        $id = EntityId::generate();
        $d = CourseGroupDefinition::blank("Shared %_ core");
        $row = $f->groups->create($id, $d);
        $audit = $f->digest("sis_audit");
        self::assertSame($row, $f->groups->create($id, $d));
        self::assertSame($audit, $f->digest("sis_audit"));
        $changed = $d->definition;
        $changed["name"] = "Changed";
        $changed = new CourseGroupDefinition($changed);
        $saved = $f->groups->save($id, 0, $changed);
        $audit = $f->digest("sis_audit");
        self::assertSame($saved, $f->groups->save($id, 0, $changed));
        self::assertSame($audit, $f->digest("sis_audit"));
        $this->expectException(ConcurrentModificationException::class);
        $f->groups->create($id, $changed);
    }
    public function testSearchIsLiteralAndPagedMetadataExcludesLargeDraftDefinitions(): void
    {
        $f = $this->f;
        for ($i = 0; $i < 51; ++$i) {
            $f->groups->create(EntityId::generate(), CourseGroupDefinition::blank("Shared %_ core " . $i));
        }
        $first = $f->groups->page("%_");
        self::assertCount(50, $first["items"]);
        self::assertNotNull($first["next_after"]);
        self::assertArrayNotHasKey("definition", $first["items"][0]);
        self::assertArrayNotHasKey("creation_hash", $first["items"][0]);
        $next = $f->groups->page("%_", new EntityId($first["next_after"]));
        self::assertCount(1, $next["items"]);
        self::assertNull($next["next_after"]);
        self::assertSame(
            [],
            array_intersect(array_column($first["items"], "id"), array_column($next["items"], "id")),
        );
        self::assertSame([], $f->groups->page("does not exist")["items"]);
    }
    public function testPublishedSearchUsesRetainedPublishedNameAndHidesUnpublishedDrafts(): void
    {
        $f = $this->f;
        $d = $f->groupDefinition;
        $d["name"] = "Pending new name";
        $f->groups->save($f->group, 0, new CourseGroupDefinition($d));
        $f->groups->create(EntityId::generate(), CourseGroupDefinition::blank("General education draft"));
        self::assertCount(1, $f->groups->page("General education", null, true)["items"]);
        self::assertSame([], $f->groups->page("Pending", null, true)["items"]);
        self::assertSame(
            "General education",
            $f->groups->version($f->group, $f->groupVersion)["definition"]["name"],
        );
    }
    public function testPublicationRetriesRetainOriginalReceiptAfterNewerVersionsAndProtectedPayloads(): void
    {
        $f = $this->f;
        $before = $f->digest("sis_history_events");
        $review = $f->groups->review($f->group, "Fictional protected approval");
        self::assertSame($before, $f->digest("sis_history_events"));
        $receipt = $f->groups->publish($f->group, $review["review_token"]);
        $d = $f->groupDefinition;
        $d["name"] = "New name";
        $f->groups->save($f->group, 0, new CourseGroupDefinition($d));
        $f->publishGroup();
        $audit = $f->digest("sis_audit");
        self::assertSame($receipt, $f->groups->publish($f->group, $review["review_token"]));
        self::assertSame($audit, $f->digest("sis_audit"));
        $raw = json_encode($f->sis->sql->rows("sis_history_events"), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("Fictional protected approval", $raw);
        self::assertStringNotContainsString("General education", $raw);
        self::assertSame(
            "General education",
            $f->groups->version($f->group, $f->groupVersion)["definition"]["name"],
        );
    }
    public function testStaleAndCompetingReviewsAndStaleSavesCannotOverwriteNewerWork(): void
    {
        $f = $this->f;
        $r = $f->groups->review($f->group, "Fictional review");
        $before = $f->digest("sis_history_events");
        $d = $f->groupDefinition;
        $d["name"] = "Updated";
        $f->groups->save($f->group, 0, new CourseGroupDefinition($d));
        try {
            $f->groups->publish($f->group, $r["review_token"]);
            self::fail("Stale review accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->digest("sis_history_events"));
        }
        try {
            $f->groups->save($f->group, 0, new CourseGroupDefinition($f->groupDefinition));
            self::fail("Stale save accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame("Updated", $f->groups->view($f->group)["definition"]["name"]);
        }
        $a = $f->groups->review($f->group, "First");
        $b = $f->groups->review($f->group, "Second");
        $f->groups->publish($f->group, $a["review_token"]);
        $this->expectException(ConcurrentModificationException::class);
        $f->groups->publish($f->group, $b["review_token"]);
    }
    public function testLateAuditFailureRollsBackEventReceiptHeadAndProjectionTogether(): void
    {
        $f = $this->f;
        $review = $f->groups->review($f->group, "Fictional rollback");
        $tables = [
            "sis_history_events",
            "sis_history_operations",
            "sis_history_streams",
            "sis_course_groups",
            "sis_audit",
        ];
        $hashes = [];
        foreach ($tables as $t) {
            $hashes[$t] = $f->digest($t);
        }
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure"));
        try {
            $f->groupService($audit)->publish($f->group, $review["review_token"]);
            self::fail("Audit failure ignored.");
        } catch (RuntimeException) {
            foreach ($tables as $t) {
                self::assertSame($hashes[$t], $f->digest($t), $t);
            }
        }
        self::assertSame(2, $f->groups->publish($f->group, $review["review_token"])["last_version"]);
    }
    public function testForeignCoursePublicationAndForeignGroupVersionAreRejected(): void
    {
        $f = $this->f;
        $d = $f->groupDefinition;
        $d["courses"][0]["version_id"] = $d["courses"][1]["version_id"];
        try {
            $f->groups->save($f->group, 0, new CourseGroupDefinition($d));
            self::fail("Foreign course publication accepted.");
        } catch (\Domain\Shared\Exception\RecordNotFoundException) {
            self::assertSame(0, $f->groups->view($f->group)["revision"]);
        }
        $other = EntityId::generate();
        $f->groups->create($other, new CourseGroupDefinition($f->groupDefinition));
        $this->expectException(\Domain\Shared\Exception\RecordNotFoundException::class);
        $f->groups->version($other, $f->groupVersion);
    }
    public function testWrongActorTamperedTokenAndRevokedPermissionFailClosed(): void
    {
        $f = $this->f;
        $review = $f->groups->review($f->group, "Protected review");
        try {
            $f->groups->publish($f->group, $review["review_token"] . "bad");
            self::fail("Tampered token accepted.");
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $f->sis->access->actor = $f->sis->user();
        try {
            $f->groups->publish($f->group, $review["review_token"]);
            self::fail("Wrong actor accepted.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->sis->access->staff = false;
        $cases = [
            fn() => $f->groups->view($f->group),
            fn() => $f->groups->page(),
            fn() => $f->groups->save($f->group, 0, new CourseGroupDefinition($f->groupDefinition)),
            fn() => $f->groups->publish($f->group, $review["review_token"]),
        ];
        foreach ($cases as $call) {
            try {
                $call();
                self::fail("Unauthorized group access.");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }
    public function testStableBoundedGroupHistoryAndScopedCursor(): void
    {
        $f = $this->f;
        for ($i = 0; $i < 10; ++$i) {
            $f->publishGroup();
        }
        $page = $f->groups->versions($f->group);
        self::assertCount(10, $page["items"]);
        $f->publishGroup();
        $next = $f->groups->versions($f->group, $page["next_cursor"]);
        self::assertCount(1, $next["items"]);
        self::assertSame(11, $next["items"][0]["version"]);
        $other = EntityId::generate();
        $f->groups->create($other, CourseGroupDefinition::blank("Other"));
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $f->groups->versions($other, $page["next_cursor"]);
    }
    public function testGroupContractRejectsOwnershipDuplicateAndExecutableOrMalformedRules(): void
    {
        $f = $this->f;
        $base = $f->groupDefinition;
        $invalid = [];
        foreach (["program_id", "academic_year_id", "sql", "callback"] as $key) {
            $d = $base;
            $d[$key] = "anything";
            $invalid[] = $d;
        }
        $d = $base;
        $d["courses"][] = $d["courses"][0];
        $invalid[] = $d;
        $d = $base;
        $d["rules"][0]["course_ids"][] = $d["rules"][0]["course_ids"][0];
        $invalid[] = $d;
        $d = $base;
        $d["rules"][0]["course_ids"] = [EntityId::generate()->value];
        $invalid[] = $d;
        $d = $base;
        $d["rules"][0]["mode"] = "execute";
        $invalid[] = $d;
        $d = $base;
        $d["rules"][0]["minimum_courses"] = 1.0;
        $invalid[] = $d;
        $d = $base;
        $d["rules"][1]["id"] = $d["rules"][0]["id"];
        $invalid[] = $d;
        $d = $base;
        $d["min_gpa_milli"] = 10001;
        $invalid[] = $d;
        foreach ($invalid as $d) {
            try {
                new CourseGroupDefinition($d);
                self::fail("Malformed group accepted.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        CourseGroupDefinition::blank("Empty")->assertPublishable();
    }

    public function testCodefyReplayEnforcesGroupPredecessorContinuity(): void
    {
        $f = $this->f;
        $second = $f->publishGroup();
        $stream = new \Domain\Shared\EventSourcing\StreamReference(
            new \Domain\DegreeAudit\ValueObject\CourseGroupPublicationId($f->group->value),
            "course_group_publication",
        );
        $events = array_map(static fn($stored) => $stored->event, $f->history->page($stream)->events);
        $state = \Domain\DegreeAudit\CourseGroupPublication::reconstituteFromEventStream(
            new \Codefy\Domain\EventSourcing\EventStream($stream->aggregateId, $events),
        );
        self::assertSame(2, $state->playhead());
        self::assertSame($second->value, $state->versionId());
        $bad = \Domain\DegreeAudit\Event\CourseGroupWasPublished::occur(
            $stream->aggregateId,
            [
                "version_id" => EntityId::generate()->value,
                "previous_version_id" => null,
                "definition" => $f->groupDefinition,
            ],
            ["__aggregate_type" => "course_group_publication"],
        )->withPlayhead(3);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $state->acceptHistorical($bad);
    }
    public function testReviewRefusesAMismatchedPublicationProjection(): void
    {
        $f = $this->f;
        $f->sis->sql->update("sis_course_groups", ["publication_position" => 0], ["id" => $f->group->value]);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $f->groups->review($f->group, "Fictional inconsistent projection");
    }
}
