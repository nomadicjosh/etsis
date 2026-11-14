<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\DegreeAudit\ValueObject\AllocationProblem;
use Domain\DegreeAudit\ValueObject\AllocationPreparation;
use Domain\DegreeAudit\ValueObject\AllocationResult;
use Domain\DegreeAudit\ValueObject\AcademicWorkEvidence;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse;
use Domain\DegreeAudit\ValueObject\PolicyAttempt;
use Domain\DegreeAudit\ValueObject\RepeatCreditPolicy;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use JsonException;

/** Structural group review for supplied verified current work, not full degree completion. */
final readonly class GroupAllocationEvaluator
{
    public function __construct(
        private CourseMatchEvaluator $matches,
        private AcademicPolicyEvaluator $credits,
        private CourseAllocationPlanner $planner,
        private AllocationGapAnalyzer $gaps,
        private RuleAllocationCompiler $rules,
        private AllocationCoverageReview $coverage,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $work
     * @param array<string,mixed> $requirements
     * @param array<string,mixed> $effective
     * @param list<array<string,mixed>> $eligibility Complete captured saved-rule decisions for this work.
     * @return array<string,mixed>
     * @throws JsonException
     */
    public function evaluate(
        #[\SensitiveParameter] array $work,
        #[\SensitiveParameter] array $requirements,
        #[\SensitiveParameter] array $effective,
        #[\SensitiveParameter] array $eligibility = [],
    ): array {
        return $this->prepare($work, $requirements, $effective, $eligibility)->review();
    }

    /** Build normalized choices once, without searching or exposing partial placements.
     * @param list<array<string,mixed>> $work
     * @param array<string,mixed> $requirements
     * @param array<string,mixed> $effective
     * @param list<array<string,mixed>> $eligibility
     */
    public function prepare(
        #[\SensitiveParameter] array $work,
        #[\SensitiveParameter] array $requirements,
        #[\SensitiveParameter] array $effective,
        #[\SensitiveParameter] array $eligibility = [],
    ): AllocationPreparation {
        if (count($work) > 200) {
            throw new AllocationBudgetExhaustedException();
        }
        $observed = [];
        foreach ($work as $item) {
            $attempt = PolicyAttempt::fromAcademicWork($item);
            if (isset($observed[$attempt->id->value])) {
                throw new CorruptHistoryException();
            }
            $observed[$attempt->id->value] = AcademicWorkEvidence::fromAttempt($attempt);
        }
        $labels = [];
        $namedMemberships = [];
        $issues = [];
        $reuse = $requirements["definition"]["policies"]["group_reuse"] ?? null;
        $sharing = is_string($reuse) ? CourseGroupReuse::tryFrom($reuse) : null;
        $policy = AuditCalculationPolicy::fromRequirements($requirements);
        if ($sharing === null || $policy->credit === null || $requirements["group_details"] === []) {
            return new AllocationPreparation(null, static fn(?AllocationResult $result): array => [
                "outcome" => "needs_review",
                "groups" => [],
                "records" => [],
                "issues" => ["policy_unknown"],
            ]);
        }
        foreach ($requirements["group_details"] as $id => $group) {
            $d = $group["definition"];
            $e = $effective["groups"][$id];
            foreach ($d['rules'] as $rule) {
                if (!isset($rule['eligibility'])) {
                    foreach ($rule['course_ids'] as $course) {
                        $namedMemberships[$id][$course][] = $rule['id'];
                    }
                }
            }
            if (($e["min_gpa_milli"] ?? ($d["min_gpa_milli"] ?? 0)) > 0) {
                $issues["group_average_gpa_unsupported"] = true;
            }
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
                "coverage" => null,
                'course_review' => null,
            ];
        }
        if ($issues !== []) {
            return new AllocationPreparation(null, static fn(?AllocationResult $result): array => [
                "outcome" => "unsupported",
                "groups" => array_values($labels),
                "records" => [],
                "issues" => array_keys($issues),
            ]);
        }
        $namedRequirements = $requirements;
        $compiled = $this->rules->compile($work, $requirements, $effective, $eligibility);
        $requirements = $compiled['requirements'];
        $issues = array_fill_keys($compiled['issues'], true);
        $groups = [];
        foreach ($requirements['group_details'] as $id => $group) {
            $groups[] = [
                'id' => $id,
                'min_credits_milli' => $effective['groups'][$id]['min_credits_milli'],
                'min_courses' => $effective['groups'][$id]['min_courses'] ?? 0,
                'rules' => $group['definition']['rules'],
            ];
            $label = $labels[$id] ?? throw new CorruptHistoryException();
            $label['courses'] = $group['course_details'];
            foreach ($label['rules'] as &$rule) {
                if (isset($rule['eligibility'])) {
                    $rule['course_ids'] = $requirements['rule_course_ids'][$id][$rule['id']];
                }
            }
            unset($rule);
            $labels[$id] = $label;
        }
        if (($effective["substitution_conflicts"] ?? []) !== []) {
            $issues["approval_conflict"] = true;
        }
        $resources = [];
        $records = [];
        $pairs = [];
        $candidates = [];
        $candidateMemberships = [];
        $items = $this->matches->allocation($work, $requirements, $effective);
        foreach ($items as $item) {
            $id = $item["id"];
            $records[$id] = $observed[$id] + [
                "id" => $id,
                "course_code" => $item["course_code"],
                "course_title" => $item["course_title"],
                "term_code" => ($item['term_label_retained'] ?? false) ? ($item['term_code'] ?? null) : null,
                "term_label_retained" => $item['term_label_retained'] ?? false,
                "attempted_milli" => $item['attempted_milli'],
                "grading_republication_verified" =>
                    isset($requirements['identical_grading_publications'][$item['grade']['scale_version_id'] ?? '']),
                "statuses" => [],
                "uses" => [],
                "preferred_group_id" => $effective["placements"][$item["course_id"]]["group_id"] ?? null,
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
                if (isset($requirements['rule_memberships'][$match['group_id']])) {
                    $memberships = array_merge(
                        $namedMemberships[$match['group_id']][$match['required_course_id']] ?? [],
                        $requirements['rule_memberships'][$match['group_id']][$id]
                            ?? throw new CorruptHistoryException(),
                    );
                    sort($memberships, SORT_STRING);
                    $candidateMemberships[$key][$id] = $memberships;
                }
                $pairs[$key] = $match + ["actual_course_id" => $item["course_id"]];
                $candidates[$key][] = PolicyAttempt::fromAcademicWork($item);
            }
        }
        $evidence = [];
        $evidenceCount = 0;
        $contributionCount = 0;
        foreach ($candidates as $key => $attempts) {
            $pair = $pairs[$key];
            $memberships = $candidateMemberships[$key] ?? null;
            $selected = [];
            foreach ($this->pools($attempts, $policy, $memberships) as $pool) {
                $options = $pool['options'];
                $evidenceCount += array_sum(array_map(count(...), $options));
                $contributionCount += count($pool['rule_credits_milli'] ?? []);
                if ($evidenceCount > 20000 || $contributionCount > 20000) {
                    throw new AllocationBudgetExhaustedException();
                }
                $course = $pair['actual_course_id'];
                $choice = [
                    'group_id' => $pair['group_id'],
                    'target_course_id' => $pair['required_course_id'],
                    'credits_milli' => $pool['credits_milli'],
                    'work_ids' => $options[0],
                ] + (isset($pool['rule_credits_milli']) ? ['rule_credits_milli' => $pool['rule_credits_milli']] : []);
                $resources[$course]['course_id'] = $course;
                $resources[$course]['choices'][] = $choice;
                $evidence[$key . $this->contributionKey($choice)] = $options;
                array_push($selected, ...array_merge(...$options));
                if ($pair['dynamic_target'] ?? false) {
                    $representative = $records[$options[0][0]];
                    $labels[$pair['group_id']]['courses'][$pair['required_course_id']] = [
                        'code' => $representative['course_code'], 'title' => $representative['course_title'],
                        'credits_milli' => $pool['credits_milli'],
                    ];
                }
            }
            foreach ($attempts as $attempt) {
                if (!in_array($attempt->id->value, $selected, true)) {
                    $record = $records[$attempt->id->value] ?? throw new CorruptHistoryException();
                    $record['statuses']['repeat_not_selected'] = true;
                    $records[$attempt->id->value] = $record;
                }
            }
        }
        $problem = $issues === [] ? new AllocationProblem(
            $groups,
            array_values($resources),
            $sharing,
            $effective['waived_course_ids'],
        ) : null;
        return new AllocationPreparation($problem, function (#[\SensitiveParameter] ?AllocationResult $planned) use (
            $problem,
            $labels,
            $records,
            $issues,
            $pairs,
            $evidence,
            $namedRequirements,
            $items,
            $effective,
        ): array {
            $outcome = 'needs_review';
            $competition = null;
            if ($problem !== null) {
                foreach ($this->gaps->analyze($problem) as $id => $report) {
                    $labels[$id]["gaps"] = $report;
                }
                $planned ??= $this->planner->plan($problem);
                $outcome = $planned->outcome->value;
                foreach ($this->coverage->review($problem, $planned) as $id => $coverage) {
                    $ordered = [];
                    $retainedRules = $labels[$id]['rules'] ?? throw new CorruptHistoryException();
                    foreach ($retainedRules as $rule) {
                        $ordered[$rule['id']] = $coverage['rules'][$rule['id']];
                    }
                    $coverage['rules'] = $ordered;
                    $labels[$id]['coverage'] = $coverage;
                }
                if ($outcome === "infeasible") {
                    $competition = $this->gaps->competition($problem);
                }
                foreach ($planned->placements as $placement) {
                    $key =
                    $placement["course_id"] .
                    ":" .
                    $placement["group_id"] .
                    ":" .
                    $placement["target_course_id"];
                    $pair = $pairs[$key];
                    $options = $evidence[$key . $this->contributionKey($placement)];
                    $workLabel = $records[$options[0][0]];
                    $labels[$placement["group_id"]]["placements"][] = [
                        "course_id" => $placement["course_id"],
                        "course_code" => $workLabel["course_code"],
                        "course_title" => $workLabel["course_title"],
                        "target_course_id" => $placement["target_course_id"],
                        "target_code" => ($pair['dynamic_target'] ?? false)
                            ? $workLabel['course_code'] : $pair["required_course_code"],
                        "target_title" => ($pair['dynamic_target'] ?? false)
                            ? $workLabel['course_title'] : $pair["required_course_title"],
                        "via_substitution" => $pair["via_substitution"],
                        "credits_milli" => $placement["credits_milli"],
                        "work_options" => $options,
                    ] + (isset($placement['rule_credits_milli'])
                    ? ['rule_credits_milli' => $placement['rule_credits_milli']] : []);
                    if ($pair['dynamic_target'] ?? false) {
                        $labels[$placement['group_id']]['courses'][$placement['target_course_id']] = [
                            'code' => $workLabel['course_code'], 'title' => $workLabel['course_title'],
                            'credits_milli' => $placement['credits_milli'],
                        ];
                    }
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
            $courseReviews = $this->matches->namedReview(
                $namedRequirements,
                $items,
                array_values($labels),
                $effective['waived_course_ids'],
                $outcome === 'feasible',
            );
            foreach ($labels as $groupId => &$label) {
                $label['course_review'] = $courseReviews[$groupId];
                $courseLabels = $label['courses'] ?? throw new CorruptHistoryException();
                ksort($courseLabels, SORT_STRING);
                $label['courses'] = $courseLabels;
            }
            unset($label);
            ksort($issues, SORT_STRING);
            return [
                "outcome" => $outcome,
                "competition" => $competition,
                "groups" => array_values($labels),
                "records" => array_values($records),
                "issues" => array_keys($issues),
            ];
        });
    }

    /**
     * Highest eligible awards with different rule memberships are distinct placement alternatives.
     * All-eligible awards form one pool, retaining only each card's own eligible earned credits.
     * @param list<PolicyAttempt> $attempts
     * @param array<string,list<string>>|null $memberships
     * @return list<array{credits_milli:int,options:list<list<string>>,rule_credits_milli?:array<string,int>}>
     */
    private function pools(
        #[\SensitiveParameter] array $attempts,
        AuditCalculationPolicy $policy,
        #[\SensitiveParameter] ?array $memberships = null,
    ): array {
        $sets = [];
        $byId = [];
        foreach ($attempts as $attempt) {
            $byId[$attempt->id->value] = $attempt;
            $signature = $memberships !== null && $policy->credit === RepeatCreditPolicy::HighestEarned
            ? implode(':', $memberships[$attempt->id->value]) : '';
            $sets[$signature][] = $attempt;
        }
        ksort($sets, SORT_STRING);
        $pools = [];
        foreach ($sets as $set) {
            $result = $this->credits->evaluate($set, $policy);
            if ($result->earnedMilli === null || count($result->creditPools) !== 1) {
                throw new CorruptHistoryException();
            }
            $pool = $result->creditPools[0];
            $options = array_column($pool['options'], 'work_ids');
            sort($options, SORT_REGULAR);
            $entry = ['credits_milli' => $pool['credits_milli'], 'options' => $options];
            if ($memberships !== null) {
                $expected = null;
                foreach ($options as $option) {
                    $contributions = [];
                    foreach ($option as $work) {
                        foreach ($memberships[$work] as $rule) {
                            $contributions[$rule] = ($contributions[$rule] ?? 0) + $byId[$work]->earnedMilli;
                        }
                    }
                    ksort($contributions, SORT_STRING);
                    if ($expected !== null && $contributions !== $expected) {
                        throw new CorruptHistoryException();
                    }
                    $expected = $contributions;
                }
                $entry['rule_credits_milli'] = $expected ?? throw new CorruptHistoryException();
            }
            $pools[] = $entry;
        }
        return $pools;
    }

    /**
     * @param array<string,mixed> $choice
     * @throws JsonException
     */
    private function contributionKey(#[\SensitiveParameter] array $choice): string
    {
        return ':' . hash('sha256', json_encode($choice['rule_credits_milli'] ?? null, JSON_THROW_ON_ERROR));
    }
}
