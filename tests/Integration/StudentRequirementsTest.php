<?php

declare(strict_types=1);
require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";
use Domain\DegreeAudit\Service\StudentRequirementsService;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\PursuitStart;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
final class StudentRequirementsTest extends TestCase
{
    public function testPublicationDoesNotAssignStudentsAndReviewIsSideEffectFree(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        self::assertNull($f->service->view($f->student, $f->assignment)["selection"]);
        $before = $f->f->digest("sis_history_events");
        $review = $f->review();
        self::assertSame($before, $f->f->digest("sis_history_events"));
        self::assertSame([], $s->sql->rows("sis_student_requirements"));
        self::assertSame("2030-01-31", $review["anticipated_completion_on"]);
        $receipt = $f->service->assign($f->student, $f->assignment, $review["review_token"]);
        $view = $f->service->view($f->student, $f->assignment);
        self::assertSame(1, $receipt["last_version"]);
        self::assertSame($f->version->value, $view["selection"]["requirements_version_id"]);
        self::assertSame("2026-01-31", $view["selection"]["pursuit_started_on"]);
        self::assertFalse($view["cohort_changed"]);
        self::assertArrayNotHasKey("reason", $view["selection"]);
        self::assertSame([], $s->sql->rows("sis_academic_credits"));
        $raw = json_encode($s->sql->rows("sis_history_events"), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("Fictional registrar approval", $raw);
        self::assertStringNotContainsString("2026-01-31", $raw);
    }
    public function testOwnerReadsDoNotNeedCatalogPrivilegesButCannotWriteOrReadAnotherStudent(): void
    {
        $f = new StudentRequirementsFixture();
        $f->assign();
        $s = $f->f->sis;
        $s->access->staff = false;
        self::assertSame(
            "B.S. Computing",
            $f->service->view($f->student, $f->assignment)["requirements"]["definition"]["name"],
        );
        self::assertCount(1, $f->service->history($f->student, $f->assignment)["items"]);
        try {
            $f->service->choices($f->student, $f->assignment);
            self::fail("Owner authorized their own assignment.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $f->service->view($f->student, $f->assignment);
    }
    public function testDedicatedOfficePermissionAndStudentReadAreBothRequiredWithoutCatalogWrite(): void
    {
        $f = new StudentRequirementsFixture();
        $a = $f->f->sis->access;
        $a->staff = false;
        $a->grants = ["sis:catalog:manage", "sis:student:read", "sis:registration:manage"];
        try {
            $f->review();
            self::fail("Catalog or registration authority assigned degree requirements.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $a->grants = [StudentRequirementsService::ASSIGN];
        try {
            $f->review();
            self::fail("Assignment permission bypassed student read.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $a->grants[] = "sis:student:read";
        $f->assign();
        self::assertSame(
            $f->version->value,
            $f->service->view($f->student, $f->assignment)["selection"]["requirements_version_id"],
        );
    }
    public function testNewerDegreeAndGroupPublicationsNeverReplaceTheStudentSelection(): void
    {
        $f = new StudentRequirementsFixture();
        $f->assign();
        $before = $f->service->view($f->student, $f->assignment);
        $d = $f->f->definition;
        $d["name"] = "New requirements";
        $d["length_months"] = 36;
        $f->f->degrees->save($f->f->degree, 1, new AuditRequirementsV3($d));
        $next = $f->f->publishDegree();
        $f->f->publishGroup();
        self::assertSame($before, $f->service->view($f->student, $f->assignment));
        $r = $f->service->review(
            $f->student,
            $f->assignment,
            $f->f->degree,
            new EntityId($next["operation_id"]),
            new PursuitStart("2026-01-31"),
            "Fictional deliberate change",
        );
        $f->service->assign($f->student, $f->assignment, $r["review_token"]);
        $changed = $f->service->view($f->student, $f->assignment);
        self::assertSame("New requirements", $changed["requirements"]["definition"]["name"]);
        self::assertSame("2029-01-31", $changed["anticipated_completion_on"]);
        self::assertCount(2, $f->service->history($f->student, $f->assignment)["items"]);
    }
    public function testExactRetryAfterAnotherDecisionReturnsOriginalReceiptWithoutDuplicateAudit(): void
    {
        $f = new StudentRequirementsFixture();
        $r = $f->review();
        $receipt = $f->service->assign($f->student, $f->assignment, $r["review_token"]);
        $r2 = $f->review("2026-02-28");
        $f->service->assign($f->student, $f->assignment, $r2["review_token"]);
        $audit = $f->f->digest("sis_audit");
        self::assertSame($receipt, $f->service->assign($f->student, $f->assignment, $r["review_token"]));
        self::assertSame($audit, $f->f->digest("sis_audit"));
        self::assertSame(
            "2026-02-28",
            $f->service->view($f->student, $f->assignment)["selection"]["pursuit_started_on"],
        );
    }
    public function testCompetingReviewCannotReplaceAnAcceptedDecision(): void
    {
        $f = new StudentRequirementsFixture();
        $a = $f->review();
        $b = $f->review("2026-02-28");
        $f->service->assign($f->student, $f->assignment, $a["review_token"]);
        $before = $f->f->digest("sis_history_events");
        try {
            $f->service->assign($f->student, $f->assignment, $b["review_token"]);
            self::fail("Competing assignment accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->f->digest("sis_history_events"));
        }
    }
    public function testChangedCohortOrClosedAssignmentInvalidatesReviewWithoutPartialAppend(): void
    {
        foreach (["cohort", "closed"] as $change) {
            $f = new StudentRequirementsFixture();
            $r = $f->review();
            $s = $f->f->sis;
            $before = $f->f->digest("sis_history_events");
            if ($change === "cohort") {
                $s->sql->update(
                    "sis_student_catalogs",
                    ["version" => 1],
                    ["assignment_id" => $f->assignment->value],
                );
            } else {
                $s->sql->update(
                    "sis_student_programs",
                    ["status" => "closed"],
                    ["id" => $f->assignment->value],
                );
            }
            try {
                $f->service->assign($f->student, $f->assignment, $r["review_token"]);
                self::fail("Stale assignment accepted.");
            } catch (ConcurrentModificationException | InvalidArgumentException) {
                self::assertSame($before, $f->f->digest("sis_history_events"));
                self::assertSame([], $s->sql->rows("sis_student_requirements"));
            }
        }
    }
    public function testCohortCorrectionFlagsExistingSelectionWithoutSilentlyRebinding(): void
    {
        $f = new StudentRequirementsFixture();
        $f->assign();
        $f->f->sis->sql->update(
            "sis_student_catalogs",
            ["version" => 1],
            ["assignment_id" => $f->assignment->value],
        );
        $view = $f->service->view($f->student, $f->assignment);
        self::assertTrue($view["cohort_changed"]);
        self::assertSame($f->version->value, $view["selection"]["requirements_version_id"]);
    }
    public function testForeignAssignmentVersionAndWrongCohortAreRejected(): void
    {
        $f = new StudentRequirementsFixture();
        $other = $f->f->sis->student($f->f->sis->user());
        try {
            $f->service->view($other->id, $f->assignment);
            self::fail("Cross-student assignment exposed.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        try {
            $f->service->review(
                $f->student,
                $f->assignment,
                $f->f->degree,
                $f->f->groupVersion,
                new PursuitStart("2026-01-31"),
                "Fictional invalid version",
            );
            self::fail("Group version used as degree.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $a = new AcademicCalendarFixture($f->f->sis);
        $year = new EntityId($a->year(2028)["id"]);
        $clone = $f->f->degrees->cloneTo($f->f->degree, $f->version, $f->f->sis->program, $year);
        $id = new EntityId($clone["id"]);
        $r = $f->f->degrees->review($id, "Fictional next year");
        $v = $f->f->degrees->publish($id, $r["review_token"]);
        $this->expectException(InvalidArgumentException::class);
        $f->service->review(
            $f->student,
            $f->assignment,
            $id,
            new EntityId($v["operation_id"]),
            new PursuitStart("2026-01-31"),
            "Wrong cohort",
        );
    }
    public function testTamperedWrongActorAndRevokedReviewsFailClosed(): void
    {
        $f = new StudentRequirementsFixture();
        $r = $f->review();
        try {
            $f->service->assign($f->student, $f->assignment, $r["review_token"] . "bad");
            self::fail("Tampered review accepted.");
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $s = $f->f->sis;
        $s->access->actor = $s->user();
        try {
            $f->service->assign($f->student, $f->assignment, $r["review_token"]);
            self::fail("Foreign actor accepted.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->staff = false;
        $s->access->grants = ["sis:student:read"];
        $this->expectException(AccessDeniedException::class);
        $f->service->assign($f->student, $f->assignment, $r["review_token"]);
    }
    public function testLateAuditFailureRollsBackEventHeadReceiptAndProjectionTogether(): void
    {
        $f = new StudentRequirementsFixture();
        $r = $f->review();
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_student_requirements",
            "sis_audit",
        ];
        $hashes = [];
        foreach ($tables as $t) {
            $hashes[$t] = $f->f->digest($t);
        }
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional failure"));
        try {
            $f->service($audit)->assign($f->student, $f->assignment, $r["review_token"]);
            self::fail("Late failure ignored.");
        } catch (RuntimeException) {
            foreach ($tables as $t) {
                self::assertSame($hashes[$t], $f->f->digest($t), $t);
            }
        }
        self::assertSame(
            1,
            $f->service->assign($f->student, $f->assignment, $r["review_token"])["last_version"],
        );
    }
    public function testProjectionTamperingAndDeletionFailClosedInsteadOfRewritingHistory(): void
    {
        foreach (["tamper", "delete"] as $change) {
            $f = new StudentRequirementsFixture();
            $f->assign();
            if ($change === "tamper") {
                $f->f->sis->sql->update(
                    "sis_student_requirements",
                    ["pursuit_started_on" => "2020-01-01"],
                    ["id" => $f->assignment->value],
                );
            } else {
                $f->f->sis->sql->execute("DELETE FROM sis_student_requirements WHERE id = ?", [
                    $f->assignment->value,
                ]);
            }
            try {
                $f->service->view($f->student, $f->assignment);
                self::fail("Corrupt projection accepted.");
            } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }
    public function testHistoryPagesAreStableBoundedAndScoped(): void
    {
        $f = new StudentRequirementsFixture();
        for ($i = 0; $i < 11; ++$i) {
            $f->assign();
        }
        $first = $f->service->history($f->student, $f->assignment);
        self::assertCount(10, $first["items"]);
        $f->assign();
        self::assertCount(
            1,
            $f->service->history($f->student, $f->assignment, $first["next_cursor"])["items"],
        );
        self::assertArrayNotHasKey("reason", $first["items"][0]);
        self::assertArrayNotHasKey("actor_id", $first["items"][0]);
    }
    public function testPursuitDatesAndCompletionUseCalendarClampingAndUnknownLengthStaysUnknown(): void
    {
        self::assertSame("2027-02-28", new PursuitStart("2027-01-31")->anticipatedCompletion(1));
        self::assertSame("2028-02-29", new PursuitStart("2028-01-31")->anticipatedCompletion(1));
        self::assertNull(new PursuitStart("2026-01-31")->anticipatedCompletion(null));
        try {
            new PursuitStart("2026-02-30");
            self::fail("Impossible date accepted.");
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $f = new StudentRequirementsFixture();
        $this->expectException(InvalidArgumentException::class);
        $f->review("2027-01-01");
    }

    public function testCodefyReplayReconstructsTheProjectionWithoutRepeatingTheDecision(): void
    {
        $f = new StudentRequirementsFixture();
        $f->assign();
        $f->assign();
        $stream = new \Domain\Shared\EventSourcing\StreamReference(
            new \Domain\DegreeAudit\ValueObject\StudentRequirementsId($f->assignment->value),
            "student_degree_requirements",
        );
        $events = array_map(static fn($e) => $e->event, $f->f->history->page($stream)->events);
        $root = \Domain\DegreeAudit\StudentRequirements::reconstituteFromEventStream(
            new \Codefy\Domain\EventSourcing\EventStream($stream->aggregateId, $events),
        );
        self::assertSame(2, $root->playhead());
        self::assertSame($f->version->value, $root->selection()["requirements_version_id"]);
        $original = $f->service->view($f->student, $f->assignment);
        $before = $f->f->digest("sis_history_events");
        $f->f->sis->sql->execute("DELETE FROM sis_student_requirements WHERE id = ?", [
            $f->assignment->value,
        ]);
        new \Infrastructure\Persistence\Repository\SqlStudentRequirementsRepository($f->f->sis->sql)->project(
            $f->assignment,
            $root->selection(),
            $root->playhead(),
        );
        self::assertSame($original, $f->service->view($f->student, $f->assignment));
        self::assertSame($before, $f->f->digest("sis_history_events"));
    }
    public function testMissingCohortAndUnpublishedDefinitionsCannotBeAssigned(): void
    {
        $f = new StudentRequirementsFixture();
        $f->f->sis->sql->execute("DELETE FROM sis_student_catalogs WHERE assignment_id = ?", [
            $f->assignment->value,
        ]);
        self::assertNull($f->service->view($f->student, $f->assignment)["selection"]);
        $this->expectException(InvalidArgumentException::class);
        $f->review();
    }
    public function testNewOfficePermissionDoesNotExpandExistingAdminOrStaffGrants(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        $permission = $s->sql->one("sis_access_permissions", ["name" => StudentRequirementsService::ASSIGN]);
        self::assertSame(1, (int) $permission["is_system"]);
        self::assertCount(
            1,
            $s->sql->rows("sis_access_implications", ["descendant_id" => $permission["id"]]),
        );
        $access = new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql);
        foreach (["admin", "staff", "super"] as $roleName) {
            $user = $s->user();
            $role = $s->sql->one("sis_access_roles", ["name" => $roleName]);
            $s->sql->insert("sis_access_user_roles", [
                "id" => EntityId::generate()->value,
                "user_id" => $user->value,
                "role_id" => $role["id"],
            ]);
            self::assertSame(
                $roleName === "super",
                $access->allows($user->value, StudentRequirementsService::ASSIGN),
            );
        }
        $before = $f->f->digest("sis_access_role_permissions");
        $m = new CreateSisStudentRequirements("repeat");
        $m->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $s->connection,
                    "test_migrations",
                ),
            ]),
        );
        $m->up();
        self::assertSame($before, $f->f->digest("sis_access_role_permissions"));
        self::assertCount(
            1,
            $s->sql->rows("sis_access_permissions", ["name" => StudentRequirementsService::ASSIGN]),
        );
    }
    public function testHistoryCursorCannotBeReusedForAnotherStudentProgram(): void
    {
        $f = new StudentRequirementsFixture();
        for ($i = 0; $i < 11; ++$i) {
            $f->assign();
        }
        $cursor = $f->service->history($f->student, $f->assignment)["next_cursor"];
        $other = $f->f->sis->student($f->f->sis->user());
        $assignment = new EntityId(
            $f->f->sis->sql->rows("sis_student_programs", ["student_id" => $other->id->value], 1)[0]["id"],
        );
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $f->service->history($other->id, $assignment, $cursor);
    }
}
