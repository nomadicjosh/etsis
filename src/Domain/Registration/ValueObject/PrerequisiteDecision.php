<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
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
    ) {
        if (!array_is_list($required) || count($required) > 100 || !array_is_list($support)) {
            throw new CorruptHistoryException();
        }
        foreach ($required as $id) {
            self::id($id);
        }
        /** @var list<string> $required */
        $ordered = array_values(array_unique($required, SORT_REGULAR));
        sort($ordered, SORT_STRING);
        if ($ordered !== $required || count($support) > count($required)) {
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
            if (
                count($reference) !== count($keys) ||
                array_diff(array_keys($reference), $keys) !== []
            ) {
                throw new CorruptHistoryException();
            }
            foreach (["course_id", "credit_id", "source_id"] as $key) {
                self::id($reference[$key]);
            }
            if (
                !in_array($reference["origin"], ["institutional", "transfer"], true) ||
                !in_array($reference["course_id"], $required, true) ||
                isset($courses[$reference["course_id"]]) ||
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
            $courses[$reference["course_id"]] = true;
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
        $this->required = $required;
        /** @var list<array<string,mixed>> $support */
        $this->support = $support;
    }

    public function isMet(): bool
    {
        return $this->missingCount() === 0;
    }

    /** Response-local positions match the shared prerequisite list; no supporting record identities.
     * @return list<string>
     */
    public function courseStatuses(): array
    {
        $completed = array_column($this->support, 'course_id');
        if ($this->groups?->minimumGradePointsMilli !== null) {
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
                ['minimum_grade_points_milli' => $this->groups->minimumGradePointsMilli[$index]]);
        }, $this->groups->groups, array_keys($this->groups->groups));
    }

    /** @return list<string> */
    private function qualifying(int $index): array
    {
        return $this->groups->completedInGroup(
            $index,
            array_column($this->support, 'course_id'),
            array_column($this->support, 'grade_points_milli', 'course_id')
        );
    }

    public function missingCount(): int
    {
        return $this->groups === null ? count($this->required) - count($this->support) :
        $this->groups->missing(
            array_column($this->support, 'course_id'),
            array_column($this->support, 'grade_points_milli', 'course_id')
        );
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
                        $facts['requirement_grade_points_milli'] ?? null
                    ) : null,
            );
        } catch (\InvalidArgumentException) {
            throw new CorruptHistoryException();
        }
        if (!$decision->isMet()) {
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
        ['requirement_grade_points_milli' => $this->groups->minimumGradePointsMilli]);
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
