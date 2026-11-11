<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\DegreeAudit\ValueObject\AllocationProblem;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse;
use Domain\DegreeAudit\ValueObject\PolicyAttempt;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Structural group review for supplied verified current work, not full degree completion. */
final readonly class GroupAllocationEvaluator
{
    public function __construct(
        private CourseMatchEvaluator $matches,
        private AcademicPolicyEvaluator $credits,
        private CourseAllocationPlanner $planner,
        private AllocationGapAnalyzer $gaps,
    ) {
    }

    /** @param list<array<string,mixed>> $work
     * @param array<string,mixed> $requirements
     * @param array<string,mixed> $effective
     * @return array<string,mixed>
     */
    public function evaluate(array $work, array $requirements, array $effective): array
    {
        if (count($work) > 200) {
            throw new AllocationBudgetExhaustedException();
        }
        foreach ($work as $item) {
            PolicyAttempt::fromAcademicWork($item);
        }
        $groups = [];
        $labels = [];
        $issues = [];
        $reuse = $requirements["definition"]["policies"]["group_reuse"] ?? null;
        $sharing = is_string($reuse) ? CourseGroupReuse::tryFrom($reuse) : null;
        $policy = AuditCalculationPolicy::fromRequirements($requirements);
        if ($sharing === null || $policy->credit === null || $requirements["group_details"] === []) {
            return [
                "outcome" => "needs_review",
                "groups" => [],
                "records" => [],
                "issues" => ["policy_unknown"],
            ];
        }
        foreach ($requirements["group_details"] as $id => $group) {
            $d = $group["definition"];
            $e = $effective["groups"][$id];
            if (($e["min_gpa_milli"] ?? ($d["min_gpa_milli"] ?? 0)) > 0) {
                $issues["group_average_gpa_unsupported"] = true;
            }
            $groups[] = [
                "id" => $id,
                "min_credits_milli" => $e["min_credits_milli"],
                "min_courses" => $e["min_courses"] ?? 0,
                "rules" => $d["rules"],
            ];
            $labels[$id] = [
                "id" => $id,
                "name" => $d["name"],
                "version_id" => $group["version_id"],
                "requirement_type" => $d["requirement_type"] ?? "credits",
                "rules" => $d["rules"],
                "courses" => $group["course_details"],
                "required_credits_milli" => $e["min_credits_milli"],
                "required_courses" => $e["min_courses"] ?? 0,
                "minimum_grade_points_milli" => $e["min_course_grade_points_milli"] ?? null,
                "waived_courses" => array_values(
                    array_intersect(
                        array_column($d["courses"], "course_id"),
                        $effective["waived_course_ids"],
                    ),
                ),
                "placements" => [],
                "gaps" => null,
            ];
        }
        if ($issues !== []) {
            return [
                "outcome" => "unsupported",
                "groups" => array_values($labels),
                "records" => [],
                "issues" => array_keys($issues),
            ];
        }
        if (($effective["substitution_conflicts"] ?? []) !== []) {
            $issues["approval_conflict"] = true;
        }
        $resources = [];
        $records = [];
        $pairs = [];
        $candidates = [];
        $items = $this->matches->allocation($work, $requirements, $effective);
        foreach ($items as $item) {
            $id = $item["id"];
            $records[$id] = [
                "id" => $id,
                "course_code" => $item["course_code"],
                "course_title" => $item["course_title"],
                "statuses" => [],
                "uses" => [],
            ];
            foreach ($item["requirement_matches"] as $match) {
                $status = $match["status"];
                $records[$id]["statuses"][$status] = true;
                if (
                    in_array(
                        $status,
                        [
                            "grade_unknown",
                            "course_version_unknown",
                            "course_version_differs",
                            "scale_unknown",
                            "scale_differs",
                            "approval_conflict",
                        ],
                        true,
                    )
                ) {
                    $issues[$status] = true;
                }
                if ($status !== "candidate") {
                    continue;
                }
                $key = $item["course_id"] . ":" . $match["group_id"] . ":" . $match["required_course_id"];
                $pairs[$key] = $match + ["actual_course_id" => $item["course_id"]];
                $candidates[$key][] = PolicyAttempt::fromAcademicWork($item);
            }
        }
        $evidence = [];
        $evidenceCount = 0;
        foreach ($candidates as $key => $attempts) {
            $result = $this->credits->evaluate($attempts, $policy);
            if ($result->earnedMilli === null || count($result->creditPools) !== 1) {
                throw new CorruptHistoryException();
            }
            $pool = $result->creditPools[0];
            $options = array_column($pool["options"], "work_ids");
            // Structurally interchangeable evidence stays explicit. Never infer a residency/GPA winner.
            sort($options, SORT_REGULAR);
            $evidenceCount += array_sum(array_map(count(...), $options));
            if ($evidenceCount > 20000) {
                throw new AllocationBudgetExhaustedException();
            }
            $pair = $pairs[$key];
            $course = $pair["actual_course_id"];
            $resources[$course]["course_id"] = $course;
            $resources[$course]["choices"][] = [
                "group_id" => $pair["group_id"],
                "target_course_id" => $pair["required_course_id"],
                "credits_milli" => $pool["credits_milli"],
                "work_ids" => $options[0],
            ];
            $evidence[$key] = $options;
            $selected = array_merge(...$options);
            foreach ($attempts as $attempt) {
                if (!in_array($attempt->id->value, $selected, true)) {
                    $record = $records[$attempt->id->value] ?? throw new CorruptHistoryException();
                    $record["statuses"]["repeat_not_selected"] = true;
                    $records[$attempt->id->value] = $record;
                }
            }
        }
        $outcome = "needs_review";
        if ($issues === []) {
            $problem = new AllocationProblem(
                $groups,
                array_values($resources),
                $sharing,
                $effective["waived_course_ids"],
            );
            foreach ($this->gaps->analyze($problem) as $id => $report) {
                $labels[$id]["gaps"] = $report;
            }
            $planned = $this->planner->plan($problem);
            $outcome = $planned->outcome->value;
            foreach ($planned->placements as $placement) {
                $key =
                $placement["course_id"] .
                ":" .
                $placement["group_id"] .
                ":" .
                $placement["target_course_id"];
                $pair = $pairs[$key];
                $options = $evidence[$key];
                $workLabel = $records[$options[0][0]];
                $labels[$placement["group_id"]]["placements"][] = [
                    "course_id" => $placement["course_id"],
                    "course_code" => $workLabel["course_code"],
                    "course_title" => $workLabel["course_title"],
                    "target_course_id" => $placement["target_course_id"],
                    "target_code" => $pair["required_course_code"],
                    "target_title" => $pair["required_course_title"],
                    "via_substitution" => $pair["via_substitution"],
                    "credits_milli" => $placement["credits_milli"],
                    "work_options" => $options,
                ];
                foreach ($options as $option) {
                    foreach ($option as $id) {
                        $record = $records[$id] ?? throw new CorruptHistoryException();
                        $record["uses"][] = [
                            "group_id" => $placement["group_id"],
                            "alternative" => count($options) > 1,
                        ];
                        $records[$id] = $record;
                    }
                }
            }
        }
        foreach ($records as &$record) {
            $record["statuses"] = array_keys($record["statuses"]);
            sort($record["statuses"], SORT_STRING);
        }
        unset($record);
        ksort($issues, SORT_STRING);
        return [
            "outcome" => $outcome,
            "groups" => array_values($labels),
            "records" => array_values($records),
            "issues" => array_keys($issues),
        ];
    }
}
