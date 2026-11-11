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
            new \Domain\DegreeAudit\Service\RetainedAttemptReader(
                new \Domain\Registration\Service\RetainedSectionReader($f->f->history),
            ),
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
    private function institution(
        string $repeat = "all_eligible",
        string $order = "academic_period",
    ): StudentRequirementsFixture {
        $f = new StudentRequirementsFixture();
        $f->f->sis->gradeScale($this->grades());
        $this->publish($f, $repeat, $order);
        $f->assign();
        return $f;
    }
    private function publish(
        StudentRequirementsFixture $f,
        string $repeat = "all_eligible",
        string $order = "academic_period",
    ): void {
        $d = $f->f->definition;
        $d["policies"]["repeat_gpa"] = $repeat;
        $d["policies"]["repeat_attempt_order"] = $order;
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
    public function testRepeatsShareCourseIdentityAndMissingRetainedDatesAreExplicit(): void
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
                self::assertSame("academic_period", $result["policy"]["attempt_order"]);
                self::assertContains(
                    ["component" => "gpa", "code" => "chronology_unknown"],
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
    private function datedOffering(StudentRequirementsFixture $f, string $start, string $end): array
    {
        $s = $f->f->sis;
        $course = $f->f->groupDefinition["courses"][0];
        $section = new \Domain\Registration\Section(
            EntityId::generate(),
            new EntityId($course["course_id"]),
            $s->term,
            $s->access->actor,
            "FICTIONAL." . $start,
            20,
            true,
            true,
            new \Domain\Finance\ValueObject\Money(0, "USD"),
        );
        $s->registration->addSection($section);
        $bindings = $f->f->catalog->bindings();
        $binding = $bindings->review(
            $section->id,
            new EntityId($course["version_id"]),
            "Fictional offering definition.",
        );
        $bindings->commit($section->id, $binding["review_token"]);
        $publisher = $f->f->catalog->sectionPublications();
        $review = $publisher->review($section->id, $start, $end, "Fictional instructional dates.");
        $version = new EntityId($publisher->publish($section->id, $review["review_token"])["operation_id"]);
        $selection = $publisher->reviewSelection($section->id, $version, "Fictional initial offering.");
        $publisher->select($section->id, $selection["review_token"]);
        return [$section, $version];
    }

    public function testLatestGpaUsesRetainedInstructionalDatesDespiteLaterCorrectionsAndOfferingChanges(): void
    {
        $f = $this->institution("latest_eligible");
        [$older, $version] = $this->datedOffering($f, "2026-08-24", "2026-09-30");
        [$newer] = $this->datedOffering($f, "2026-10-01", "2026-12-18");
        [, $oldEnrollment, , $grades] = $this->work($f, "A", $older);
        $this->work($f, "B", $newer);
        $service = $this->service($f);
        $result = $service->preview($f->student, $f->assignment);
        self::assertSame(3000, $result["totals"]["gpa_milli"]);
        self::assertEqualsCanonicalizing(
            ["2026-08-24", "2026-10-01"],
            array_column($result["records"], "ordering_on"),
        );
        $s = $f->f->sis;
        $s->clock->time = $s->clock->time->modify("+2 days");
        $ledger = $s->academicCredits();
        $correction = $ledger->review(
            $f->student,
            $older->id,
            $oldEnrollment,
            new EntityId($grades["F"]),
            1,
            reason: "Fictional older-period correction.",
        );
        $ledger->commit($f->student, $older->id, $correction["review_token"]);
        $publisher = $f->f->catalog->sectionPublications();
        $review = $publisher->review(
            $older->id,
            "2026-11-01",
            "2026-12-18",
            "Fictional future offering revision.",
        );
        $publisher->publish($older->id, $review["review_token"]);
        $s->sql->update("sis_terms", ["starts_on" => "2026-11-01"], ["id" => $s->term->value]);
        $before = $s->sql->rows("sis_history_events");
        self::assertSame(3000, $service->preview($f->student, $f->assignment)["totals"]["gpa_milli"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame(
            ["status" => "limited"],
            $service->preview($f->student, $f->assignment, new HistoryReadBudget(maximumPages: 6)),
        );
        $stream = $s->sql->one("sis_history_streams", [
            "stream_type" => "section_publication",
            "aggregate_id" => $older->id->value,
        ]);
        $s->sql->update(
            "sis_history_events",
            ["event_hash" => str_repeat("0", 64)],
            ["stream_id" => $stream["id"]],
        );
        self::assertSame(["status" => "inconsistent"], $service->preview($f->student, $f->assignment));
    }

    public function testSectionReplayIsSharedWithinTheReadButNotCachedBetweenReads(): void
    {
        $f = $this->institution("latest_eligible");
        [$section, $version] = $this->datedOffering($f, "2026-08-24", "2026-12-18");
        $store = $this->createMock(HistoricalEventStore::class);
        $store
            ->expects(self::exactly(2))
            ->method("page")
            ->willReturnCallback(
                fn($stream, $cursor, $limit) => $f->f->history->page($stream, $cursor, $limit),
            );
        $reader = new \Domain\Registration\Service\RetainedSectionReader($store);
        $ref = ["section_id" => $section->id->value, "version_id" => $version->value];
        for ($i = 0; $i < 2; $i++) {
            $result = $reader->periods([$ref, $ref], new HistoryReadBudget());
            self::assertSame("2026-08-24", $result[$section->id->value][$version->value]["starts_on"]);
        }
    }

    public function testCompletionOrderingDoesNotSubstituteInstructionalDatesAndLaterPoliciesDoNotReplaceSelection(): void
    {
        $f = $this->institution("latest_eligible", "completion_date");
        [$first] = $this->datedOffering($f, "2026-08-24", "2026-09-30");
        [$second] = $this->datedOffering($f, "2026-10-01", "2026-12-18");
        $this->work($f, "A", $first);
        $this->work($f, "B", $second);
        $this->publish($f, "latest_eligible", "academic_period");
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("completion_date", $result["policy"]["attempt_order"]);
        self::assertFalse($result["totals"]["gpa_ready"]);
        self::assertSame([null, null], array_column($result["records"], "ordering_on"));
        self::assertContains(["component" => "gpa", "code" => "chronology_unknown"], $result["issues"]);
    }

    public function testActualCompletionCorrectionsDriveRetainedOrderingWithoutUsingGradePostingTime(): void
    {
        $f = $this->institution("latest_eligible", "completion_date");
        [$first] = $this->datedOffering($f, "2026-08-24", "2026-09-30");
        [$second] = $this->datedOffering($f, "2026-10-01", "2026-12-18");
        [, $oldEnrollment, , $grades] = $this->work($f, "A", $first);
        [, $newEnrollment] = $this->work($f, "B", $second);
        $s = $f->f->sis;
        $s->clock->time = new \DateTimeImmutable("2027-01-10T10:00:00-07:00");
        $completion = $s->academicCompletions();
        foreach ([[$first, $oldEnrollment, "2026-11-20"], [$second, $newEnrollment, "2026-12-20"]] as [$section, $enrollment, $date]) {
            $review = $completion->review($f->student, $section->id, $enrollment, $date, 1, "Fictional confirmed actual completion.");
            $completion->commit($f->student, $section->id, $review["review_token"]);
        }
        $calculation = $this->service($f);
        $original = $calculation->preview($f->student, $f->assignment);
        self::assertSame(3000, $original["totals"]["gpa_milli"]);
        $grade = $s->academicCredits()->review($f->student, $first->id, $oldEnrollment, new EntityId($grades["F"]), 2, reason: "Fictional later grade correction.");
        $s->academicCredits()->commit($f->student, $first->id, $grade["review_token"]);
        self::assertSame(3000, $calculation->preview($f->student, $f->assignment)["totals"]["gpa_milli"]);
        $changed = $completion->review($f->student, $first->id, $oldEnrollment, "2027-01-02", 3, "Fictional source completion correction.");
        $completion->commit($f->student, $first->id, $changed["review_token"]);
        $after = $calculation->preview($f->student, $f->assignment);
        self::assertSame(0, $after["totals"]["gpa_milli"]);
        self::assertSame(3000, $after["totals"]["earned_milli"]);
        self::assertSame(3000, $original["totals"]["gpa_milli"]);
        $clear = $completion->review($f->student, $first->id, $oldEnrollment, null, 4, "Fictional completion evidence withdrawn.");
        $completion->commit($f->student, $first->id, $clear["review_token"]);
        self::assertFalse($calculation->preview($f->student, $f->assignment)["totals"]["gpa_ready"]);
    }

    public function testNewLatestPublicationRequiresAnOrderingChoiceWithoutChangingEarlierRecordedFacts(): void
    {
        $f = new StudentRequirementsFixture();
        $degree = $f->f->degrees;
        $row = $degree->view($f->f->degree);
        $d = $row["definition"];
        unset($d["policies"]["repeat_attempt_order"]);
        $d["policies"]["repeat_gpa"] = "latest_eligible";
        $degree->save($f->f->degree, $row["revision"], new AuditRequirementsV3($d));
        $before = $f->f->sis->sql->rows("sis_history_events");
        try {
            $degree->review($f->f->degree, "Fictional missing-order review.");
            self::fail("A new latest-repeat publication omitted ordering.");
        } catch (\Domain\Shared\Exception\ValidationException $e) {
            self::assertSame("repeat_attempt_order", $e->errors[0]["field"]);
            self::assertStringContainsString("Choose how to order", $e->errors[0]["message"]);
        }
        self::assertSame($before, $f->f->sis->sql->rows("sis_history_events"));
        $old = new PublishedRequirementsReader($f->f->history)->version($f->f->degree, $f->version);
        self::assertArrayNotHasKey("repeat_attempt_order", $old["definition"]["policies"]);
    }
}
