<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\CourseMatchStatus as Status;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use InvalidArgumentException;

/** Pure, bounded candidate inspection using already-verified retained inputs. */
final readonly class CourseMatchEvaluator
{
    public const int MATCH_LIMIT = 25;

    /**
     * @param list<array<string,mixed>> $work
     * @param array<string,mixed> $requirements Verified published composition, not an authoring draft.
     * @param array<string,mixed> $effective Verified effective exceptions for the same pursuit/selection.
     * @return list<array<string,mixed>>
     */
    public function page(array $work, array $requirements, array $effective): array
    {
        if (count($work) > 20) {
            throw new InvalidArgumentException("Inspect at most 20 academic records per page.");
        }
        $index = $this->index($requirements, $effective);
        $items = [];
        foreach ($work as $item) {
            $candidates = $index[$item["course_id"]] ?? [];
            $matches = [];
            foreach (array_slice($candidates, 0, self::MATCH_LIMIT) as $candidate) {
                $status = $this->status(
                    $item,
                    $candidate,
                    $requirements["grade_scale"] ?? null,
                    $effective["placements"][$item["course_id"]]["group_id"] ?? null,
                    $requirements['identical_grading_publications'] ?? [],
                );
                if (in_array($item['course_id'], $effective['excluded_course_ids'] ?? [], true)) {
                    $status = Status::ComponentExcluded;
                }
                if (isset($candidate['exception_version_ids'][$item['course_version_id'] ?? ''])) {
                    $candidate['via_substitution'] = true;
                }
                $matches[] = $candidate + [
                    "status" => $status->value,
                    "explanation" => $status->explanation(),
                ];
            }
            $items[] = array_replace($item, [
                "requirement_matches" => $matches,
                "more_requirement_matches" => count($candidates) > self::MATCH_LIMIT,
                'saved_rule_matches_pending' => $this->hasSavedRules($requirements),
            ]);
        }
        return $items;
    }

    /**
     * Complete bounded eligibility for structural placement; never use truncated candidate pages.
     * Earned work for a waived target can still meet quantity constraints.
     * @param list<array<string,mixed>> $work
     * @param array<string,mixed> $requirements
     * @param array<string,mixed> $effective
     * @return list<array<string,mixed>>
     */
    public function allocation(array $work, array $requirements, array $effective): array
    {
        if (count($work) > 200) {
            throw new \Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException();
        }
        $index = $this->index($requirements, $effective);
        $items = [];
        $checks = 0;
        foreach ($work as $item) {
            $matches = [];
            foreach ($index[$item["course_id"]] ?? [] as $candidate) {
                if (isset($candidate['matched_work_ids']) && !isset($candidate['matched_work_ids'][$item['id']])) {
                    continue;
                }
                if (++$checks > 5000) {
                    throw new \Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException();
                }
                $status = $this->allocationStatus(
                    $item,
                    $candidate,
                    $requirements["grade_scale"] ?? null,
                    $effective["placements"][$item["course_id"]]["group_id"] ?? null,
                    $requirements['identical_grading_publications'] ?? [],
                );
                if (in_array($item['course_id'], $effective['excluded_course_ids'] ?? [], true)) {
                    $status = Status::ComponentExcluded;
                }
                if (isset($candidate['exception_version_ids'][$item['course_version_id'] ?? ''])) {
                    $candidate['via_substitution'] = true;
                }
                $matches[] = $candidate + ["status" => $status->value];
            }
            $items[] = array_replace($item, ["requirement_matches" => $matches]);
        }
        return $items;
    }

    /** @param array<string,mixed> $work
     * @param array<string,mixed> $candidate
     * @param array<string,mixed>|null $scale
     * @param array<string,true> $identicalGradingPublications
     */
    public function allocationStatus(
        #[\SensitiveParameter] array $work,
        #[\SensitiveParameter] array $candidate,
        ?array $scale,
        ?string $preferredGroup = null,
        array $identicalGradingPublications = [],
    ): Status {
        $status = $this->status(
            $work,
            array_replace($candidate, ['waived' => false]),
            $scale,
            $preferredGroup,
            $identicalGradingPublications,
        );
        return $status === Status::InProgress && $work['quality_points_milli'] !== 0
        ? Status::GradeUnknown : $status;
    }

    /** Explain named requirements from the same captured checks, without choosing another plan.
     * @param array<string,mixed> $requirements Original requirements, before saved-rule compilation.
     * @param list<array<string,mixed>> $items Complete captured allocation checks.
     * @param list<array<string,mixed>> $groups The finished placement result.
     * @param list<string> $waived
     * @return array<string,list<array<string,mixed>>>
     */
    public function namedReview(
        #[\SensitiveParameter] array $requirements,
        #[\SensitiveParameter] array $items,
        #[\SensitiveParameter] array $groups,
        array $waived,
        bool $established,
    ): array {
        if (count($items) > 200 || count($groups) > 25) {
            throw new \Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException();
        }
        $rows = [];
        foreach ($requirements['group_details'] as $id => $group) {
            $required = [];
            foreach ($group['definition']['rules'] as $rule) {
                if (!isset($rule['eligibility']) && $rule['mode'] === 'all') {
                    $required += array_fill_keys($rule['course_ids'], true);
                }
            }
            $rows[$id] = [];
            foreach ($group['definition']['courses'] as $reference) {
                $course = $reference['course_id'];
                $label = $group['course_details'][$course];
                $rows[$id][$course] = [
                    'course_id' => $course,
                    'course_code' => $label['code'],
                    'course_title' => $label['title'],
                    'required' => isset($required[$course]),
                    'waived' => in_array($course, $waived, true),
                    'placement' => $established ? 'not_used' : 'not_established',
                    'attempts' => [],
                ];
            }
        }
        $chosen = [];
        $chosenCount = 0;
        foreach ($groups as $group) {
            foreach ($group['placements'] as $placement) {
                if (!$established) {
                    throw new CorruptHistoryException();
                }
                $target = $placement['target_course_id'];
                if (!isset($rows[$group['id']][$target])) {
                    continue; // A saved-rule-only target is not a named obligation.
                }
                $rows[$group['id']][$target]['placement'] = 'proposed';
                foreach ($placement['work_options'] as $option) {
                    foreach ($option as $work) {
                        if (!isset($chosen[$group['id']][$target][$work])) {
                            $chosenCount++;
                        }
                        $chosen[$group['id']][$target][$work] = true;
                    }
                }
            }
        }
        $count = 0;
        $selectedCount = 0;
        $seen = [];
        foreach ($items as $item) {
            foreach ($item['requirement_matches'] as $match) {
                $group = $match['group_id'];
                $target = $match['required_course_id'];
                if (!isset($rows[$group][$target])) {
                    continue;
                }
                if (++$count > 5000) {
                    throw new \Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException();
                }
                $status = Status::from($match['status']);
                $selected = isset($chosen[$group][$target][$item['id']]);
                if (
                    isset($seen[$group][$target][$item['id']]) ||
                    ($selected && $status !== Status::Candidate)
                ) {
                    throw new CorruptHistoryException();
                }
                $seen[$group][$target][$item['id']] = true;
                $selectedCount += (int) $selected;
                $rows[$group][$target]['attempts'][] = [
                    'work_id' => $item['id'],
                    'status' => $status->value,
                    'via_substitution' => $match['via_substitution'],
                    'selected' => $established ? $selected : null,
                ];
            }
        }
        if ($chosenCount !== $selectedCount) {
            throw new CorruptHistoryException();
        }
        return array_map(array_values(...), $rows);
    }
    /**
     * @param array<string,mixed> $requirements
     * @param array<string,mixed> $effective
     * @return array<string,list<array<string,mixed>>>
     */
    private function index(array $requirements, array $effective): array
    {
        $index = [];
        foreach ($requirements["group_details"] as $groupId => $group) {
            $definition = $group["definition"];
            $modes = [];
            foreach ($definition["rules"] as $rule) {
                foreach ($rule["course_ids"] as $courseId) {
                    $modes[$courseId][$rule["mode"]] = true;
                }
            }
            foreach ($definition["courses"] as $course) {
                $id = $course["course_id"];
                $candidate = [
                    "group_id" => $groupId,
                    "group_version_id" => $group["version_id"],
                    "group_name" => $definition["name"],
                    "required_course_id" => $id,
                    "required_course_code" => $group["course_details"][$id]["code"],
                    "required_course_title" => $group["course_details"][$id]["title"],
                    "expected_course_version_id" => $course["version_id"],
                    "via_substitution" => false,
                    "rule_modes" => array_keys($modes[$id] ?? []),
                    "minimum_grade_points_milli" =>
                        $effective["groups"][$groupId]["min_course_grade_points_milli"] ??
                        ($definition["min_course_grade_points_milli"] ?? null),
                    "waived" => in_array($id, $effective["waived_course_ids"], true),
                    "approval_conflict" => isset($effective["substitution_conflicts"][$id]),
                ];
                $candidate['accepted_course_versions'] = $this->acceptedVersions($course, $effective);
                $candidate['exception_version_ids'] = array_diff_key(
                    $effective['accepted_course_versions'][$id] ?? [],
                    array_fill_keys([$course['version_id'], ...($course['accepted_version_ids'] ?? [])], true),
                );
                if (isset($requirements['dynamic_versions'][$groupId][$id])) {
                    $candidate['accepted_course_versions'] = $requirements['dynamic_versions'][$groupId][$id];
                    $candidate['matched_work_ids'] = $requirements['dynamic_work_ids'][$groupId][$id];
                    $candidate['dynamic_target'] = true;
                    unset($candidate['exception_version_ids']);
                }
                $index[$id][] = $candidate;
                if (
                    isset($effective["substitutions"][$id]) &&
                    $effective['substitutions'][$id]['course_id'] !== $id &&
                    !$candidate["waived"] &&
                    !($candidate['dynamic_target'] ?? false) &&
                    !$candidate["approval_conflict"]
                ) {
                    $replacement = $effective["substitutions"][$id];
                    unset($candidate['accepted_course_versions'], $candidate['exception_version_ids']);
                    $candidate["expected_course_version_id"] = $replacement["version_id"];
                    $candidate["via_substitution"] = true;
                    $index[$replacement["course_id"]][] = $candidate;
                }
            }
        }
        return $index;
    }

    /** Explicit curriculum allowances plus a verified same-course student exception.
     * @param array<string,mixed> $reference
     * @param array<string,mixed> $effective
     * @return array<string,true>
     */
    public function acceptedVersions(array $reference, #[\SensitiveParameter] array $effective): array
    {
        $allowed = array_fill_keys([$reference['version_id'], ...($reference['accepted_version_ids'] ?? [])], true);
        $allowed += $effective['accepted_course_versions'][$reference['course_id']] ?? [];
        return $allowed;
    }

    /** @param array<string,mixed> $requirements */
    private function hasSavedRules(array $requirements): bool
    {
        foreach ($requirements['group_details'] as $group) {
            foreach ($group['definition']['rules'] as $rule) {
                if (isset($rule['eligibility'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $work
     * @param array<string,mixed> $candidate
     * @param array<string,mixed>|null $scale
     * @param array<string,true> $identicalGradingPublications
     */
    private function status(
        array $work,
        array $candidate,
        ?array $scale,
        ?string $preferredGroup = null,
        array $identicalGradingPublications = [],
    ): Status {
        if ($candidate["approval_conflict"]) {
            return Status::ApprovalConflict;
        }
        if ($candidate["waived"]) {
            return Status::Waived;
        }
        if ($work["status"] === "V") {
            return Status::Voided;
        }
        if (in_array($work["status"], ["W", "D"], true)) {
            return Status::Dropped;
        }
        if ($preferredGroup !== null && $candidate["group_id"] !== $preferredGroup) {
            return Status::PlacementRestricted;
        }
        if (!$work["grade_recorded"] && $work["completed_milli"] === 0) {
            return Status::InProgress;
        }
        $grade = $work["grade"];
        if ($grade === null) {
            return Status::GradeUnknown;
        }
        if (!$grade["passes"]) {
            return Status::NotPassing;
        }
        if ($work["completed_milli"] <= 0 && $work["attempted_milli"] > 0) {
            return Status::NoCredit;
        }
        if ($work["course_version_id"] === null) {
            return Status::CourseVersionUnknown;
        }
        if (
            isset($candidate['accepted_course_versions'])
            ? !isset($candidate['accepted_course_versions'][$work['course_version_id']])
            : $work["course_version_id"] !== $candidate["expected_course_version_id"]
        ) {
            return Status::CourseVersionDiffers;
        }
        $minimum = $candidate["minimum_grade_points_milli"];
        if ($minimum !== null && $minimum > 0) {
            if (($scale["version_id"] ?? null) === null || $grade["scale_version_id"] === null) {
                return Status::ScaleUnknown;
            }
            if (
                $scale["version_id"] !== $grade["scale_version_id"] &&
                !isset($identicalGradingPublications[$grade['scale_version_id']])
            ) {
                return Status::ScaleDiffers;
            }
            if ($grade["points_milli"] < $minimum) {
                return Status::InsufficientGrade;
            }
        }
        return Status::Candidate;
    }
}
