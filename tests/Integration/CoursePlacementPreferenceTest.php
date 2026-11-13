<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";

use Domain\DegreeAudit\Service\RequirementAdjustmentService;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class CoursePlacementPreferenceTest extends TestCase
{
    private StudentRequirementsFixture $f;
    protected function setUp(): void
    {
        $this->f = new StudentRequirementsFixture();
        $this->f->assign();
    }
    private function service(?\Domain\Audit\Repository\AuditTrail $audit = null): RequirementAdjustmentService
    {
        $f = $this->f;
        $s = $f->f->sis;
        return new RequirementAdjustmentService(
            new \Infrastructure\Persistence\Repository\SqlRequirementAdjustmentRepository($s->sql),
            $f->service,
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($s->sql),
            $f->f->history,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $f->f->catalog->fixture->cipher,
            $s->clock,
            new PublishedRequirementsReader($f->f->history),
            new \Infrastructure\Persistence\Repository\SqlReplacementCourseRepository($s->sql),
        );
    }
    private function definition(): array
    {
        return [
            "kind" => "placement",
            "course_id" => $this->f->f->groupDefinition["courses"][0]["course_id"],
            "group_id" => $this->f->f->group->value,
        ];
    }
    private function review(): array
    {
        return $this->service()->reviewGrant(
            $this->f->student,
            $this->f->assignment,
            $this->definition(),
            "Fictional advisor recommendation reviewed by registrar.",
        );
    }
    private function commit(array $r): array
    {
        return $this->service()->commit($this->f->student, $this->f->assignment, $r["review_token"]);
    }
    public function testOnlyTheChosenPublishedCourseAndGroupAreRetainedAndReviewDoesNotWrite(): void
    {
        $f = $this->f;
        $events = $f->f->sis->sql->rows("sis_history_events");
        $credits = $f->f->sis->sql->rows("sis_academic_credits");
        $finance = $f->f->sis->financialEvents();
        $r = $this->review();
        self::assertSame($events, $f->f->sis->sql->rows("sis_history_events"));
        self::assertSame($f->f->groupVersion->value, $r["adjustment"]["definition"]["group_version_id"]);
        self::assertSame(
            $f->f->groupDefinition["courses"][0]["version_id"],
            $r["adjustment"]["definition"]["course_version_id"],
        );
        $receipt = $this->commit($r);
        self::assertSame($receipt, $this->commit($r));
        $view = $this->service()->view($f->student, $f->assignment);
        self::assertSame(
            $f->f->group->value,
            $view["effective_minimums"]["placements"][$this->definition()["course_id"]]["group_id"],
        );
        self::assertSame([], $view["effective_minimums"]["waived_course_ids"]);
        self::assertSame(120000, $view["effective_minimums"]["degree"]["min_credits_milli"]);
        self::assertSame($credits, $f->f->sis->sql->rows("sis_academic_credits"));
        self::assertSame($finance, $f->f->sis->financialEvents());
        foreach ($events as $e) {
            self::assertSame($e, $f->f->sis->sql->one("sis_history_events", ["id" => $e["id"]]));
        }
        $history = $this->service()->history($f->student, $f->assignment);
        self::assertStringContainsString("Course placement", $history["items"][0]["subject_label"]);
        self::assertSame("General education", $history["items"][0]["scope_label"]);
        self::assertStringNotContainsString(
            "Fictional advisor recommendation",
            json_encode($f->f->sis->sql->rows("sis_history_events"), JSON_THROW_ON_ERROR),
        );
        $this->expectException(InvalidArgumentException::class);
        $this->review();
    }
    public function testAReviewedMoveSupersedesThePreviousGroupAndAnOlderConfirmationCannotUndoIt(): void
    {
        $f = $this->f;
        $other = EntityId::generate();
        $group = $f->f->groupDefinition;
        $group["name"] = "Professional studies";
        $f->f->groups->create($other, new \Domain\DegreeAudit\ValueObject\CourseGroupDefinition($group));
        $review = $f->f->groups->review($other, "Fictional overlapping group.");
        $published = $f->f->groups->publish($other, $review["review_token"]);
        $f->f->definition["groups"][] = [
            "group_id" => $other->value,
            "version_id" => $published["operation_id"],
        ];
        $f->f->degrees->save(
            $f->f->degree,
            $f->f->degrees->view($f->f->degree)["revision"],
            new AuditRequirementsV3($f->f->definition),
        );
        $f->version = new EntityId($f->f->publishDegree()["operation_id"]);
        $f->assign();
        $first = $this->review();
        $move = fn() => $this->service()->reviewGrant(
            $f->student,
            $f->assignment,
            array_replace($this->definition(), ["group_id" => $other->value]),
            "Fictional changed group recommendation.",
        );
        $stale = $move();
        $receipt = $this->commit($first);
        try {
            $this->commit($stale);
            self::fail("A competing unaccepted preference overwrote the accepted decision.");
        } catch (ConcurrentModificationException) {
            self::assertSame(
                $f->f->group->value,
                $this->service()->view($f->student, $f->assignment)["effective_minimums"]["placements"][
                    $this->definition()["course_id"]
                ]["group_id"],
            );
        }
        $fresh = $move();
        self::assertSame(
            $first["adjustment"]["adjustment_id"],
            $fresh["adjustment"]["supersedes_adjustment_id"],
        );
        $this->commit($fresh);
        $events = $f->f->sis->sql->rows("sis_history_events");
        self::assertSame($receipt, $this->commit($first));
        $view = $this->service()->view($f->student, $f->assignment);
        self::assertCount(1, $view["items"]);
        self::assertSame(
            $other->value,
            $view["effective_minimums"]["placements"][$this->definition()["course_id"]]["group_id"],
        );
        self::assertSame(
            ["General education", "Professional studies"],
            array_column($this->service()->history($f->student, $f->assignment)["items"], "scope_label"),
        );
        self::assertSame($events, $f->f->sis->sql->rows("sis_history_events"));
    }
    public function testRevocationRetainsHistoryAndOriginalRetriesCannotRestoreARevokedPreference(): void
    {
        $f = $this->f;
        $r = $this->review();
        $receipt = $this->commit($r);
        $revoke = $this->service()->reviewRevoke(
            $f->student,
            $f->assignment,
            new EntityId($r["adjustment"]["adjustment_id"]),
            "Fictional release of group preference.",
        );
        $revoked = $this->commit($revoke);
        $events = $f->f->sis->sql->rows("sis_history_events");
        $f->f->sis->clock->time = $f->f->sis->clock->time->modify("+1 day");
        self::assertSame($receipt, $this->commit($r));
        self::assertSame($revoked, $this->commit($revoke));
        self::assertSame(
            [],
            $this->service()->view($f->student, $f->assignment)["effective_minimums"]["placements"],
        );
        self::assertSame($events, $f->f->sis->sql->rows("sis_history_events"));
    }
    public function testExpiredGrantAndRevocationRequireFreshReviewsWithNoPartialHistory(): void
    {
        foreach (["grant", "revoke"] as $action) {
            $this->setUp();
            $r = $this->review();
            if ($action === "revoke") {
                $this->commit($r);
                $r = $this->service()->reviewRevoke(
                    $this->f->student,
                    $this->f->assignment,
                    new EntityId($r["adjustment"]["adjustment_id"]),
                    "Fictional revocation evidence.",
                );
            }
            $events = $this->f->f->sis->sql->rows("sis_history_events");
            $rows = $this->f->f->sis->sql->rows("sis_requirement_adjustments");
            $this->f->f->sis->clock->time = $this->f->f->sis->clock->time->modify("+21 minutes");
            try {
                $this->commit($r);
                self::fail("Expired preference review committed.");
            } catch (ConcurrentModificationException) {
                self::assertSame($events, $this->f->f->sis->sql->rows("sis_history_events"));
                self::assertSame($rows, $this->f->f->sis->sql->rows("sis_requirement_adjustments"));
            }
        }
    }
    public function testNewRequirementPublicationDoesNotInheritTheOldPreference(): void
    {
        $f = $this->f;
        $this->commit($this->review());
        $f->f->definition["name"] = "Fictional revised degree";
        $f->f->degrees->save(
            $f->f->degree,
            $f->f->degrees->view($f->f->degree)["revision"],
            new AuditRequirementsV3($f->f->definition),
        );
        $f->version = new EntityId($f->f->publishDegree()["operation_id"]);
        $f->assign();
        $v = $this->service()->view($f->student, $f->assignment);
        self::assertFalse($v["items"][0]["applicable"]);
        self::assertSame([], $v["effective_minimums"]["placements"]);
        self::assertSame(
            "General education",
            $this->service()->history($f->student, $f->assignment)["items"][0]["scope_label"],
        );
    }
    public function testForeignTargetsClientPublicationReferencesAndWrongActorAreRejected(): void
    {
        $f = $this->f;
        foreach (
            [
                array_replace($this->definition(), ["group_id" => EntityId::generate()->value]),
                array_replace($this->definition(), ["course_id" => EntityId::generate()->value]),
                $this->definition() + ["group_version_id" => EntityId::generate()->value],
                $this->definition() + ["course_version_id" => EntityId::generate()->value],
            ]
            as $definition
        ) {
            $before = $f->f->sis->sql->rows("sis_history_events");
            try {
                $this->service()->reviewGrant(
                    $f->student,
                    $f->assignment,
                    $definition,
                    "Fictional invalid request.",
                );
                self::fail("Foreign or client-controlled publication accepted.");
            } catch (InvalidArgumentException) {
                self::assertSame($before, $f->f->sis->sql->rows("sis_history_events"));
            }
        }
        $r = $this->review();
        $f->f->sis->access->actor = EntityId::generate();
        $this->expectException(AccessDeniedException::class);
        $this->commit($r);
    }
    public function testIndependentOfficePermissionsAndStudentScopeRemainRequired(): void
    {
        $f = $this->f;
        $r = $this->review();
        $otherStudent = $f->f->sis->student()->id;
        $f->f->sis->access->staff = false;
        foreach (
            [
                ["sis:student:read"],
                ["sis:degree-audit:assign", "sis:student:read"],
                ["sis:degree-audit:adjust"],
            ]
            as $grants
        ) {
            $f->f->sis->access->grants = $grants;
            try {
                $this->commit($r);
                self::fail("Missing independent permission accepted.");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
        $f->f->sis->access->staff = true;
        $this->expectException(\Domain\Shared\Exception\RecordNotFoundException::class);
        // A token cannot be moved to another assignment, even with broad registrar authority.
        $this->service()->commit($otherStudent, $f->assignment, $r["review_token"]);
    }
    private function approvedReplacement(): array
    {
        $f = $this->f;
        $course = EntityId::generate();
        $f->f->sis->registration->addCourse(
            new \Domain\Catalog\Course(
                $course,
                "ALT.101",
                "Fictional accepted alternative",
                4000,
            ),
        );
        $publications = $f->f->catalog->publications();
        $review = $publications->review(
            $course,
            "Fictional course definition.",
        );
        $version = $publications->publish($course, $review["review_token"])[
            "operation_id"
        ];
        $review = $this->service()->reviewGrant(
            $f->student,
            $f->assignment,
            [
                "kind" => "substitution",
                "course_id" => $this->definition()["course_id"],
                "replacement_course_id" => $course->value,
                "replacement_version_id" => $version,
            ],
            "Fictional approved alternative.",
        );
        $this->commit($review);
        return $review["adjustment"];
    }

    public function testApprovedSubstituteHasSeparatePlacementChoicesAndRetainedHistory(): void
    {
        $f = $this->f;
        $source = $this->approvedReplacement();
        $definition = [
            "kind" => "placement",
            "course_id" => $source["definition"]["replacement_course_id"],
            "group_id" => $f->f->group->value,
            "substitution_adjustment_id" => $source["adjustment_id"],
        ];
        $before = $f->f->digest("sis_history_events");
        $view = $this->service()->view($f->student, $f->assignment);
        self::assertCount(5, $view["courses"]);
        self::assertCount(6, $view["placement_courses"]);
        $choice = $view["placement_courses"][5];
        self::assertSame(
            $source["adjustment_id"],
            $choice["substitution_adjustment_id"],
        );
        self::assertSame("ALT.101", $choice["code"]);
        self::assertSame(
            "MATH.101",
            $choice["placement_groups"][0]["required_course_code"],
        );
        $review = $this->service()->reviewGrant(
            $f->student,
            $f->assignment,
            $definition,
            "Fictional advisor placement approved.",
        );
        self::assertSame($before, $f->f->digest("sis_history_events"));
        $stored = $review["adjustment"]["definition"];
        self::assertSame(
            $source["definition"]["replacement_version_id"],
            $stored["course_version_id"],
        );
        self::assertSame(
            $source["definition"]["course_id"],
            $stored["required_course_id"],
        );
        self::assertSame(
            $f->f->groupVersion->value,
            $stored["group_version_id"],
        );
        $academic = $f->f->digest("sis_academic_credits");
        $finance = $f->f->sis->financialEvents();
        $receipt = $this->commit($review);
        $f->f->sis->sql->update(
            "sis_courses",
            ["title" => "Fictional current draft title"],
            [
                "id" => $definition["course_id"],
            ],
        );
        $view = $this->service()->view($f->student, $f->assignment);
        self::assertSame(
            $definition["group_id"],
            $view["effective_minimums"]["placements"][$definition["course_id"]][
                "group_id"
            ],
        );
        $history = $this->service()->history($f->student, $f->assignment)[
            "items"
        ];
        self::assertSame(
            "Course placement · ALT.101 · Fictional accepted alternative",
            $history[1]["subject_label"],
        );
        self::assertSame("General education", $history[1]["scope_label"]);
        $revoke = $this->service()->reviewRevoke(
            $f->student,
            $f->assignment,
            new EntityId($source["adjustment_id"]),
            "Fictional substitution revoked.",
        );
        $this->commit($revoke);
        $view = $this->service()->view($f->student, $f->assignment);
        self::assertCount(5, $view["placement_courses"]);
        self::assertSame([], $view["effective_minimums"]["substitutions"]);
        self::assertArrayHasKey(
            $definition["course_id"],
            $view["effective_minimums"]["placements"],
        );
        self::assertSame(
            "Fictional accepted alternative",
            $view["substitution_details"][
                $review["adjustment"]["adjustment_id"]
            ]["title"],
        );
        $f->f->sis->clock->time = $f->f->sis->clock->time->modify("+1 day");
        $events = $f->f->digest("sis_history_events");
        self::assertSame($receipt, $this->commit($review));
        self::assertSame($events, $f->f->digest("sis_history_events"));
        self::assertSame($academic, $f->f->digest("sis_academic_credits"));
        self::assertSame($finance, $f->f->sis->financialEvents());
        $revoke = $this->service()->reviewRevoke(
            $f->student,
            $f->assignment,
            new EntityId($review["adjustment"]["adjustment_id"]),
            "Fictional release of independent preference.",
        );
        $this->commit($revoke);
        self::assertSame(
            [],
            $this->service()->view($f->student, $f->assignment)[
                "effective_minimums"
            ]["placements"],
        );
    }

    public function testSubstitutePlacementRejectsUnapprovedForeignAndRevokedSourcesAndStaleConfirmation(): void
    {
        $f = $this->f;
        $source = $this->approvedReplacement();
        $definition = [
            "kind" => "placement",
            "course_id" => $source["definition"]["replacement_course_id"],
            "group_id" => $f->f->group->value,
            "substitution_adjustment_id" => $source["adjustment_id"],
        ];
        $cases = [
            array_diff_key($definition, ["substitution_adjustment_id" => true]),
            array_replace($definition, [
                "substitution_adjustment_id" => EntityId::generate()->value,
            ]),
            array_replace($definition, ["substitution_adjustment_id" => null]),
            array_replace($definition, [
                "course_id" => $this->definition()["course_id"],
            ]),
            array_replace($definition, [
                "group_id" => EntityId::generate()->value,
            ]),
            $definition + ["course_version_id" => EntityId::generate()->value],
            $definition + [
                "required_course_id" => $this->definition()["course_id"],
            ],
        ];
        foreach ($cases as $invalid) {
            $before = $f->f->digest("sis_history_events");
            try {
                $this->service()->reviewGrant(
                    $f->student,
                    $f->assignment,
                    $invalid,
                    "Fictional invalid selection.",
                );
                self::fail(
                    "Unapproved or client-controlled replacement placement accepted.",
                );
            } catch (InvalidArgumentException) {
                self::assertSame($before, $f->f->digest("sis_history_events"));
            }
        }
        $review = $this->service()->reviewGrant(
            $f->student,
            $f->assignment,
            $definition,
            "Fictional placement to become stale.",
        );
        $revoke = $this->service()->reviewRevoke(
            $f->student,
            $f->assignment,
            new EntityId($source["adjustment_id"]),
            "Fictional revocation before confirmation.",
        );
        $this->commit($revoke);
        $before = $f->f->digest("sis_history_events");
        try {
            $this->commit($review);
            self::fail("Stale substitution source accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->f->digest("sis_history_events"));
        }
        $this->expectException(InvalidArgumentException::class);
        $this->service()->reviewGrant(
            $f->student,
            $f->assignment,
            $definition,
            "Fictional revoked source.",
        );
    }
    public function testAuditFailureRollsBackEventReceiptAndActiveProjection(): void
    {
        $f = $this->f;
        $r = $this->review();
        $before = [];
        foreach (["sis_history_events", "sis_history_operations", "sis_requirement_adjustments"] as $table) {
            $before[$table] = $f->f->sis->sql->rows($table);
        }
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional audit unavailable."));
        try {
            $this->service($audit)->commit($f->student, $f->assignment, $r["review_token"]);
            self::fail("Unaudited preference committed.");
        } catch (RuntimeException) {
            foreach ($before as $table => $rows) {
                self::assertSame($rows, $f->f->sis->sql->rows($table));
            }
        }
    }
}
