<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\Service\DegreeWorkReview;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use PHPUnit\Framework\TestCase;

final class DegreeWorkReviewTest extends TestCase
{
    private static function id(int $n): string
    {
        return str_pad((string) $n, 26, "0", STR_PAD_LEFT);
    }

    private function work(int $id, string $origin = "institutional"): array
    {
        return [
            "id" => self::id($id),
            "course_id" => self::id(100),
            "credit_type" => $origin,
            "status" => "A",
            "attempted_milli" => 3000,
            "completed_milli" => 3000,
            "quality_points_milli" => 12000,
            "grade_recorded" => true,
            "completed_on" => null,
            "grade" => [
                "id" => self::id(400),
                "code" => "A",
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
                "scale_id" => self::id(401),
                "scale_version_id" => self::id(402),
            ],
            "term_id" => self::id(200),
            "term_code" => "26/FA",
            "term_label_retained" => true,
        ];
    }

    private function pending(int $id, int $credits = 3000): array
    {
        return array_replace($this->work($id), [
            "grade" => null,
            "grade_recorded" => false,
            "attempted_milli" => $credits,
            "completed_milli" => 0,
            "quality_points_milli" => 0,
        ]);
    }

    private function allocation(array $uses, string $outcome = "feasible"): array
    {
        $records = [];
        foreach ($uses as $id => $alternatives) {
            $records[] = [
                "id" => self::id($id),
                "uses" => array_map(
                    fn($a) => ["group_id" => self::id(300), "alternative" => $a],
                    $alternatives,
                ),
            ];
        }
        return ["outcome" => $outcome, "records" => $records];
    }

    public function testCompletedUnusedWorkKeepsPlacementSeparateFromActualAwardOrGpaSelection(): void
    {
        $work = array_map($this->work(...), [1, 2, 3, 4]);
        $result = new DegreeWorkReview()->review(
            $work,
            $this->allocation([1 => [false], 2 => [true], 3 => [], 4 => [true, false]]),
        );
        self::assertSame(1, $result["summary"]["unused_completed_count"]);
        self::assertSame("not_used", $result["records"][self::id(3)]["placement"]);
        self::assertSame("alternative", $result["records"][self::id(2)]["placement"]);
        self::assertSame("proposed", $result["records"][self::id(4)]["placement"]);
        self::assertSame("completed", $result["records"][self::id(3)]["academic_state"]);
        self::assertArrayNotHasKey("earned_milli", $result["summary"]);
        self::assertArrayNotHasKey("gpa", $result["summary"]);
    }

    public function testNoIncompleteOrUnknownPlacementCanDeclareCoursesUnused(): void
    {
        foreach (["infeasible", "undetermined", "needs_review", "unsupported"] as $outcome) {
            $r = new DegreeWorkReview()->review(
                [$this->work(1)],
                $this->allocation([1 => [false]], $outcome),
            );
            self::assertFalse($r["summary"]["placement_established"]);
            self::assertNull($r["summary"]["unused_completed_count"]);
            self::assertSame("not_established", $r["records"][self::id(1)]["placement"]);
            self::assertSame([], $r["records"][self::id(1)]["uses"]);
        }
    }

    public function testPendingCreditsAreRecordedInstitutionalAttemptsAcrossTermsWithoutGuessingTransferOrUnknownGrades(): void
    {
        $older = array_replace($this->pending(1, 1500), [
            "term_code" => null,
            "term_label_retained" => false,
        ]);
        $newer = array_replace($this->pending(2, 2250), ["term_id" => self::id(201), "term_code" => "27/SP"]);
        $unknown = array_replace($this->work(3), ["grade" => null]);
        $transfer = array_replace($this->pending(4), ["credit_type" => "transfer"]);
        $r = new DegreeWorkReview()->review(
            [$older, $newer, $unknown, $transfer],
            $this->allocation([], "needs_review"),
        );
        self::assertSame(2, $r["summary"]["awaiting_grade_count"]);
        self::assertSame(3750, $r["summary"]["awaiting_grade_milli"]);
        self::assertSame("grade_unknown", $r["records"][self::id(3)]["academic_state"]);
        self::assertSame("grade_unknown", $r["records"][self::id(4)]["academic_state"]);
        self::assertNull($r["records"][self::id(1)]["term_code"]);
        self::assertFalse($r["records"][self::id(1)]["term_label_retained"]);
    }

    public function testLateDropsVoidsAndFailingOrZeroCreditWorkAreNotPendingOrUnusedPassingWork(): void
    {
        $drop = array_replace($this->pending(1), ["status" => "W"]);
        $void = array_replace($this->pending(2), ["status" => "V", "credit_type" => "transfer"]);
        $fail = $this->work(3);
        $fail["grade"] = array_replace($fail["grade"], [
            "code" => "F",
            "points_milli" => 0,
            "passes" => false,
        ]);
        $fail["completed_milli"] = $fail["quality_points_milli"] = 0;
        $zero = $this->work(4);
        $zero["attempted_milli"] = $zero["completed_milli"] = $zero["quality_points_milli"] = 0;
        $r = new DegreeWorkReview()->review(
            [$drop, $void, $fail, $zero],
            $this->allocation([1 => [], 2 => [], 3 => [], 4 => []]),
        );
        self::assertSame(0, $r["summary"]["awaiting_grade_count"]);
        self::assertSame(1, $r["summary"]["unused_completed_count"]);
        self::assertSame(
            ["late_drop", "voided", "not_passing", "completed"],
            array_column($r["records"], "academic_state"),
        );
    }

    public function testCuratedEvidencePreservesActualGradePointsAndOmitsGradeAndScaleIdentities(): void
    {
        $work = $this->work(1);
        $work["completed_on"] = "2026-12-15";
        $work["private"] = "fictional-sensitive-source";
        $evidence = \Domain\DegreeAudit\ValueObject\AcademicWorkEvidence::fromAttempt(
            \Domain\DegreeAudit\ValueObject\PolicyAttempt::fromAcademicWork($work),
        );
        self::assertSame("institutional", $evidence["credit_type"]);
        self::assertSame("2026-12-15", $evidence["completed_on"]);
        self::assertSame(3000, $evidence["recorded_earned_milli"]);
        self::assertSame("retained", $evidence["grade_state"]);
        self::assertSame(
            ["code" => "A", "points_milli" => 4000, "grading_publication_retained" => true],
            $evidence["retained_grade"],
        );
        $serialized = json_encode($evidence, JSON_THROW_ON_ERROR);
        $privateValues = [self::id(400), self::id(401), self::id(402), "fictional-sensitive-source"];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $serialized);
        }
        $work["grade"]["scale_id"] = $work["grade"]["scale_version_id"] = null;
        $evidence = \Domain\DegreeAudit\ValueObject\AcademicWorkEvidence::fromAttempt(
            \Domain\DegreeAudit\ValueObject\PolicyAttempt::fromAcademicWork($work),
        );
        self::assertFalse($evidence["retained_grade"]["grading_publication_retained"]);
        self::assertSame(4000, $evidence["retained_grade"]["points_milli"]);
        self::assertArrayNotHasKey("maximum_milli", $evidence["retained_grade"]);
    }

    public function testMissingAndZeroGradesKeepDistinctEvidenceWithoutInferringPendingTransferWork(): void
    {
        $unknown = array_replace($this->work(2), ["grade" => null]);
        $unexplained = array_replace($this->pending(3), ["quality_points_milli" => 12000]);
        $transfer = array_replace($this->pending(4), ["credit_type" => "transfer"]);
        $drop = array_replace($this->pending(5), ["status" => "W"]);
        $fail = $this->work(6);
        $fail["completed_milli"] = $fail["quality_points_milli"] = 0;
        $fail["grade"] = array_replace($fail["grade"], [
            "code" => "F",
            "points_milli" => 0,
            "passes" => false,
        ]);
        $result = new DegreeWorkReview()->review(
            [$this->pending(1), $unknown, $unexplained, $transfer, $drop, $fail],
            $this->allocation([], "needs_review"),
        );
        self::assertSame(
            ["awaiting_grade", "unknown", "unknown", "unknown", "unknown", "retained"],
            array_column($result["records"], "grade_state"),
        );
        self::assertSame(0, $result["records"][self::id(6)]["retained_grade"]["points_milli"]);
        self::assertSame("F", $result["records"][self::id(6)]["retained_grade"]["code"]);
        self::assertNull($result["records"][self::id(2)]["retained_grade"]);
    }
    public function testPendingArithmeticLimitRetainsTheCountButNeverReturnsAPartialOrZeroTotal(): void
    {
        $r = new DegreeWorkReview()->review(
            [$this->pending(1, 2147483646), $this->pending(2), $this->pending(3)],
            $this->allocation([], "infeasible"),
        );
        self::assertSame(3, $r["summary"]["awaiting_grade_count"]);
        self::assertNull($r["summary"]["awaiting_grade_milli"]);
    }

    public function testAnEarlyRemovedRecordCannotEscapeThroughTheNewReview(): void
    {
        $this->expectException(CorruptHistoryException::class);
        new DegreeWorkReview()->review(
            [array_replace($this->pending(1), ["status" => "D"])],
            $this->allocation([], "infeasible"),
        );
    }

    public function testIncompletePlacementCoverageOrDuplicateCurrentWorkIsRejected(): void
    {
        foreach ([[$this->work(1), $this->work(2)], [$this->work(1), $this->work(1)]] as $work) {
            try {
                new DegreeWorkReview()->review($work, $this->allocation([1 => []]));
                self::fail("A mismatched work boundary was accepted.");
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }
}
