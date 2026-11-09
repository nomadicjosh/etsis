<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";
use Domain\DegreeAudit\ValueObject\AuditRequirements;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV2;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
final class DegreeAuditRequirementsTest extends TestCase
{
    private ReusableGroupFixture $f;
    protected function setUp(): void
    {
        $this->f = new ReusableGroupFixture();
    }
    public function testOnePublishedGroupIsReusedAcrossProgramsWithoutOwnershipOrCopying(): void
    {
        $f = $this->f;
        $f->saveDegree();
        $f->publishDegree();
        $program = EntityId::generate();
        $f->sis->sql->insert("sis_programs", [
            "id" => $program->value,
            "code" => "BA",
            "title" => "Humanities",
        ]);
        $second = $f->degrees->open($program, $f->year);
        $id = new EntityId($second["id"]);
        $f->degrees->save($id, 0, new AuditRequirementsV3($f->definition));
        $r = $f->degrees->review($id, "Fictional shared general education");
        $f->degrees->publish($id, $r["review_token"]);
        self::assertCount(1, $f->sis->sql->rows("sis_course_groups"));
        self::assertSame($f->definition["groups"], $f->degrees->view($id)["definition"]["groups"]);
        $group = $f->groups->view($f->group);
        foreach (["program_id", "academic_year_id", "degree_id"] as $key) {
            self::assertArrayNotHasKey($key, $group);
        }
        self::assertSame([], $f->sis->sql->rows("sis_student_catalogs"));
        self::assertSame([], $f->sis->sql->rows("sis_academic_credits"));
    }
    public function testPublishedDegreeRetainsTheSelectedGroupEvenAfterGroupAndCatalogChanges(): void
    {
        $f = $this->f;
        $f->saveDegree();
        $v = $f->publishDegree()["operation_id"];
        $old = $f->degrees->version($f->degree, new EntityId($v));
        $updated = $f->groupDefinition;
        $updated["name"] = "Revised general education";
        $updated["min_credits_milli"] = 15000;
        $f->groups->save($f->group, 0, new CourseGroupDefinition($updated));
        $next = $f->publishGroup();
        $course = $updated["courses"][0]["course_id"];
        $f->sis->sql->update("sis_courses", ["code" => "MATH.CHANGED"], ["id" => $course]);
        $still = $f->degrees->version($f->degree, new EntityId($v));
        self::assertSame($old, $still);
        self::assertSame(
            "MATH.101",
            $still["group_details"][$f->group->value]["course_details"][$course]["code"],
        );
        $new = $f->definition;
        $new["groups"][0]["version_id"] = $next->value;
        $f->degrees->save($f->degree, 1, new AuditRequirementsV3($new));
        $f->publishDegree();
        self::assertSame(
            "Revised general education",
            $f->degrees->view($f->degree)["group_details"][$f->group->value]["definition"]["name"],
        );
    }
    public function testCloningDegreeReferencesTheSameExactGroupVersionsAcrossAcademicYears(): void
    {
        $f = $this->f;
        $f->saveDegree();
        $published = $f->publishDegree();
        $calendar = new AcademicCalendarFixture($f->sis);
        $year = new EntityId($calendar->year(2028)["id"]);
        $clone = $f->degrees->cloneTo(
            $f->degree,
            new EntityId($published["operation_id"]),
            $f->sis->program,
            $year,
        );
        self::assertSame($f->definition["groups"], $clone["definition"]["groups"]);
        self::assertNull($clone["published_version_id"]);
        self::assertSame($f->definition["policies"], $clone["definition"]["policies"]);
        self::assertCount(1, $f->sis->sql->rows("sis_course_groups"));
        $this->expectException(ConcurrentModificationException::class);
        $f->degrees->cloneTo($f->degree, new EntityId($published["operation_id"]), $f->sis->program, $year);
    }
    public function testReviewAndExactRetriesPreserveHistoryAndAuditAfterLaterEdits(): void
    {
        $f = $this->f;
        $saved = $f->saveDegree();
        $before = $f->digest("sis_audit");
        self::assertSame($saved, $f->saveDegree());
        self::assertSame($before, $f->digest("sis_audit"));
        $before = $f->digest("sis_history_events");
        $r = $f->degrees->review($f->degree, "Fictional approval");
        self::assertSame($before, $f->digest("sis_history_events"));
        $receipt = $f->degrees->publish($f->degree, $r["review_token"]);
        $d = $f->definition;
        $d["name"] = "Changed title";
        $f->degrees->save($f->degree, 1, new AuditRequirementsV3($d));
        $f->publishDegree();
        $audit = $f->digest("sis_audit");
        self::assertSame($receipt, $f->degrees->publish($f->degree, $r["review_token"]));
        self::assertSame($audit, $f->digest("sis_audit"));
        $stream = new \Domain\Shared\EventSourcing\StreamReference(
            new \Domain\DegreeAudit\ValueObject\RequirementsPublicationId($f->degree->value),
            "degree_audit_requirements",
        );
        $events = array_map(static fn($e) => $e->event, $f->history->page($stream)->events);
        $root = \Domain\DegreeAudit\RequirementsPublication::reconstituteFromEventStream(
            new \Codefy\Domain\EventSourcing\EventStream($stream->aggregateId, $events),
        );
        self::assertSame(2, $root->playhead());
    }
    public function testStaleAndCompetingReviewsCannotPublishAnotherDefinition(): void
    {
        $f = $this->f;
        $f->saveDegree();
        $r = $f->degrees->review($f->degree, "Fictional stale approval");
        $before = $f->digest("sis_history_events");
        $d = $f->definition;
        $d["name"] = "Edited";
        $f->degrees->save($f->degree, 1, new AuditRequirementsV3($d));
        try {
            $f->degrees->publish($f->degree, $r["review_token"]);
            self::fail("Stale publication accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->digest("sis_history_events"));
        }
        $a = $f->degrees->review($f->degree, "First");
        $b = $f->degrees->review($f->degree, "Second");
        $f->degrees->publish($f->degree, $a["review_token"]);
        $this->expectException(ConcurrentModificationException::class);
        $f->degrees->publish($f->degree, $b["review_token"]);
    }
    public function testDraftReferencesMayBeUnpublishedButDegreePublicationRequiresPublishedGroups(): void
    {
        $f = $this->f;
        $d = $f->definition;
        $d["groups"][0]["version_id"] = null;
        $f->degrees->save($f->degree, 0, new AuditRequirementsV3($d));
        self::assertArrayHasKey($f->group->value, $f->degrees->view($f->degree)["group_details"]);
        $this->expectException(InvalidArgumentException::class);
        $f->degrees->review($f->degree, "No selected version");
    }
    public function testForeignVersionMissingGroupAndDuplicateBlocksAreRejected(): void
    {
        $f = $this->f;
        $d = $f->definition;
        $d["groups"][] = $d["groups"][0];
        try {
            new AuditRequirementsV3($d);
            self::fail("Duplicate group accepted.");
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $d = $f->definition;
        $d["groups"][0]["version_id"] = $f->groupDefinition["courses"][0]["version_id"];
        try {
            $f->degrees->save($f->degree, 0, new AuditRequirementsV3($d));
            self::fail("Foreign version accepted.");
        } catch (\Domain\Shared\Exception\RecordNotFoundException) {
            self::assertSame(0, $f->degrees->view($f->degree)["revision"]);
        }
        $d = $f->definition;
        $d["groups"][0]["group_id"] = EntityId::generate()->value;
        $this->expectException(\Domain\Shared\Exception\RecordNotFoundException::class);
        $f->degrees->save($f->degree, 0, new AuditRequirementsV3($d));
    }
    public function testExplicitLegacyDraftConversionIsAtomicRepeatableAndDoesNotPublishNewClaims(): void
    {
        $f = $this->f;
        $legacy = $f->oldDefinition();
        $f->sis->sql->update(
            "sis_degree_audit_templates",
            ["draft_json" => json_encode($legacy, JSON_THROW_ON_ERROR)],
            ["id" => $f->degree->value],
        );
        $before = $f->digest("sis_history_events");
        $converted = $f->degrees->reuseLegacy($f->degree, 0);
        self::assertSame($before, $f->digest("sis_history_events"));
        self::assertCount(2, $f->sis->sql->rows("sis_course_groups"));
        self::assertArrayNotHasKey("courses", $converted["definition"]);
        self::assertNull($converted["definition"]["groups"][0]["version_id"]);
        self::assertSame($converted, $f->degrees->reuseLegacy($f->degree, 0));
        self::assertCount(2, $f->sis->sql->rows("sis_course_groups"));
        $this->expectException(InvalidArgumentException::class);
        $f->degrees->review($f->degree, "Unpublished converted groups");
    }
    public function testConversionAndPublicationAuditFailuresRollBackTheirEntireTransactions(): void
    {
        $f = $this->f;
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willReturnCallback(static function ($actor, $action, $subject): void {
            if (
                in_array(
                    $action,
                    ["degree_audit.groups_moved_to_library", "degree_audit.requirements_published"],
                    true,
                )
            ) {
                throw new RuntimeException("Fictional failure");
            }
        });
        $legacy = $f->oldDefinition();
        $f->sis->sql->update(
            "sis_degree_audit_templates",
            ["draft_json" => json_encode($legacy, JSON_THROW_ON_ERROR)],
            ["id" => $f->degree->value],
        );
        $before = $f->digest("sis_course_groups");
        try {
            $f->degreeService($audit)->reuseLegacy($f->degree, 0);
            self::fail("Conversion failure ignored.");
        } catch (RuntimeException) {
            self::assertSame($before, $f->digest("sis_course_groups"));
        }
        $f->sis->sql->update(
            "sis_degree_audit_templates",
            ["draft_json" => json_encode($f->definition, JSON_THROW_ON_ERROR)],
            ["id" => $f->degree->value],
        );
        $r = $f->degrees->review($f->degree, "Approval");
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_degree_audit_templates",
            "sis_audit",
        ];
        $hashes = [];
        foreach ($tables as $t) {
            $hashes[$t] = $f->digest($t);
        }
        try {
            $f->degreeService($audit)->publish($f->degree, $r["review_token"]);
            self::fail("Audit failure ignored.");
        } catch (RuntimeException) {
            foreach ($tables as $t) {
                self::assertSame($hashes[$t], $f->digest($t), $t);
            }
        }
        self::assertSame(1, $f->degrees->publish($f->degree, $r["review_token"])["last_version"]);
    }
    public function testTamperedCrossTemplateWrongActorAndRevokedAccessCannotMutateRequirements(): void
    {
        $f = $this->f;
        $f->saveDegree();
        $r = $f->degrees->review($f->degree, "Approval");
        $cases = [[$f->degree, $r["review_token"] . "bad"], [EntityId::generate(), $r["review_token"]]];
        foreach ($cases as [$id, $token]) {
            try {
                $f->degrees->publish($id, $token);
                self::fail("Invalid token accepted.");
            } catch (InvalidArgumentException) {
                self::assertSame([], $f->degrees->versions($f->degree)["items"]);
            }
        }
        $f->sis->access->actor = $f->sis->user();
        try {
            $f->degrees->publish($f->degree, $r["review_token"]);
            self::fail("Foreign actor accepted.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->sis->access->staff = false;
        $cases = [
            fn() => $f->degrees->view($f->degree),
            fn() => $f->degrees->publish($f->degree, $r["review_token"]),
            fn() => $f->degrees->reuseLegacy($f->degree, 0),
        ];
        foreach ($cases as $call) {
            try {
                $call();
                self::fail("Missing permission accepted.");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }
    public function testStableHistoryPagesAreBoundedAndCrossTemplateCursorsFailClosed(): void
    {
        $f = $this->f;
        $f->saveDegree();
        for ($i = 0; $i < 11; ++$i) {
            $f->publishDegree();
        }
        $page = $f->degrees->versions($f->degree);
        self::assertCount(10, $page["items"]);
        $f->publishDegree();
        $next = $f->degrees->versions($f->degree, $page["next_cursor"]);
        self::assertCount(1, $next["items"]);
        self::assertSame(11, $next["items"][0]["version"]);
        $a = new AcademicCalendarFixture($f->sis);
        $year = new EntityId($a->year(2028)["id"]);
        $other = $f->degrees->open($f->sis->program, $year);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $f->degrees->versions(new EntityId($other["id"]), $page["next_cursor"]);
    }
    public function testPoliciesAndFrozenInlineGrammarsRemainStrict(): void
    {
        $f = $this->f;
        $old = $f->oldDefinition();
        new AuditRequirementsV2($old);
        unset($old["policies"]);
        new AuditRequirements($old);
        self::assertTrue(true);
        $bad = $f->definition;
        $bad["courses"] = [];
        try {
            new AuditRequirementsV3($bad);
            self::fail("Inline courses accepted.");
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $bad = $f->definition;
        $bad["policies"]["repeat_gpa"] = "SQL";
        try {
            new AuditRequirementsV3($bad);
            self::fail("Executable policy accepted.");
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $bad = $f->definition;
        $bad["policies"]["repeat_gpa"] = null;
        $f->degrees->save($f->degree, 0, new AuditRequirementsV3($bad));
        $this->expectException(InvalidArgumentException::class);
        $f->degrees->review($f->degree, "Incomplete policies");
    }

    public function testReplacingACoursePublicationInAGroupPreservesEarlierGroupAndDegreeHistory(): void
    {
        $f = $this->f;
        $f->saveDegree();
        $degreeVersion = new EntityId($f->publishDegree()["operation_id"]);
        $before = $f->degrees->version($f->degree, $degreeVersion);
        $d = $f->groupDefinition;
        $course = new EntityId($d["courses"][0]["course_id"]);
        $f->sis->sql->update(
            "sis_courses",
            ["title" => "Advanced Algebra", "credits_milli" => 4000],
            ["id" => $course->value],
        );
        $review = $f->catalog->publications()->review($course, "Fictional new course version");
        $publication = $f->catalog->publications()->publish($course, $review["review_token"]);
        $d["courses"][0]["version_id"] = $publication["operation_id"];
        $f->groups->save($f->group, 0, new CourseGroupDefinition($d));
        $next = $f->publishGroup();
        self::assertSame(
            4000,
            $f->groups->version($f->group, $next)["course_details"][$course->value]["credits_milli"],
        );
        self::assertSame(
            3000,
            $f->groups->version($f->group, $f->groupVersion)["course_details"][$course->value][
                "credits_milli"
            ],
        );
        self::assertSame($before, $f->degrees->version($f->degree, $degreeVersion));
    }
}
