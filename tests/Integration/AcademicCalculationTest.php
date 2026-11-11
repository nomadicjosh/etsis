<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";
require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";

use Domain\DegreeAudit\Repository\AcademicInputRepository;
use Domain\DegreeAudit\Service\AcademicCalculationService;
use Domain\DegreeAudit\Service\AcademicInputService;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\ValueObject\AcademicInputInventory;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAcademicInputRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;
use StudentRequirementsFixture;
use TransferAwardFixture;

final class AcademicCalculationTest extends TestCase
{
    private function service(
        StudentRequirementsFixture $f,
        ?AcademicInputRepository $repository = null,
    ): AcademicCalculationService {
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
        return new AcademicCalculationService(
            $inputs,
            $f->service,
            new AcademicPolicyEvaluator(),
            $students,
            $s->transactions,
        );
    }
    private function grades(): array
    {
        return [
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
                "code" => "TR",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => 0,
                "passes" => true,
                "counts_gpa" => false,
            ],
            [
                "code" => "F",
                "min_score_milli" => 0,
                "max_score_milli" => 79999,
                "points_milli" => 0,
                "passes" => false,
                "counts_gpa" => true,
            ],
        ];
    }
    private function institution(string $repeat = "all_eligible"): StudentRequirementsFixture
    {
        $f = new StudentRequirementsFixture();
        $f->f->sis->gradeScale($this->grades());
        $this->publish($f, $repeat);
        $f->assign();
        return $f;
    }
    private function publish(StudentRequirementsFixture $f, string $repeat = "all_eligible"): void
    {
        $d = $f->f->definition;
        $d["policies"]["repeat_gpa"] = $repeat;
        $row = $f->f->degrees->view($f->f->degree);
        $f->f->degrees->save($f->f->degree, $row["revision"], new AuditRequirementsV3($d));
        $f->version = new EntityId($f->f->publishDegree()["operation_id"]);
    }
    private function work(
        StudentRequirementsFixture $f,
        string $grade = "A",
        ?\Domain\Registration\Section $section = null,
    ): array {
        $s = $f->f->sis;
        $section ??= $s->section(faculty: $s->access->actor);
        $enrollment = $s->service->register($f->student, $section->id)->id;
        $ledger = $s->academicCredits();
        $choices = array_column(
            $ledger->choices($f->student, $section->id, $enrollment)["items"],
            "id",
            "code",
        );
        $review = $ledger->review($f->student, $section->id, $enrollment, new EntityId($choices[$grade]), 0);
        $ledger->commit($f->student, $section->id, $review["review_token"]);
        $credit = new EntityId(
            $s->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"],
        );
        return [$section, $enrollment, $credit, $choices];
    }
    public function testSelectedPoliciesDriveExactCurrentTotalsWithoutWritingOrUsingLaterPublications(): void
    {
        $f = $this->institution();
        $this->work($f);
        $selected = $f->version->value;
        $s = $f->f->sis;
        $this->publish($f, "highest_grade");
        $changedGrades = $this->grades();
        $changedGrades[0]["points_milli"] = 5000;
        $s->gradeScale($changedGrades);
        $events = $s->sql->rows("sis_history_events");
        $receipts = $s->sql->rows("sis_history_operations");
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("review", $result["status"]);
        self::assertSame($selected, $result["context"]["requirements_version_id"]);
        self::assertSame("all_eligible", $result["policy"]["repeat_gpa"]);
        self::assertSame(3000, $result["totals"]["earned_milli"]);
        self::assertSame(4000, $result["totals"]["gpa_milli"]);
        self::assertSame(12000, $result["totals"]["quality_points_milli"]);
        self::assertSame("included", $result["records"][0]["gpa"]);
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        self::assertSame($receipts, $s->sql->rows("sis_history_operations"));
        self::assertArrayNotHasKey("references", $result);
    }
    public function testRepeatsShareCourseIdentityAndMissingRetainedOrderingIsExplicit(): void
    {
        foreach (["all_eligible", "highest_grade", "latest_eligible"] as $repeat) {
            $f = $this->institution($repeat);
            [$first] = $this->work($f, "B");
            $section = new \Domain\Registration\Section(
                EntityId::generate(),
                $first->courseId,
                $first->termId,
                $f->f->sis->access->actor,
                "FICTIONAL.REPEAT",
                20,
                true,
                true,
                new \Domain\Finance\ValueObject\Money(0, "USD"),
            );
            $f->f->sis->registration->addSection($section);
            $this->work($f, "A", $section);
            $result = $this->service($f)->preview($f->student, $f->assignment);
            self::assertSame(3000, $result["totals"]["earned_milli"]);
            self::assertSame(
                match ($repeat) {
                    "all_eligible" => 3500,
                    "highest_grade" => 4000,
                    default => null,
                },
                $result["totals"]["gpa_milli"],
            );
            if ($repeat === "latest_eligible") {
                self::assertFalse($result["totals"]["gpa_ready"]);
                self::assertNull($result["policy"]["attempt_order"]);
                self::assertContains(
                    ["component" => "gpa", "code" => "ordering_policy_unknown"],
                    $result["issues"],
                );
            }
        }
    }
    public function testNoGpaCreditsAndAnFOnlyGpaRemainDifferent(): void
    {
        $f = $this->institution();
        $service = $this->service($f);
        $empty = $service->preview($f->student, $f->assignment);
        self::assertTrue($empty["totals"]["gpa_ready"]);
        self::assertSame(0, $empty["totals"]["gpa_credits_milli"]);
        self::assertNull($empty["totals"]["gpa_milli"]);
        $this->work($f, "F");
        $failed = $service->preview($f->student, $f->assignment);
        self::assertSame(0, $failed["totals"]["gpa_milli"]);
        self::assertSame(3000, $failed["totals"]["gpa_credits_milli"]);
        self::assertSame(0, $failed["totals"]["earned_milli"]);
    }
    public function testDifferentGradingPublicationBlocksTheGpaButNotKnownCredits(): void
    {
        $f = $this->institution();
        $s = $f->f->sis;
        $s->gradeScale($this->grades());
        $this->work($f);
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame(3000, $result["totals"]["earned_milli"]);
        self::assertFalse($result["totals"]["gpa_ready"]);
        self::assertNull($result["totals"]["gpa_milli"]);
        self::assertNull($result["totals"]["gpa_credits_milli"]);
        self::assertSame([["component" => "gpa", "code" => "grading_policy_differs"]], $result["issues"]);
    }
    public function testCorrectionsRefreshCurrentTotalsButDoNotMutateEarlierReturnedResults(): void
    {
        $f = $this->institution();
        [$section, $enrollment, , $choices] = $this->work($f);
        $service = $this->service($f);
        $original = $service->preview($f->student, $f->assignment);
        $ledger = $f->f->sis->academicCredits();
        $review = $ledger->review(
            $f->student,
            $section->id,
            $enrollment,
            new EntityId($choices["B"]),
            1,
            reason: "Fictional grade correction.",
        );
        $ledger->commit($f->student, $section->id, $review["review_token"]);
        self::assertSame(3000, $service->preview($f->student, $f->assignment)["totals"]["gpa_milli"]);
        self::assertSame(4000, $original["totals"]["gpa_milli"]);
    }
    public function testMissingSelectionDoesNotGuessCurrentPoliciesOrCalculateAnEmptyGpa(): void
    {
        $f = new StudentRequirementsFixture();
        self::assertSame(
            ["status" => "needs_selection"],
            $this->service($f)->preview($f->student, $f->assignment),
        );
    }
    public function testReplayBudgetIncludesSelectionPublicationAndAcademicInputsWithNoPartialResults(): void
    {
        $f = $this->institution();
        $this->work($f);
        // Two pages cover selection and degree, but cannot cover both academic and registration streams.
        $result = $this->service($f)->preview(
            $f->student,
            $f->assignment,
            new HistoryReadBudget(maximumPages: 2),
        );
        self::assertSame(["status" => "limited"], $result);
        self::assertSame("review", $this->service($f)->preview($f->student, $f->assignment)["status"]);
    }
    public function testMissingCurrentFactsAndDamagedHistoryReturnNoPartialTotals(): void
    {
        foreach (["projection", "journal"] as $kind) {
            $f = $this->institution();
            [, , $credit] = $this->work($f);
            $s = $f->f->sis;
            if ($kind === "projection") {
                $s->sql->execute("DELETE FROM sis_academic_credits WHERE id = ?", [$credit->value]);
            } else {
                $head = $s->sql->one("sis_history_streams", [
                    "stream_type" => "academic_credit",
                    "aggregate_id" => $credit->value,
                ]);
                $s->sql->update(
                    "sis_history_events",
                    ["event_hash" => str_repeat("0", 64)],
                    ["stream_id" => $head["id"]],
                );
            }
            self::assertSame(
                ["status" => "inconsistent"],
                $this->service($f)->preview($f->student, $f->assignment),
            );
        }
    }
    public function testEarlyRemovedRecordsStayOutOfThePreviewAndItsCounts(): void
    {
        $f = $this->institution();
        $s = $f->f->sis;
        $section = $s->section();
        $enrollment = $s->service->register($f->student, $section->id)->id;
        $credit = new EntityId(
            $s->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"],
        );
        $review = $s->drops->review($f->student, $section->id);
        $s->drops->commit(
            $f->student,
            $section->id,
            new EntityId($review["enrollment_id"]),
            $review["version"],
            new EntityId($review["operation_id"]),
            $review["revision"],
            $review["effective_on"],
        );
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame(0, $result["current_record_count"]);
        self::assertSame([], $result["records"]);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($enrollment->value, $json);
        self::assertStringNotContainsString($credit->value, $json);
    }
    public function testTransferExclusionUsesTheRetainedPolicyAndDoesNotAssumeACompatibleGradeScale(): void
    {
        $f = $this->institution();
        new TransferAwardFixture($f->f->sis, $f->f->catalog->fixture->cipher, $f->student);
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame(3000, $result["totals"]["earned_milli"]);
        self::assertSame(0, $result["totals"]["institutional_maximum_milli"]);
        self::assertSame("transfer_excluded", $result["records"][0]["gpa"]);
        self::assertTrue($result["totals"]["gpa_ready"]);
        self::assertNull($result["totals"]["gpa_milli"]);
    }
    public function testUnknownEarlierTransferGradePreventsAnEarnedTotalWithoutInventingHistory(): void
    {
        $f = $this->institution();
        new TransferAwardFixture($f->f->sis, $f->f->catalog->fixture->cipher, $f->student, legacy: true);
        $before = $f->f->sis->sql->rows("sis_history_events");
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("history_gaps", $result["input_status"]);
        self::assertFalse($result["totals"]["credit_ready"]);
        self::assertNull($result["totals"]["earned_milli"]);
        self::assertSame("grade_unknown", $result["records"][0]["credit"]);
        self::assertSame($before, $f->f->sis->sql->rows("sis_history_events"));
    }
    public function testPolicyEnvelopeDoesNotReadUnneededCourseOrGroupHistoriesAndIsBudgeted(): void
    {
        $f = $this->institution();
        $store = $this->createMock(HistoricalEventStore::class);
        $types = [];
        $store
            ->expects(self::atLeastOnce())
            ->method("page")
            ->willReturnCallback(function ($stream, $cursor, $limit) use ($f, &$types) {
                $types[] = $stream->type;
                return $f->f->history->page($stream, $cursor, $limit);
            });
        $reader = new PublishedRequirementsReader($store);
        $publication = $reader->calculationVersion($f->f->degree, $f->version, new HistoryReadBudget());
        self::assertSame([], $publication["group_details"]);
        self::assertSame("all_eligible", $publication["definition"]["policies"]["repeat_gpa"]);
        self::assertNotContains("course_group_publication", $types);
        self::assertNotContains("course_publication", $types);
        self::assertContains("degree_audit_requirements", $types);
    }
    public function testOwnerAndOfficeChecksRejectAnUnrelatedUserOrAnotherPursuit(): void
    {
        $f = $this->institution();
        $s = $f->f->sis;
        $s->access->staff = false;
        self::assertSame("review", $this->service($f)->preview($f->student, $f->assignment)["status"]);
        $s->access->actor = $s->user();
        $s->access->grants = ["sis:student:read"];
        self::assertSame("review", $this->service($f)->preview($f->student, $f->assignment)["status"]);
        try {
            $this->service($f)->preview($f->student, EntityId::generate());
            self::fail("Another pursuit was accepted.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $s->access->grants = [];
        $this->expectException(AccessDeniedException::class);
        $this->service($f)->preview($f->student, $f->assignment);
    }
    public function testAuthorityRevokedDuringInputCollectionCannotReturnTotals(): void
    {
        $f = $this->institution();
        $s = $f->f->sis;
        $s->access->staff = false;
        $s->access->actor = $s->user();
        $s->access->grants = ["sis:student:read"];
        $repository = new class ($s) implements AcademicInputRepository {
            public function __construct(private \SisFixture $s) {}
            public function inventory(EntityId $student): AcademicInputInventory
            {
                $result = new SqlAcademicInputRepository($this->s->sql)->inventory($student);
                $this->s->access->grants = [];
                return $result;
            }
        };
        $this->expectException(AccessDeniedException::class);
        $this->service($f, $repository)->preview($f->student, $f->assignment);
    }
}
