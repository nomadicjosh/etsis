<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";

use Domain\DegreeAudit\Service\AcademicWorkMatchService;
use Domain\DegreeAudit\Service\AcademicWorkService;
use Domain\DegreeAudit\Service\CourseMatchEvaluator;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\Service\RequirementAdjustmentService;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Section;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAcademicWorkRepository;
use Infrastructure\Persistence\Repository\SqlReplacementCourseRepository;
use Infrastructure\Persistence\Repository\SqlRequirementAdjustmentRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;
use StudentRequirementsFixture;

final class AcademicWorkMatchTest extends TestCase
{
    private function adjustments(StudentRequirementsFixture $f): RequirementAdjustmentService
    {
        $s = $f->f->sis;
        return new RequirementAdjustmentService(
            new SqlRequirementAdjustmentRepository($s->sql),
            $f->service,
            new SqlStudentRecordRepository($s->sql),
            $f->f->history,
            $s->access,
            $s->transactions,
            $s->audit,
            $f->f->catalog->fixture->cipher,
            $s->clock,
            new PublishedRequirementsReader($f->f->history),
            new SqlReplacementCourseRepository($s->sql),
        );
    }

    private function service(StudentRequirementsFixture $f): AcademicWorkMatchService
    {
        $s = $f->f->sis;
        $students = new SqlStudentRecordRepository($s->sql);
        $work = new AcademicWorkService(
            new SqlAcademicWorkRepository($s->sql),
            $s->admissions,
            $students,
            $s->academicCredits(),
            $s->transferCredits(),
            $s->access,
            $s->transactions,
        );
        return new AcademicWorkMatchService(
            $work,
            $this->adjustments($f),
            new CourseMatchEvaluator(),
            $f->service,
            $s->transactions,
        );
    }

    private function gradedWork(StudentRequirementsFixture $f): EntityId
    {
        $s = $f->f->sis;
        $s->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $course = $f->f->groupDefinition["courses"][0];
        $section = new Section(
            EntityId::generate(),
            new EntityId($course["course_id"]),
            $s->term,
            $s->access->actor,
            "FICTIONAL.MATCH.A",
            20,
            true,
            true,
            new Money(0, "USD"),
        );
        $s->registration->addSection($section);
        $bindings = $f->f->catalog->bindings();
        $review = $bindings->review(
            $section->id,
            new EntityId($course["version_id"]),
            "Fictional published offering.",
        );
        $bindings->commit($section->id, $review["review_token"]);
        $enrollment = $s->service->register($f->student, $section->id)->id;
        $ledger = $s->academicCredits();
        $grade = new EntityId($ledger->choices($f->student, $section->id, $enrollment)["items"][0]["id"]);
        $gradeReview = $ledger->review($f->student, $section->id, $enrollment, $grade, 0);
        $ledger->commit($f->student, $section->id, $gradeReview["review_token"]);
        return new EntityId($s->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"]);
    }

    public function testMatchesUseTheRetainedSelectionAndDoNotWriteAnAuditResult(): void
    {
        $f = new StudentRequirementsFixture();
        $f->assign();
        $credit = $this->gradedWork($f);
        $s = $f->f->sis;
        $before = $s->sql->rows("sis_history_events");
        $receipts = $s->sql->rows("sis_history_operations");
        $result = $this->service($f)->page($f->student, $f->assignment);
        self::assertSame($f->version->value, $result["matches_context"]["requirements_version_id"]);
        self::assertSame("candidate", $result["items"][0]["requirement_matches"][0]["status"]);
        self::assertSame($credit->value, $result["items"][0]["id"]);
        self::assertSame(3000, $result["items"][0]["completed_milli"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame($receipts, $s->sql->rows("sis_history_operations"));
        $f->f->definition["name"] = "New requirements today";
        $f->f->degrees->save(
            $f->f->degree,
            1,
            new \Domain\DegreeAudit\ValueObject\AuditRequirementsV3($f->f->definition),
        );
        $f->f->publishDegree();
        $s->sql->update(
            "sis_courses",
            ["title" => "New title today"],
            ["id" => $result["items"][0]["course_id"]],
        );
        $again = $this->service($f)->page($f->student, $f->assignment);
        self::assertSame($result["matches_context"], $again["matches_context"]);
        self::assertSame(
            $result["items"][0]["requirement_matches"],
            $again["items"][0]["requirement_matches"],
        );
    }

    public function testAStudentWithoutASelectionReceivesGuidanceAndNoInventedRequirements(): void
    {
        $f = new StudentRequirementsFixture();
        $this->gradedWork($f);
        $result = $this->service($f)->page($f->student, $f->assignment);
        self::assertNull($result["matches_context"]);
        self::assertCount(1, $result["items"]);
        self::assertArrayNotHasKey("requirement_matches", $result["items"][0]);
    }

    public function testWaiverRevocationChangesOnlyTheMatchExplanationAndRemainsPursuitScoped(): void
    {
        $f = new StudentRequirementsFixture();
        $f->assign();
        $this->gradedWork($f);
        $adjustments = $this->adjustments($f);
        $review = $adjustments->reviewGrant(
            $f->student,
            $f->assignment,
            [
                "kind" => "waiver",
                "course_id" => $f->f->groupDefinition["courses"][0]["course_id"],
            ],
            "Fictional documented waiver.",
        );
        $receipt = $adjustments->commit($f->student, $f->assignment, $review["review_token"]);
        $service = $this->service($f);
        $waived = $service->page($f->student, $f->assignment)["items"][0];
        self::assertSame("waived", $waived["requirement_matches"][0]["status"]);
        self::assertSame(3000, $waived["completed_milli"]);
        $revoke = $adjustments->reviewRevoke(
            $f->student,
            $f->assignment,
            new EntityId($receipt["operation_id"]),
            "Fictional waiver rescission.",
        );
        $adjustments->commit($f->student, $f->assignment, $revoke["review_token"]);
        self::assertSame(
            "candidate",
            $service->page($f->student, $f->assignment)["items"][0]["requirement_matches"][0]["status"],
        );
    }

    public function testOwnerOrAcademicReadIsRequiredAndForeignAssignmentsAreRejected(): void
    {
        $f = new StudentRequirementsFixture();
        $f->assign();
        $s = $f->f->sis;
        $s->access->staff = false;
        $service = $this->service($f);
        self::assertNotNull($service->page($f->student, $f->assignment)["matches_context"]);
        $s->access->actor = $s->user();
        try {
            $service->page($f->student, $f->assignment);
            self::fail("Unrelated user read matches.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->grants = ["sis:student:read"];
        self::assertNotNull($service->page($f->student, $f->assignment)["matches_context"]);
        $s->access->staff = true;
        $other = $s->student()->id;
        $otherAssignment = new EntityId(
            $s->sql->rows("sis_student_programs", ["student_id" => $other->value], 1)[0]["id"],
        );
        $this->expectException(RecordNotFoundException::class);
        $service->page($f->student, $otherAssignment);
    }

    public function testMatchingVerifiesEachDegreeCourseHistoryOncePerRequestAndRechecksFreshAuthority(): void
    {
        $f = new StudentRequirementsFixture();
        $f->assign();
        $s = $f->f->sis;
        $reads = 0;
        $revoke = false;
        $history = $this->createStub(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $history
            ->method("page")
            ->willReturnCallback(function ($stream, $cursor, $limit) use ($f, $s, &$reads, &$revoke) {
                if ($stream->type === "course_publication") {
                    $reads++;
                    if ($revoke) {
                        $s->access->grants = [];
                    }
                }
                return $f->f->history->page($stream, $cursor, $limit);
            });
        $f->service = $f->service(history: $history);
        $service = $this->service($f);
        $service->page($f->student, $f->assignment);
        self::assertSame(5, $reads);
        $service->page($f->student, $f->assignment);
        self::assertSame(10, $reads);
        $s->access->actor = $s->user();
        $s->access->staff = false;
        $s->access->grants = ["sis:student:read"];
        $revoke = true;
        $this->expectException(AccessDeniedException::class);
        $service->page($f->student, $f->assignment);
    }

    public function testTamperedWorkCannotBeUsedAsARequirementCandidate(): void
    {
        $f = new StudentRequirementsFixture();
        $f->assign();
        $credit = $this->gradedWork($f);
        $f->f->sis->sql->update(
            "sis_academic_credits",
            ["completed_milli" => 2000],
            ["id" => $credit->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $this->service($f)->page($f->student, $f->assignment);
    }
}
