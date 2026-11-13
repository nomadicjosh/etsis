<?php

declare(strict_types=1);

use Domain\Portal\ValueObject\StudentDegreeProgress;
use PHPUnit\Framework\TestCase;

final class StudentDegreeProgressTest extends TestCase
{
    private function review(string $outcome = "feasible"): array
    {
        $secret = "fictional-staff-only-detail";
        return [
            "status" => "review",
            "input_status" => "verified",
            "current_record_count" => 1,
            "actor" => $secret,
            "context" => [
                "name" => "Fictional degree",
                "academic_year_code" => "2026-2027",
                "version" => 1,
                "cohort_changed" => false,
                "requirements_version_id" => $secret,
            ],
            "minimums" => [
                [
                    "key" => "min_overall_gpa_milli",
                    "required_milli" => 3000,
                    "actual_minimum_milli" => null,
                    "actual_maximum_milli" => null,
                    "status" => "unknown",
                    "reason" => $secret,
                ],
            ],
            "gpa" => [
                "cumulative" => [
                    "ready" => true,
                    "milli" => null,
                    "credits_milli" => 0,
                    "quality_points_milli" => 0,
                    "reason" => $secret,
                ],
                "overall" => [
                    "ready" => false,
                    "milli" => null,
                    "credits_milli" => null,
                    "quality_points_milli" => null,
                ],
            ],
            "work_summary" => [
                "placement_established" => $outcome === "feasible",
                "unused_completed_count" => null,
                "awaiting_grade_count" => 1,
                "awaiting_grade_milli" => 3000,
                "reason" => $secret,
            ],
            "policy" => [
                "repeat_credit" => "highest_earned",
                "repeat_gpa" => "all_eligible",
                "transfer_overall_gpa" => "exclude",
                "attempt_order" => "academic_period",
                "private" => $secret,
            ],
            "allocation" => [
                "outcome" => $outcome,
                "groups" => [
                    [
                        "id" => $secret,
                        "name" => "Fictional group",
                        "version_id" => $secret,
                        "rules" => [$secret],
                        "coverage" => [
                            "applied_credits_milli" => 3000, "applied_courses" => 1, "reason" => $secret,
                            "rules" => [$secret => [
                                "mode" => "credits", "applied_credits_milli" => 3000, "applied_courses" => 1,
                                "covered_courses" => 1, "required_courses" => null, "required_credits_milli" => 3000,
                                "waived_courses" => 0, "rule_id" => $secret, "eligibility" => [$secret],
                            ]],
                        ],
                        "placements" => [
                            [
                                "course_id" => $secret,
                                "course_code" => "MATH.101",
                                "course_title" => "Fictional Algebra",
                                "credits_milli" => 3000,
                                "target_code" => "MATH.101",
                                "target_title" => "Algebra",
                                "via_substitution" => false,
                                "work_options" => [[$secret]],
                                "rule_credits_milli" => [$secret => 3000],
                            ],
                        ],
                    ],
                ],
            ],
            "records" => [
                [
                    "id" => $secret,
                    "term_id" => $secret,
                    "course_code" => "MATH.101",
                    "course_title" => "Fictional Algebra",
                    "attempted_milli" => 3000,
                    "term_code" => "26/FA",
                    "term_label_retained" => true,
                    "academic_state" => "awaiting_grade",
                    "placement" => "unplaced",
                    "credit" => "included",
                    "overall_gpa" => "undetermined",
                    "cumulative_gpa" => "excluded",
                    "grade_state" => "retained",
                    "credit_type" => "institutional",
                    "recorded_earned_milli" => 3000,
                    "completed_on" => "2026-12-15",
                    "retained_grade" => [
                        "code" => "A", "points_milli" => 4000, "grading_publication_retained" => true,
                        "id" => $secret, "scale_version_id" => $secret, "reason" => $secret,
                    ],
                    "uses" => [$secret],
                ],
            ],
        ];
    }
    public function testPortalSnapshotPreservesUnknownsWithoutLeakingStaffAndSourceReferences(): void
    {
        $view = new StudentDegreeProgress($this->review());
        $data = $view->describe();
        self::assertStringNotContainsString("fictional-staff-only-detail", json_encode($data));
        self::assertStringNotContainsString("rule_credits_milli", json_encode($data));
        self::assertNull($data["minimums"][0]["actual_minimum_milli"]);
        self::assertNull($data["gpa"]["cumulative"]["milli"]);
        self::assertSame(0, $data["gpa"]["cumulative"]["credits_milli"]);
        self::assertSame(
            "Fictional Algebra",
            $data["allocation"]["groups"][0]["placements"][0]["course_title"],
        );
        self::assertStringNotContainsString("Fictional Algebra", print_r($view, true));
        $coverage = $data['allocation']['groups'][0]['coverage'];
        self::assertSame(3000, $coverage['applied_credits_milli']);
        self::assertSame(1, $coverage['applied_courses']);
        self::assertSame([0], array_keys($coverage['rules']));
        self::assertSame([[0]], $data['allocation']['groups'][0]['placements'][0]['evidence_options']);
        self::assertSame(['code' => 'A', 'points_milli' => 4000, 'grading_publication_retained' => true], $data['records'][0]['retained_grade']);
        self::assertSame(3000, $data['records'][0]['recorded_earned_milli']);
        self::assertStringNotContainsString('work_options', json_encode($data));
        self::assertSame(3000, $coverage['rules'][0]['required_credits_milli']);
    }
    public function testIncompletePlacementCannotExposeAProposedPartialPlan(): void
    {
        $data = new StudentDegreeProgress($this->review("undetermined"))->describe();
        self::assertSame([], $data["allocation"]["groups"][0]["placements"]);
        self::assertNull($data['allocation']['groups'][0]['coverage']);
    }
    public function testMissingDuplicateAndUnboundedEvidenceReferencesAreRejected(): void
    {
        $cases = [
            [],
            [[]],
            [["foreign-record"]],
            [["fictional-staff-only-detail", "fictional-staff-only-detail"]],
        ];
        foreach ($cases as $options) {
            $review = $this->review();
            $review["allocation"]["groups"][0]["placements"][0]["work_options"] = $options;
            try {
                new StudentDegreeProgress($review);
                self::fail("Invalid evidence was presented as retained coursework.");
            } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
        $review = $this->review();
        $record = $review["records"][0];
        $review["records"] = [];
        $ids = [];
        foreach (range(1, 200) as $i) {
            $ids[] = $id = str_pad((string) $i, 26, "0", STR_PAD_LEFT);
            $review["records"][] = array_replace($record, ["id" => $id]);
        }
        $placement = $review["allocation"]["groups"][0]["placements"][0];
        $placement["work_options"] = [$ids];
        $review["allocation"]["groups"][0]["placements"] = array_fill(0, 101, $placement);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        new StudentDegreeProgress($review);
    }
    public function testLimitedResultsCannotExposePartialTotalsOrWork(): void
    {
        $review = $this->review();
        $review["status"] = "limited";
        self::assertSame(["status" => "limited"], new StudentDegreeProgress($review)->describe());
    }
}
