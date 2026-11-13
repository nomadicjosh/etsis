<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\DegreeAudit\ValueObject\CourseMatchStatus;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Pure request-local constraints from verified work and captured predicate decisions. No publication changes. */
final readonly class RuleAllocationCompiler
{
    public function __construct(private CourseMatchEvaluator $matches)
    {
    }

    /**
     * @param list<array<string,mixed>> $work
     * @param array<string,mixed> $requirements
     * @param array<string,mixed> $effective
     * @param list<array<string,mixed>> $eligibility
     * @return array{requirements:array<string,mixed>,issues:list<string>}
     */
    public function compile(
        #[\SensitiveParameter] array $work,
        #[\SensitiveParameter] array $requirements,
        #[\SensitiveParameter] array $effective,
        #[\SensitiveParameter] array $eligibility,
    ): array {
        if (count($work) > 200 || count($eligibility) > 5000) {
            throw new AllocationBudgetExhaustedException();
        }
        $decisions = [];
        foreach ($eligibility as $row) {
            $key = $row['group_id'] . ':' . $row['requirement_id'] . ':' . $row['work_id'];
            if (isset($decisions[$key]) || !in_array($row['outcome'], ['matches', 'does_not_match', 'unknown'], true)) {
                throw new CorruptHistoryException();
            }
            $decisions[$key] = $row['outcome'];
        }
        $issues = [];
        $checks = 0;
        foreach ($requirements['group_details'] as $groupId => &$group) {
            $dynamic = array_filter(
                $group['definition']['rules'],
                static fn(array $r): bool => isset($r['eligibility']),
            );
            if ($dynamic === []) {
                continue;
            }
            $named = array_column($group['definition']['courses'], 'version_id', 'course_id');
            $substitutes = [];
            foreach ($effective['substitutions'] as $target => $replacement) {
                if (
                    isset($named[$target]) && $target !== $replacement['course_id'] &&
                    !in_array($target, $effective['waived_course_ids'], true) &&
                    !isset($effective['substitution_conflicts'][$target])
                ) {
                    $substitutes[$replacement['course_id']][$target] = true;
                }
            }
            $courses = [];
            $eligibleLabels = [];
            $targets = [];
            foreach ($work as $item) {
                $courseId = $item['course_id'];
                $candidate = [
                    'group_id' => $groupId,
                    'approval_conflict' => isset($effective['substitution_conflicts'][$courseId]),
                    'waived' => false,
                    'expected_course_version_id' => $item['course_version_id'],
                    'minimum_grade_points_milli' => $effective['groups'][$groupId]['min_course_grade_points_milli']
                        ?? ($group['definition']['min_course_grade_points_milli'] ?? null),
                ];
                $status = $this->matches->allocationStatus(
                    $item,
                    $candidate,
                    $requirements['grade_scale'] ?? null,
                    $effective['placements'][$courseId]['group_id'] ?? null,
                );
                $possible = in_array($status, [
                    CourseMatchStatus::Candidate, CourseMatchStatus::GradeUnknown,
                    CourseMatchStatus::CourseVersionUnknown, CourseMatchStatus::ScaleUnknown,
                    CourseMatchStatus::ScaleDiffers, CourseMatchStatus::ApprovalConflict,
                ], true);
                $membership = [];
                foreach ($dynamic as $rule) {
                    if (++$checks > 5000) {
                        throw new AllocationBudgetExhaustedException();
                    }
                    $key = $groupId . ':' . $rule['id'] . ':' . $item['id'];
                    $decision = $decisions[$key] ?? throw new CorruptHistoryException();
                    if ($decision === 'unknown' && $possible) {
                        $issues['rule_facts_unknown'] = true;
                    }
                    if ($decision !== 'matches') {
                        continue;
                    }
                    $membership[] = $rule['id'];
                    if (
                        $status === CourseMatchStatus::Candidate && $item['course_version_id'] !== null &&
                        (!isset($named[$courseId]) || $named[$courseId] === $item['course_version_id'])
                    ) {
                        $targets[$rule['id']][$courseId] = true;
                    }
                }
                sort($membership, SORT_STRING);
                $requirements['rule_memberships'][$groupId][$item['id']] = $membership;
                if ($membership === []) {
                    continue;
                }
                if ($possible && $status !== CourseMatchStatus::Candidate) {
                    $issues[$status->value] = true;
                }
                if ($item['course_version_id'] === null) {
                    continue;
                }
                if (
                    !isset($courses[$courseId]) ||
                    ($status === CourseMatchStatus::Candidate && !isset($eligibleLabels[$courseId]))
                ) {
                    $courses[$courseId] = $item;
                }
                if ($status === CourseMatchStatus::Candidate) {
                    $eligibleLabels[$courseId] = true;
                }
                if (!isset($named[$courseId])) {
                    $requirements['dynamic_versions'][$groupId][$courseId][$item['course_version_id']] = true;
                    $requirements['dynamic_work_ids'][$groupId][$courseId][$item['id']] = true;
                }
                if ($status !== CourseMatchStatus::Candidate) {
                    continue;
                }
                if (isset($named[$courseId]) && $named[$courseId] !== $item['course_version_id']) {
                    $issues['rule_publication_conflict'] = true;
                }
            }
            if (count($named + $courses) > 200) {
                throw new AllocationBudgetExhaustedException();
            }
            foreach ($courses as $id => $item) {
                if (isset($named[$id])) {
                    continue;
                }
                $group['definition']['courses'][] = ['course_id' => $id, 'version_id' => $item['course_version_id']];
                $group['course_details'][$id] = [
                    'code' => $item['course_code'], 'title' => $item['course_title'],
                    'credits_milli' => $item['completed_milli'],
                ];
            }
            foreach ($group['definition']['rules'] as &$rule) {
                if (isset($rule['eligibility'])) {
                    $actual = array_keys($targets[$rule['id']] ?? []);
                    sort($actual, SORT_STRING);
                    $requirements['rule_course_ids'][$groupId][$rule['id']] = $actual;
                    $eligibleTargets = $targets[$rule['id']] ?? [];
                    // A matching replacement can contribute while occupying its approved named slot.
                    // Display lists retain actual courses; each choice still carries its own card memberships.
                    foreach ($actual as $course) {
                        $eligibleTargets += $substitutes[$course] ?? [];
                    }
                    $rule['course_ids'] = array_keys($eligibleTargets);
                    sort($rule['course_ids'], SORT_STRING);
                    unset($rule['eligibility']);
                }
            }
            unset($rule);
        }
        unset($group);
        if ($checks !== count($decisions)) {
            throw new CorruptHistoryException();
        }
        ksort($issues, SORT_STRING);
        return ['requirements' => $requirements, 'issues' => array_keys($issues)];
    }
}
