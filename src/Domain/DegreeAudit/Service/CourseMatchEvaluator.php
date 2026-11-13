<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\CourseMatchStatus as Status;
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
                    $effective["placements"][$item["course_id"]]["group_id"] ?? null
                );
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
                    $effective["placements"][$item["course_id"]]["group_id"] ?? null
                );
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
     */
    public function allocationStatus(
        #[\SensitiveParameter] array $work,
        #[\SensitiveParameter] array $candidate,
        ?array $scale,
        ?string $preferredGroup = null,
    ): Status {
        $status = $this->status($work, array_replace($candidate, ['waived' => false]), $scale, $preferredGroup);
        return $status === Status::InProgress && $work['quality_points_milli'] !== 0
        ? Status::GradeUnknown : $status;
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
     */
    private function status(array $work, array $candidate, ?array $scale, ?string $preferredGroup = null): Status
    {
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
            if ($scale["version_id"] !== $grade["scale_version_id"]) {
                return Status::ScaleDiffers;
            }
            if ($grade["points_milli"] < $minimum) {
                return Status::InsufficientGrade;
            }
        }
        return Status::Candidate;
    }
}
