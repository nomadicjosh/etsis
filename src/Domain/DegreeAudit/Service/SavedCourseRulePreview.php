<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Catalog\Service\PublishedCourseReader;
use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\Rules\SavedCourseRule;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;
use JsonException;

/** Eligibility inspection only. A predicate match neither awards credits nor establishes group completion. */
final readonly class SavedCourseRulePreview
{
    public function __construct(private PublishedCourseReader $courses)
    {
    }

    /**
     * Caller supplies verified current work under its student lock and shared replay budget.
     * @param list<array<string,mixed>> $work
     * @param array<string,mixed> $requirements
     * @return list<array<string,mixed>>
     * @throws JsonException
     */
    public function preview(#[\SensitiveParameter] array $work, array $requirements, HistoryReadBudget $budget): array
    {
        $rules = $this->rules($requirements);
        if (count($rules) * count($work) > 5000 || count($work) > 200) {
            throw new AllocationBudgetExhaustedException();
        }
        $versions = [];
        $items = [];
        foreach ($rules as [$groupId, $groupName, $requirementId, $saved]) {
            foreach ($work as $item) {
                $course = new EntityId($item["course_id"]);
                $version = $item["course_version_id"];
                if ($version === null) {
                    $decision = $saved->rule->rules->decision(["course_id" => $course->value]);
                } else {
                    $key = $course->value . ":" . $version;
                    $published = $versions[$key] ??= $this->courses->version($course, new EntityId($version), $budget);
                    $decision = $saved->rule->decision($course, $published->definition);
                }
                $items[] = [
                    "work_id" => $item["id"],
                    "group_id" => $groupId,
                    "group_name" => $groupName,
                    "requirement_id" => $requirementId,
                    "course_code" => $item["course_code"],
                    "course_title" => $item["course_title"],
                    "rule_code" => $saved->snapshot["code"],
                    "rule_title" => $saved->snapshot["title"],
                    "rule_revision" => $saved->snapshot["revision"],
                    "outcome" => $decision === null ? "unknown" : ($decision ? "matches" : "does_not_match"),
                ];
            }
        }
        return $items;
    }
    /** Matching retained rule cards only; no student work or grade/credit eligibility is invented.
     * @param array<string,mixed> $requirements
     * @return list<array<string,mixed>>
     */
    public function placementGroups(
        EntityId $course,
        EntityId $version,
        array $requirements,
        HistoryReadBudget $budget,
    ): array {
        $rules = $this->rules($requirements);
        if (count($rules) > 5000) {
            throw new AllocationBudgetExhaustedException();
        }
        if ($rules === []) {
            return [];
        }
        $published = $this->courses->version($course, $version, $budget);
        $groups = [];
        foreach ($rules as [$id, $name, $requirement, $saved]) {
            if ($saved->rule->decision($course, $published->definition) !== true) {
                continue;
            }
            $groups[$id] ??= [
                "id" => $id,
                "name" => $name,
                "version_id" => $requirements["group_details"][$id]["version_id"],
                "rules" => [],
            ];
            $groups[$id]["rules"][] = [
                "id" => $requirement,
                "code" => $saved->snapshot["code"],
                "title" => $saved->snapshot["title"],
                "revision" => $saved->snapshot["revision"],
            ];
        }
        foreach ($groups as &$group) {
            usort($group["rules"], static fn(array $a, array $b): int => strcmp($a["id"], $b["id"]));
        }
        unset($group);
        return array_values($groups);
    }

    /** @param array<string,mixed> $requirements
     * @return list<array{string,string,string,SavedCourseRule}>
     */
    private function rules(array $requirements): array
    {
        $rules = [];
        foreach ($requirements["group_details"] as $groupId => $group) {
            foreach ($group["definition"]["rules"] as $rule) {
                if (isset($rule["eligibility"])) {
                    $rules[] = [
                        $groupId,
                        $group["definition"]["name"],
                        $rule["id"],
                        new SavedCourseRule($rule["eligibility"]),
                    ];
                }
            }
        }
        return $rules;
    }
}
