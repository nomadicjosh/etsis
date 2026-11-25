<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Catalog\ValueObject\PrerequisiteCompletionWindow;
use Domain\Shared\ValueObject\EntityId;

/** One locked eligibility decision. Its retained references describe acceptance, not today's grades. */
final readonly class PrerequisiteDecision implements \JsonSerializable
{
    /** @var list<string> */
    public array $required;
    /** @var list<array<string,mixed>> */
    public array $support;

    /** @param array<mixed> $required Untrusted recorded data is validated before assignment.
     * @param array<mixed> $support
     */
    public function __construct(
        public EntityId $assignment,
        array $required,
        #[\SensitiveParameter] array $support,
        public ?\Domain\Catalog\ValueObject\PrerequisiteGroups $groups = null,
        public ?PrerequisitePermissionReference $permission = null,
        public ?array $completionAnchor = null,
    ) {
        if ($completionAnchor !== null) {
            if (
                !$groups?->hasRecency() || count($completionAnchor) !== 2
                || array_diff(array_keys($completionAnchor), ['section_version_id', 'starts_on']) !== []
                || !is_string($completionAnchor['starts_on'] ?? null)
                || !PrerequisiteCompletionWindow::validDate($completionAnchor['starts_on'])
            ) {
                throw new CorruptHistoryException();
            }
            self::id($completionAnchor['section_version_id'] ?? null);
        }
        if (!array_is_list($required) || count($required) > 100 || !array_is_list($support)) {
            throw new CorruptHistoryException();
        }
        foreach ($required as $id) {
            self::id($id);
        }
        /** @var list<string> $required */
        $ordered = array_values(array_unique($required, SORT_REGULAR));
        sort($ordered, SORT_STRING);
        $multipleOrigins = $groups?->requirementCreditOrigins !== null;
        $windows = $groups?->completionWindows !== null;
        $maximumSupport = $windows ? 200 : count($required) * ($multipleOrigins ? 2 : 1);
        if ($ordered !== $required || count($support) > $maximumSupport) {
            throw new CorruptHistoryException();
        }
        $courses = $credits = [];
        foreach ($support as $reference) {
            $keys = [
                "course_id",
                "credit_id",
                "origin",
                "source_id",
                "academic_version",
                "registration_version",
                "pursuit_version",
            ];
            if (!is_array($reference)) {
                throw new CorruptHistoryException();
            }
            if ($groups?->minimumGradePointsMilli !== null) {
                $keys[] = 'grade_points_milli';
                if (
                    !is_int($reference['grade_points_milli'] ?? null) ||
                    $reference['grade_points_milli'] < 0 || $reference['grade_points_milli'] > 10000
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if ($windows) {
                $keys[] = 'completed_on';
                if (
                    !array_key_exists('completed_on', $reference)
                    || ($reference['completed_on'] !== null
                    && (!is_string($reference['completed_on'])
                        || !PrerequisiteCompletionWindow::validDate($reference['completed_on'])))
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if (
                count($reference) !== count($keys) ||
                array_diff(array_keys($reference), $keys) !== []
            ) {
                throw new CorruptHistoryException();
            }
            foreach (["course_id", "credit_id", "source_id"] as $key) {
                self::id($reference[$key]);
            }
            if (!in_array($reference['origin'], ['institutional', 'transfer'], true)) {
                throw new CorruptHistoryException();
            }
            $courseKey = $reference['course_id'] . ($windows
                ? ':' . $reference['origin'] . ':' . ($reference['completed_on'] ?? '')
                : ($multipleOrigins ? ':' . $reference['origin'] : ''));
            if (
                ($groups !== null && !$groups->acceptsWork(
                    $reference['course_id'],
                    $reference['origin'],
                    $reference['completed_on'] ?? null,
                    $completionAnchor['starts_on'] ?? null,
                )) ||
                !in_array($reference["course_id"], $required, true) ||
                isset($courses[$courseKey]) ||
                isset($credits[$reference["credit_id"]])
            ) {
                throw new CorruptHistoryException();
            }
            self::version($reference["academic_version"], 1);
            self::version($reference["pursuit_version"], 1);
            if ($reference["origin"] === "institutional") {
                self::version($reference["registration_version"], 0);
            } elseif ($reference["registration_version"] !== null) {
                throw new CorruptHistoryException();
            }
            $courses[$courseKey] = true;
            $credits[$reference["credit_id"]] = true;
        }
        $orderedCourses = array_keys($courses);
        sort($orderedCourses, SORT_STRING);
        if ($orderedCourses !== array_keys($courses)) {
            throw new CorruptHistoryException();
        }
        if ($groups !== null && $groups->courses() !== $required) {
            throw new CorruptHistoryException();
        }
        if (
            $permission !== null &&
            ($permission->data['pursuit_id'] !== $assignment->value || $required === [])
        ) {
            throw new CorruptHistoryException();
        }
        $this->required = $required;
        /** @var list<array<string,mixed>> $support */
        $this->support = $support;
    }

    public function isMet(): bool
    {
        return $this->permission !== null || $this->missingCount() === 0;
    }

    /** Response-local positions match the shared prerequisite list; no supporting record identities.
     * @return list<string>
     */
    public function courseStatuses(): array
    {
        $completed = array_column($this->support, 'course_id');
        if (
            $this->groups?->minimumGradePointsMilli !== null || $this->groups?->requirementCreditOrigins !== null
            || $this->groups?->completionWindows !== null
        ) {
            $completed = [];
            foreach (array_keys($this->groups->groups) as $index) {
                $completed = [...$completed, ...$this->qualifying($index)];
            }
        }
        $supported = array_fill_keys($completed, true);
        return array_map(
            static fn(string $id): string => isset($supported[$id]) ? "met" : "missing",
            $this->required,
        );
    }

    /** Safe response-local course positions, with no supporting academic identities.
     * @return list<array<string,mixed>>|null */
    public function groupStatuses(): ?array
    {
        if ($this->groups === null) {
            return null;
        }
        $positions = array_flip($this->required);
        $counts = $this->groups->counts();
        return array_map(function (array $group, int $index) use ($positions, $counts): array {
            $completed = count($this->qualifying($index));
            return ['positions' => array_map(static fn(string $id): int => $positions[$id], $group),
                'met' => $completed >= $counts[$index], 'required_count' => $counts[$index],
                'completed_count' => $completed] + ($this->groups->minimumGradePointsMilli === null ? [] :
                ['minimum_grade_points_milli' => $this->groups->minimumGradePointsMilli[$index]]) +
                ($this->groups->requirementCreditOrigins === null ? [] :
                ['credit_origin' => $this->groups->originForRequirement($index)]) +
                ($this->groups->completionWindows === null ? [] :
                ['completion_window' => $this->groups->completionWindows[$index]]) +
                (!isset($this->groups->completionWindows[$index]['max_age_months']) ? [] :
                ['completion_anchor' => $this->completionAnchor === null ? null : [
                    'starts_on' => $this->completionAnchor['starts_on'],
                    'earliest_completion_on' => new PrerequisiteCompletionWindow(
                        $this->groups->completionWindows[$index]
                    )->earliestCompletion($this->completionAnchor['starts_on']),
                ]]);
        }, $this->groups->groups, array_keys($this->groups->groups));
    }

    /** Safe counts; supporting academic identities remain protected. */
    public function groupPolicy(): ?array
    {
        if ($this->groups === null) {
            return null;
        }
        $origin = $this->groups->creditOrigin === null ? [] : ['credit_origin' => $this->groups->creditOrigin];
        if ($this->groups->rule !== null) {
            return ['rule' => $this->groups->rule->status(array_column($this->groupStatuses(), 'met'))] + $origin;
        }
        return ['required_count' => $this->groups->requiredRequirements(),
            'total_count' => count($this->groups->groups),
            'completed_count' => count(array_filter(
                $this->groupStatuses(),
                static fn(array $g): bool => $g['met']
            ))] + $origin;
    }

    /** @return list<string> */
    private function qualifying(int $index): array
    {
        $points = [];
        $origin = $this->groups->originForRequirement($index);
        foreach ($this->support as $reference) {
            if (
                ($origin === 'either' || $reference['origin'] === $origin)
                && $this->groups->acceptsCompletion(
                    $index,
                    $reference['completed_on'] ?? null,
                    $this->completionAnchor['starts_on'] ?? null,
                )
            ) {
                $course = $reference['course_id'];
                $points[$course] = max($points[$course] ?? 0, $reference['grade_points_milli'] ?? 0);
            }
        }
        return $this->groups->completedInGroup(
            $index,
            array_keys($points),
            $points,
        );
    }

    public function missingCount(): int
    {
        return $this->groups === null ? count($this->required) - count($this->support) :
        $this->groups->missingFromOutcomes(array_column($this->groupStatuses(), 'met'));
    }

    /** @return array<string,mixed> */
    public function retained(): array
    {
        if (!$this->isMet()) {
            throw new CorruptHistoryException();
        }
        return $this->facts();
    }

    public function revision(): string
    {
        $facts = $this->facts();
        foreach ($facts["support"] as &$reference) {
            ksort($reference, SORT_STRING);
        }
        unset($reference);
        return hash("sha256", json_encode($facts, JSON_THROW_ON_ERROR));
    }

    /** Strict recorded-evidence reader; an incomplete decision cannot describe accepted registration.
     * @param array<string,mixed> $facts
     */
    public static function fromRetained(#[\SensitiveParameter] array $facts): self
    {
        $keys = ['assignment_id', 'required_course_ids', 'support'];
        if (array_key_exists('permission', $facts)) {
            if (!is_array($facts['permission'])) {
                throw new CorruptHistoryException();
            }
            $keys[] = 'permission';
        }
        if (array_key_exists('requirement_groups', $facts)) {
            $keys[] = 'requirement_groups';
        }
        if (array_key_exists('requirement_minimums', $facts)) {
            if (!array_key_exists('requirement_groups', $facts) || !is_array($facts['requirement_minimums'])) {
                throw new CorruptHistoryException();
            }
            $keys[] = 'requirement_minimums';
        }
        if (array_key_exists('requirement_grade_points_milli', $facts)) {
            if (
                !array_key_exists('requirement_groups', $facts) ||
                !is_array($facts['requirement_grade_points_milli'])
            ) {
                throw new CorruptHistoryException();
            }
            $keys[] = 'requirement_grade_points_milli';
        }
        if (array_key_exists('requirement_minimum', $facts)) {
            if (!array_key_exists('requirement_groups', $facts) || !is_int($facts['requirement_minimum'])) {
                throw new CorruptHistoryException();
            }
            $keys[] = 'requirement_minimum';
        }
        if (array_key_exists('credit_origin', $facts)) {
            if (!array_key_exists('requirement_groups', $facts) || !is_string($facts['credit_origin'])) {
                throw new CorruptHistoryException();
            }
            $keys[] = 'credit_origin';
        }
        if (array_key_exists('requirement_credit_origins', $facts)) {
            if (!array_key_exists('requirement_groups', $facts) || !is_array($facts['requirement_credit_origins'])) {
                throw new CorruptHistoryException();
            }
            $keys[] = 'requirement_credit_origins';
        }
        if (array_key_exists('completion_windows', $facts)) {
            if (!array_key_exists('requirement_groups', $facts) || !is_array($facts['completion_windows'])) {
                throw new CorruptHistoryException();
            }
            $keys[] = 'completion_windows';
        }
        if (array_key_exists('completion_anchor', $facts)) {
            if ($facts['completion_anchor'] !== null && !is_array($facts['completion_anchor'])) {
                throw new CorruptHistoryException();
            }
            $keys[] = 'completion_anchor';
        }
        if (array_key_exists('requirement_rule', $facts)) {
            if (!array_key_exists('requirement_groups', $facts) || !is_array($facts['requirement_rule'])) {
                throw new CorruptHistoryException();
            }
            $keys[] = 'requirement_rule';
        }
        if (
            count($facts) !== count($keys) ||
            array_diff(array_keys($facts), $keys) !== [] ||
            !is_array($facts["required_course_ids"]) ||
            !is_array($facts["support"]) ||
            (array_key_exists("requirement_groups", $facts) && !is_array($facts["requirement_groups"]))
        ) {
            throw new CorruptHistoryException();
        }
        self::id($facts["assignment_id"]);
        try {
            $decision = new self(
                new EntityId($facts["assignment_id"]),
                $facts["required_course_ids"],
                $facts["support"],
                array_key_exists('requirement_groups', $facts) ?
                    new \Domain\Catalog\ValueObject\PrerequisiteGroups(
                        $facts['requirement_groups'],
                        $facts['requirement_minimums'] ?? null,
                        $facts['requirement_grade_points_milli'] ?? null,
                        minimumRequirements: $facts['requirement_minimum'] ?? null,
                        rule: $facts['requirement_rule'] ?? null,
                        creditOrigin: $facts['credit_origin'] ?? null,
                        requirementCreditOrigins: $facts['requirement_credit_origins'] ?? null,
                        completionWindows: $facts['completion_windows'] ?? null,
                    ) : null,
                permission: isset($facts['permission'])
                    ? new PrerequisitePermissionReference($facts['permission']) : null,
                completionAnchor: $facts['completion_anchor'] ?? null,
            );
        } catch (\InvalidArgumentException) {
            throw new CorruptHistoryException();
        }
        if (
            !$decision->isMet()
            || (bool) $decision->groups?->hasRecency() !== array_key_exists('completion_anchor', $facts)
        ) {
            throw new CorruptHistoryException();
        }
        return $decision;
    }

    /** @return array<string,mixed> */
    private function facts(): array
    {
        return [
            "assignment_id" => $this->assignment->value,
            "required_course_ids" => $this->required,
            "support" => $this->support,
        ] + ($this->groups === null ? [] : ['requirement_groups' => $this->groups->groups]) +
        ($this->groups?->minimums === null ? [] : ['requirement_minimums' => $this->groups->minimums]) +
        ($this->groups?->minimumGradePointsMilli === null ? [] :
        ['requirement_grade_points_milli' => $this->groups->minimumGradePointsMilli]) +
        ($this->groups?->minimumRequirements === null ? [] :
        ['requirement_minimum' => $this->groups->minimumRequirements]) +
        ($this->groups?->rule === null ? [] : ['requirement_rule' => $this->groups->rule->definition]) +
        ($this->groups?->creditOrigin === null ? [] : ['credit_origin' => $this->groups->creditOrigin]) +
        ($this->groups?->requirementCreditOrigins === null ? [] :
        ['requirement_credit_origins' => $this->groups->requirementCreditOrigins]) +
        ($this->groups?->completionWindows === null ? [] : ['completion_windows' => $this->groups->completionWindows]) +
        ($this->groups?->hasRecency() ? ['completion_anchor' => $this->completionAnchor] : []) +
        ($this->permission === null ? [] : ['permission' => $this->permission->data]);
    }

    private static function id(mixed $value): void
    {
        if (!is_string($value)) {
            throw new CorruptHistoryException();
        }
        try {
            new EntityId($value);
        } catch (\InvalidArgumentException) {
            throw new CorruptHistoryException();
        }
    }

    private static function version(mixed $value, int $minimum): void
    {
        if (!is_int($value) || $value < $minimum || $value > 2147483646) {
            throw new CorruptHistoryException();
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["prerequisites" => "[protected registration evidence]"];
    }

    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
