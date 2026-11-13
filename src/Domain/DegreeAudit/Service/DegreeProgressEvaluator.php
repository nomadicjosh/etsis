<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\AcademicGpaScope;
use Domain\DegreeAudit\ValueObject\AcademicInputSet;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\Shared\EventSourcing\HistoryReadBudget;

/** Shared calculations for verified assigned requirements and explicitly hypothetical authoring inputs. */
final readonly class DegreeProgressEvaluator
{
    public function __construct(
        private AcademicPolicyEvaluator $policies,
        private RetainedAttemptReader $attempts,
        private DegreeMinimumEvaluator $minimums,
        private GroupAllocationEvaluator $groups,
        private DegreeWorkReview $workReview,
    ) {
    }

    /** Caller authorizes and holds the student lock; this method creates no academic decisions.
     * @param array<string,mixed> $requirements
     * @param array<string,mixed> $effective
     * @return array<string,mixed>
     */
    public function evaluate(
        AcademicInputSet $inputs,
        array $requirements,
        array $effective,
        HistoryReadBudget $budget,
    ): array {
        $policy = AuditCalculationPolicy::fromRequirements($requirements);
        $attempts = $this->attempts->forPolicy($inputs, $policy, $budget);
        $overall = $this->policies->evaluate($attempts, $policy);
        $institutional = $this->policies->evaluate(
            $attempts,
            $policy,
            AcademicGpaScope::Institutional,
        );
        $checks = $this->minimums->evaluate(
            $overall,
            $institutional,
            $effective["degree"],
        );
        foreach ($checks as &$check) {
            $check["published_milli"] = $requirements["definition"][$check["key"]];
            $check["adjusted"] = $check["required_milli"] !== $check["published_milli"];
        }
        unset($check);
        $allocation = $this->groups->evaluate(
            $inputs->items,
            $requirements,
            $effective,
        );
        $byId = array_column($inputs->items, null, "id");
        $workReview = $this->workReview->review($inputs->items, $allocation);
        $cumulative = array_column($institutional->dispositions, null, "id");
        $records = [];
        foreach ($overall->dispositions as $d) {
            $work = $byId[$d["id"]];
            $records[] = $workReview["records"][$d["id"]] + [
                "id" => $d["id"],
                "course_code" => $work["course_code"],
                "course_title" => $work["course_title"],
                "credit" => $d["credit"],
                "overall_gpa" => $d["gpa"],
                "cumulative_gpa" => $cumulative[$d["id"]]["gpa"],
            ];
        }
        $issues = [];
        foreach (["overall" => $overall, "institutional" => $institutional] as $scope => $result) {
            foreach ($result->issues as $issue) {
                $component = $issue["component"] === "credit" ? "credits" : $scope;
                $issues[$component . ":" . $issue["code"]] = [
                    "component" => $component,
                    "code" => $issue["code"],
                ];
            }
        }
        ksort($issues, SORT_STRING);
        return [
            "status" => "review",
            "input_status" => $inputs->status->value,
            "current_record_count" => count($inputs->items),
            "minimums" => $checks,
            "allocation" => $allocation,
            "issues" => array_values($issues),
            "records" => $records,
            "work_summary" => $workReview["summary"],
            "gpa" => [
                "cumulative" => [
                    "ready" => $institutional->gpa !== null,
                    "milli" => $institutional->gpa?->milli(),
                    "credits_milli" => $institutional->gpa?->creditsMilli,
                    "quality_points_milli" => $institutional->gpa?->qualityMilli,
                ],
                "overall" => [
                    "ready" => $overall->gpa !== null,
                    "milli" => $overall->gpa?->milli(),
                    "credits_milli" => $overall->gpa?->creditsMilli,
                    "quality_points_milli" => $overall->gpa?->qualityMilli,
                ],
            ],
            "policy" => [
                "repeat_credit" => $policy->credit?->value,
                "repeat_gpa" => $policy->gpa?->value,
                "transfer_overall_gpa" => $policy->transferGpa?->value,
                "attempt_order" => $policy->attemptOrder?->value,
            ],
        ];
    }
}
