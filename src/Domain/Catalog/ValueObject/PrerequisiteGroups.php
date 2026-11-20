<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Meet each group's distinct-course minimum. Empty groups mean no prerequisites. */
final readonly class PrerequisiteGroups
{
    /** @var list<list<string>> */
    public array $groups;

    /** @param list<list<string>> $groups
     * @param list<int>|null $minimums Absent on earlier one-course choice publications.
     * @param list<int|null>|null $minimumGradePointsMilli Absent means passing grade only. */
    public ?array $corequisites;

    public function __construct(
        array $groups,
        public ?array $minimums = null,
        public ?array $minimumGradePointsMilli = null,
        ?array $corequisites = null,
    ) {
        if (
            !array_is_list($groups) || count($groups) > 100 ||
            ($minimums !== null && (!array_is_list($minimums) || count($minimums) !== count($groups))) ||
            ($minimumGradePointsMilli !== null && (!array_is_list($minimumGradePointsMilli) ||
                count($minimumGradePointsMilli) !== count($groups)))
        ) {
            throw new InvalidArgumentException(
                'Use up to 100 prerequisite requirements with a matching course minimum.'
            );
        }
        $ids = [];
        foreach ($groups as $index => &$group) {
            if (!is_array($group) || !array_is_list($group) || $group === [] || count($group) > 100) {
                throw new InvalidArgumentException('Every prerequisite requirement needs at least one course.');
            }
            foreach ($group as $id) {
                if (!is_string($id)) {
                    throw new InvalidArgumentException('Use course identities in prerequisite groups.');
                }
                new EntityId($id);
                $ids[] = $id;
            }
            $minimum = $minimums[$index] ?? 1;
            if (
                count(array_unique($group)) !== count($group) ||
                !is_int($minimum) || $minimum < 1 || $minimum > count($group) ||
                ($minimums !== null && $minimums[$index] === null)
            ) {
                throw new InvalidArgumentException(
                    'Use distinct courses within each requirement and a minimum from one to its course count.'
                );
            }
            $floor = $minimumGradePointsMilli[$index] ?? null;
            if ($floor !== null && (!is_int($floor) || $floor < 0 || $floor > 10000)) {
                throw new InvalidArgumentException(
                    'Use optional grade-point thousandths from zero through ten points.'
                );
            }
            sort($group, SORT_STRING);
        }
        unset($group);
        if (count($ids) > 500 || count(array_unique($ids)) > 100) {
            throw new InvalidArgumentException('Use up to 100 distinct courses and 500 total prerequisite choices.');
        }
        if (count(array_unique([...$ids, ...($corequisites ?? [])])) > 100) {
            throw new InvalidArgumentException('Use up to one hundred distinct course requirements.');
        }
        if ($corequisites !== null) {
            if (
                !array_is_list($corequisites) || count($corequisites) > 20 ||
                count(array_unique($corequisites)) !== count($corequisites)
            ) {
                throw new InvalidArgumentException('Choose up to twenty distinct co-requisite courses.');
            }
            foreach ($corequisites as $id) {
                if (!is_string($id)) {
                    throw new InvalidArgumentException('Use co-requisite course identities.');
                }
                new EntityId($id);
            }
            $sorted = $corequisites;
            sort($sorted, SORT_STRING);
            $corequisites = $sorted;
        }
        $this->groups = $groups;
        $this->corequisites = $corequisites;
    }

    /** @param list<string> $courses */
    public static function all(array $courses): self
    {
        return new self(array_map(static fn(string $id): array => [$id], $courses));
    }

    /** @return list<string> */
    public function courses(): array
    {
        $ids = array_values(array_unique(array_merge([], ...$this->groups)));
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @return list<string> */
    public function references(): array
    {
        $ids = array_values(array_unique([...$this->courses(), ...($this->corequisites ?? [])]));
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @return list<int> */
    public function counts(): array
    {
        return $this->minimums ?? array_fill(0, count($this->groups), 1);
    }

    /** @param list<string> $completed
     * @param array<string,int> $points */
    public function missing(array $completed, array $points = []): int
    {
        $missing = 0;
        $counts = $this->counts();
        foreach ($this->groups as $index => $group) {
            $missing += (int) (count($this->completedInGroup($index, $completed, $points)) < $counts[$index]);
        }
        return $missing;
    }

    /** @return list<int|null> */
    public function gradeFloors(): array
    {
        return $this->minimumGradePointsMilli ?? array_fill(0, count($this->groups), null);
    }

    /** @param list<string> $completed
     * @param array<string,int> $points Retained per-course grade points, never credit-weighted quality totals.
     * @return list<string> */
    public function completedInGroup(int $index, array $completed, array $points = []): array
    {
        $floor = $this->minimumGradePointsMilli[$index] ?? null;
        return array_values(array_filter(
            array_intersect($this->groups[$index], $completed),
            static fn(string $course): bool => $floor === null ||
            (isset($points[$course]) && $points[$course] >= $floor)
        ));
    }

    /** Preserve original minima when cloning or removing an indexed course.
     * @param list<string> $selected */
    public function subset(array $selected): self
    {
        $groups = $minimums = $floors = [];
        $counts = $this->counts();
        foreach ($this->groups as $index => $group) {
            $remaining = array_values(array_intersect($group, $selected));
            if ($remaining === []) {
                continue;
            }
            if (count($remaining) < $counts[$index]) {
                throw new ValidationException([['field' => 'prerequisites',
                    'message' => 'Keep enough courses to meet each retained minimum, ' .
                        'or omit that requirement entirely.',
                ]]);
            }
            $groups[] = $remaining;
            $minimums[] = $counts[$index];
            $floors[] = $this->minimumGradePointsMilli[$index] ?? null;
        }
        return new self(
            $groups,
            $this->minimums === null ? null : $minimums,
            $this->minimumGradePointsMilli === null ? null : $floors,
            $this->corequisites === null ? null : array_values(array_intersect($this->corequisites, $selected))
        );
    }
}
