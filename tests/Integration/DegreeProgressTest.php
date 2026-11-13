<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";
require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";

use Domain\DegreeAudit\Repository\AcademicInputRepository;
use Domain\DegreeAudit\Service\AcademicInputService;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\Service\AllocationGapAnalyzer;
use Domain\DegreeAudit\Service\CourseAllocationPlanner;
use Domain\DegreeAudit\Service\CourseMatchEvaluator;
use Domain\DegreeAudit\Service\DegreeMinimumEvaluator;
use Domain\DegreeAudit\Service\DegreeWorkReview;
use Domain\DegreeAudit\Service\DegreeProgressService;
use Domain\DegreeAudit\Service\GroupAllocationEvaluator;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\Service\RequirementAdjustmentService;
use Domain\DegreeAudit\Service\RetainedAttemptReader;
use Domain\DegreeAudit\ValueObject\AcademicInputInventory;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Section;
use Domain\Registration\Service\RetainedSectionReader;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\ValueObject\AwardChange;
use Infrastructure\Persistence\Repository\SqlAcademicInputRepository;
use Infrastructure\Persistence\Repository\SqlReplacementCourseRepository;
use Infrastructure\Persistence\Repository\SqlRequirementAdjustmentRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;
use StudentRequirementsFixture;
use TransferAwardFixture;

final class DegreeProgressTest extends TestCase
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
    private function service(
        StudentRequirementsFixture $f,
        ?AcademicInputRepository $repository = null,
        int $steps = 100000,
    ): DegreeProgressService {
        $s = $f->f->sis;
        $students = new SqlStudentRecordRepository($s->sql);
        $inputs = new AcademicInputService(
            $repository ?? new SqlAcademicInputRepository($s->sql),
            $s->academicCredits(),
            $s->transferCredits(),
            $s->enrollmentLedger(),
            $students,
            $f->service,
            $s->transactions,
        );
        return new DegreeProgressService(
            $inputs,
            $f->service,
            $this->adjustments($f),
            new \Domain\DegreeAudit\Service\DegreeProgressEvaluator(
                new AcademicPolicyEvaluator(),
                new RetainedAttemptReader(new RetainedSectionReader($f->f->history)),
                new DegreeMinimumEvaluator(),
                new GroupAllocationEvaluator(
                    new CourseMatchEvaluator(),
                    new AcademicPolicyEvaluator(),
                    new CourseAllocationPlanner($steps),
                    new AllocationGapAnalyzer(),
                ),
                new DegreeWorkReview(),
                new \Domain\DegreeAudit\Service\SavedCourseRulePreview(new \Domain\Catalog\Service\PublishedCourseReader($f->f->history)),
            ),
            $students,
            $s->transactions,
        );
    }
    private function institution(): StudentRequirementsFixture
    {
        $f = new StudentRequirementsFixture();
        $f->f->sis->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "B",
                "min_score_milli" => 80000,
                "max_score_milli" => 89999,
                "points_milli" => 3000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "F",
                "min_score_milli" => 0,
                "max_score_milli" => 79999,
                "points_milli" => 0,
                "passes" => false,
                "counts_gpa" => true,
            ],
            [
                "code" => "TR",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => 0,
                "passes" => true,
                "counts_gpa" => false,
            ],
        ]);
        $d = $f->f->definition;
        $d["min_credits_milli"] = 12000;
        $d["min_resident_milli"] = 6000;
        $d["min_cumulative_gpa_milli"] = $d["min_overall_gpa_milli"] = 3500;
        $d["policies"]["transfer_overall_gpa"] = "include_eligible";
        $row = $f->f->degrees->view($f->f->degree);
        $f->f->degrees->save($f->f->degree, $row["revision"], new AuditRequirementsV3($d));
        $f->version = new EntityId($f->f->publishDegree()["operation_id"]);
        $f->assign();
        return $f;
    }
    private function work(StudentRequirementsFixture $f, int $index = 0, ?string $grade = "A", ?array $course = null): array
    {
        $s = $f->f->sis;
        $c = $course ?? $f->f->groupDefinition["courses"][$index];
        $section = new Section(
            EntityId::generate(),
            new EntityId($c["course_id"]),
            $s->term,
            $s->access->actor,
            "FICTIONAL." . $index,
            20,
            true,
            true,
            new Money(0, "USD"),
        );
        $s->registration->addSection($section);
        $bindings = $f->f->catalog->bindings();
        $review = $bindings->review(
            $section->id,
            new EntityId($c["version_id"]),
            "Fictional retained offering.",
        );
        $bindings->commit($section->id, $review["review_token"]);
        $enrollment = $s->service->register($f->student, $section->id)->id;
        $choices = array_column(
            $s->academicCredits()->choices($f->student, $section->id, $enrollment)["items"],
            "id",
            "code",
        );
        if ($grade !== null) {
            $review = $s
                ->academicCredits()
                ->review($f->student, $section->id, $enrollment, new EntityId($choices[$grade]), 0);
            $s->academicCredits()->commit($f->student, $section->id, $review["review_token"]);
        }
        $credit = new EntityId(
            $s->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"],
        );
        return [$section, $enrollment, $credit, $choices];
    }
    public function testOneReadCombinesGeneralMinimumsAndGroupPlacementWithoutWritingOrGrantingEligibility(): void
    {
        $f = $this->institution();
        foreach ([0, 1, 2, 4] as $i) {
            $this->work($f, $i);
        }
        $s = $f->f->sis;
        $events = $s->sql->rows("sis_history_events");
        $receipts = $s->sql->rows("sis_history_operations");
        $repository = new class ($s) implements AcademicInputRepository {
            public int $reads = 0;
            public function __construct(private \SisFixture $s) {}
            public function inventory(EntityId $student): AcademicInputInventory
            {
                $this->reads++;
                return new SqlAcademicInputRepository($this->s->sql)->inventory($student);
            }
        };
        $result = $this->service($f, $repository)->preview($f->student, $f->assignment);
        self::assertSame(1, $repository->reads);
        self::assertSame("review", $result["status"]);
        self::assertSame(
            ["satisfied", "satisfied", "satisfied", "satisfied"],
            array_column($result["minimums"], "status"),
        );
        self::assertSame("feasible", $result["allocation"]["outcome"]);
        self::assertSame(0, $result["work_summary"]["unused_completed_count"]);
        self::assertSame(0, $result["work_summary"]["awaiting_grade_milli"]);
        self::assertSame(4000, $result["gpa"]["cumulative"]["milli"]);
        self::assertSame(4000, $result["gpa"]["overall"]["milli"]);
        self::assertSame($f->version->value, $result["context"]["requirements_version_id"]);
        self::assertArrayNotHasKey("graduation_eligible", $result);
        self::assertArrayNotHasKey("references", $result);
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        self::assertSame($receipts, $s->sql->rows("sis_history_operations"));
    }
    public function testPublishedTransferCourseCanMeetItsRetainedGroupWithoutUsingTodaysCatalogLabels(): void
    {
        $f = $this->institution();
        foreach ([1, 2, 4] as $i) {
            $this->work($f, $i);
        }
        $course = $f->f->groupDefinition["courses"][0];
        $t = new \TransferAwardFixture(
            $f->f->sis,
            $f->f->catalog->fixture->cipher,
            $f->student,
            course: new EntityId($course["course_id"]),
            courseVersion: new EntityId($course["version_id"])
        );
        $f->f->sis->sql->update("sis_courses", ["title" => "Changed today"], ["id" => $course["course_id"]]);
        $f->f->sis->publishCourse(new EntityId($course["course_id"]));
        $r = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("feasible", $r["allocation"]["outcome"]);
        self::assertSame(12000, $r["minimums"][0]["actual_minimum_milli"]);
        self::assertCount(4, $r["allocation"]["groups"][0]["placements"]);
        self::assertStringNotContainsString("Changed today", json_encode($r, JSON_THROW_ON_ERROR));
        $facts = $f->f->sis->transferCredits()->auditFacts($t->credit, $f->student);
        self::assertSame($course["version_id"], $facts["course_version_id"]);
    }

    public function testReviewedTransferAcceptanceCanResolveAnExactPublicationMismatch(): void
    {
        $f = $this->institution();
        foreach ([1, 2, 4] as $i) {
            $this->work($f, $i);
        }
        $course = $f->f->groupDefinition["courses"][0];
        $different = $f->f->sis->publishCourse(new EntityId($course["course_id"]));
        $t = new \TransferAwardFixture($f->f->sis, $f->f->catalog->fixture->cipher, $f->student,
            course: new EntityId($course["course_id"]), courseVersion: $different);
        self::assertNotSame("feasible", $this->service($f)->preview($f->student, $f->assignment)["allocation"]["outcome"]);
        $review = $t->courses()->review($f->student, $t->award, new EntityId($course["version_id"]), 0,
            "2026-08-01", "Fictional receiving-course evaluation.");
        $t->courses()->commit($f->student, $t->award, $review["review_token"]);
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("feasible", $result["allocation"]["outcome"]);
        self::assertSame(12000, $result["minimums"][0]["actual_minimum_milli"]);
    }
    public function testInstitutionalAndOverallGpaUseDifferentVerifiedTransferScopes(): void
    {
        $f = $this->institution();
        $this->work($f);
        $s = $f->f->sis;
        $t = new TransferAwardFixture($s, $f->f->catalog->fixture->cipher, $f->student);
        $change = new AwardChange("amend", 3000, "B", "Fictional accepted GPA-eligible transfer grade.");
        $plan = $t->service()->preview($f->student, $t->award, $change);
        $t->service()->confirm($f->student, $t->award, EntityId::generate(), $change, $plan["revision"]);
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame(4000, $result["gpa"]["cumulative"]["milli"]);
        self::assertSame(3500, $result["gpa"]["overall"]["milli"]);
        self::assertSame(3000, $result["gpa"]["cumulative"]["credits_milli"]);
        self::assertSame(6000, $result["gpa"]["overall"]["credits_milli"]);
        $credit = array_values(array_filter($result["records"], fn($r) => $r["id"] === $t->credit->value))[0];
        self::assertSame("transfer_excluded", $credit["cumulative_gpa"]);
        self::assertSame("included", $credit["overall_gpa"]);
    }
    public function testUnusedCompletedCourseStillContributesToTheIndependentEarnedTotal(): void
    {
        $f = $this->institution();
        foreach ([0, 1, 2, 4] as $i) {
            $this->work($f, $i);
        }
        $outside = EntityId::generate();
        $f->f->sis->registration->addCourse(new \Domain\Catalog\Course($outside, "ART.100", "Fictional elective", 3000));
        $publication = $f->f->catalog->publications();
        $review = $publication->review($outside, "Fictional elective publication.");
        $version = $publication->publish($outside, $review["review_token"]);
        $this->work($f, 5, course: ["course_id" => $outside->value, "version_id" => $version["operation_id"]]);
        $events = $f->f->sis->sql->rows("sis_history_events");
        $r = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("feasible", $r["allocation"]["outcome"]);
        self::assertSame(1, $r["work_summary"]["unused_completed_count"]);
        $unused = array_values(array_filter($r["records"], fn($w) => $w["academic_state"] === "completed" && $w["placement"] === "not_used"));
        self::assertCount(1, $unused);
        self::assertSame("included", $unused[0]["credit"]);
        self::assertSame("included", $unused[0]["overall_gpa"]);
        self::assertSame(15000, $r["minimums"][0]["actual_minimum_milli"]);
        self::assertSame($events, $f->f->sis->sql->rows("sis_history_events"));
    }
    public function testPendingFinalGradeRefreshesWithoutTreatingFailureAsPendingOrMutatingTheCapturedReview(): void
    {
        $f = $this->institution();
        [$section, $enrollment, $credit, $choices] = $this->work($f, 0, null);
        $this->work($f, 1, "F");
        $service = $this->service($f);
        $before = $service->preview($f->student, $f->assignment);
        self::assertSame(1, $before["work_summary"]["awaiting_grade_count"]);
        self::assertSame(3000, $before["work_summary"]["awaiting_grade_milli"]);
        self::assertNull($before["work_summary"]["unused_completed_count"]);
        self::assertSame(0, $before["minimums"][0]["actual_minimum_milli"]);
        $ledger = $f->f->sis->academicCredits();
        $review = $ledger->review($f->student, $section->id, $enrollment, new EntityId($choices["A"]), 0);
        $ledger->commit($f->student, $section->id, $review["review_token"]);
        $after = $service->preview($f->student, $f->assignment);
        self::assertSame(0, $after["work_summary"]["awaiting_grade_count"]);
        self::assertSame(0, $after["work_summary"]["awaiting_grade_milli"]);
        self::assertSame(3000, $after["minimums"][0]["actual_minimum_milli"]);
        $record = array_column($after["records"], null, "id")[$credit->value];
        self::assertSame("completed", $record["academic_state"]);
        self::assertSame("not_established", $record["placement"]);
        self::assertSame(3000, $before["work_summary"]["awaiting_grade_milli"]);
    }
    public function testApprovedGeneralMinimumReductionPreservesThePublishedThresholdAndSelectedVersion(): void
    {
        $f = $this->institution();
        foreach ([0, 1, 2, 4] as $i) {
            $this->work($f, $i, "B");
        }
        $service = $this->service($f);
        self::assertSame(
            "below_minimum",
            $service->preview($f->student, $f->assignment)["minimums"][2]["status"],
        );
        $adjustments = $this->adjustments($f);
        foreach (["min_cumulative_gpa_milli", "min_overall_gpa_milli"] as $key) {
            $r = $adjustments->reviewGrant(
                $f->student,
                $f->assignment,
                ["kind" => "minimum", "group_id" => null, "minimum" => $key, "required_milli" => 3000],
                "Fictional reviewed GPA minimum reduction.",
            );
            $adjustments->commit($f->student, $f->assignment, $r["review_token"]);
        }
        $selected = $f->version->value;
        $row = $f->f->degrees->view($f->f->degree);
        $d = $row["definition"];
        $d["min_cumulative_gpa_milli"] = 3750;
        $f->f->degrees->save($f->f->degree, $row["revision"], new AuditRequirementsV3($d));
        $f->f->publishDegree();
        $result = $service->preview($f->student, $f->assignment);
        self::assertSame($selected, $result["context"]["requirements_version_id"]);
        self::assertSame("satisfied", $result["minimums"][2]["status"]);
        self::assertSame(3500, $result["minimums"][2]["published_milli"]);
        self::assertSame(3000, $result["minimums"][2]["required_milli"]);
        self::assertTrue($result["minimums"][2]["adjusted"]);
        self::assertSame("feasible", $result["allocation"]["outcome"]);
    }
    public function testCorrectedGradeRefreshesCurrentChecksWithoutChangingAPreviousReturnedReview(): void
    {
        $f = $this->institution();
        [$section, $enrollment, , $choices] = $this->work($f);
        $service = $this->service($f);
        $original = $service->preview($f->student, $f->assignment);
        $ledger = $f->f->sis->academicCredits();
        $r = $ledger->review(
            $f->student,
            $section->id,
            $enrollment,
            new EntityId($choices["F"]),
            1,
            reason: "Fictional corrected assessment.",
        );
        $ledger->commit($f->student, $section->id, $r["review_token"]);
        $current = $service->preview($f->student, $f->assignment);
        self::assertSame(4000, $original["gpa"]["cumulative"]["milli"]);
        self::assertSame(0, $current["gpa"]["cumulative"]["milli"]);
        self::assertSame("below_minimum", $current["minimums"][2]["status"]);
    }
    public function testNoGpaDenominatorAndEarlyRemovalCannotBecomeAFakeZeroOrAVisibleRemovedRecord(): void
    {
        $f = $this->institution();
        [$section, $enrollment, $credit] = $this->work($f, grade: null);
        $s = $f->f->sis;
        $r = $s->drops->review($f->student, $section->id);
        $s->drops->commit(
            $f->student,
            $section->id,
            new EntityId($r["enrollment_id"]),
            $r["version"],
            new EntityId($r["operation_id"]),
            $r["revision"],
            $r["effective_on"],
        );
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame(0, $result["current_record_count"]);
        self::assertSame([], $result["records"]);
        self::assertSame(0, $result["work_summary"]["awaiting_grade_count"]);
        self::assertTrue($result["gpa"]["cumulative"]["ready"]);
        self::assertNull($result["gpa"]["cumulative"]["milli"]);
        self::assertSame("unknown", $result["minimums"][2]["status"]);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($enrollment->value, $json);
        self::assertStringNotContainsString($credit->value, $json);
    }
    public function testReadBudgetFailureReturnsNoPartialChecksWhileASearchLimitKeepsIndependentChecksExplicit(): void
    {
        $f = $this->institution();
        foreach ([0, 1, 2, 4] as $i) {
            $this->work($f, $i);
        }
        self::assertSame(
            ["status" => "limited"],
            $this->service($f)->preview($f->student, $f->assignment, new HistoryReadBudget(maximumPages: 2)),
        );
        $result = $this->service($f, steps: 1)->preview($f->student, $f->assignment);
        self::assertSame("review", $result["status"]);
        self::assertSame("undetermined", $result["allocation"]["outcome"]);
        self::assertNull($result["work_summary"]["unused_completed_count"]);
        self::assertSame(["not_established"], array_values(array_unique(array_column($result["records"], "placement"))));
        self::assertSame("satisfied", $result["minimums"][0]["status"]);
        self::assertSame([], $result["allocation"]["groups"][0]["placements"]);
    }
    public function testDamagedCurrentRecordCannotProducePartialMinimums(): void
    {
        $f = $this->institution();
        [, , $credit] = $this->work($f);
        $f->f->sis->sql->execute("DELETE FROM sis_academic_credits WHERE id = ?", [$credit->value]);
        self::assertSame(
            ["status" => "inconsistent"],
            $this->service($f)->preview($f->student, $f->assignment),
        );
    }
    public function testOwnerOfficeAndPursuitScopeAreCheckedBeforeCollectionAndRevocationStopsTheRead(): void
    {
        $f = $this->institution();
        $s = $f->f->sis;
        $s->access->staff = false;
        self::assertSame("review", $this->service($f)->preview($f->student, $f->assignment)["status"]);
        $s->access->actor = $s->user();
        $s->access->grants = ["sis:student:read"];
        try {
            $this->service($f)->preview($f->student, EntityId::generate());
            self::fail("Foreign pursuit accepted.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $repository = new class ($s) implements AcademicInputRepository {
            public function __construct(private \SisFixture $s) {}
            public function inventory(EntityId $student): AcademicInputInventory
            {
                $inventory = new SqlAcademicInputRepository($this->s->sql)->inventory($student);
                $this->s->access->grants = [];
                return $inventory;
            }
        };
        $this->expectException(AccessDeniedException::class);
        $this->service($f, $repository)->preview($f->student, $f->assignment);
    }
    public function testMissingSelectionShowsSetupInsteadOfComparingCurrentInstallationDefaults(): void
    {
        $f = new StudentRequirementsFixture();
        self::assertSame(
            ["status" => "needs_selection"],
            $this->service($f)->preview($f->student, $f->assignment),
        );
    }
}
