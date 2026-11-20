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
     * @param list<int>|null $minimums Absent on earlier one-course choice publications. */
    public function __construct(array $groups, public ?array $minimums = null)
    {
        if (
            !array_is_list($groups) || count($groups) > 100 ||
            ($minimums !== null && (!array_is_list($minimums) || count($minimums) !== count($groups)))
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
            sort($group, SORT_STRING);
        }
        unset($group);
        if (count($ids) > 500 || count(array_unique($ids)) > 100) {
            throw new InvalidArgumentException('Use up to 100 distinct courses and 500 total prerequisite choices.');
        }
        $this->groups = $groups;
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

    /** @return list<int> */
    public function counts(): array
    {
        return $this->minimums ?? array_fill(0, count($this->groups), 1);
    }

    /** @param list<string> $completed */
    public function missing(array $completed): int
    {
        $missing = 0;
        $counts = $this->counts();
        foreach ($this->groups as $index => $group) {
            $missing += (int) (count(array_intersect($group, $completed)) < $counts[$index]);
        }
        return $missing;
    }

    /** Preserve original minima when cloning or removing an indexed course.
     * @param list<string> $selected */
    public function subset(array $selected): self
    {
        $groups = $minimums = [];
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
        }
        return new self($groups, $this->minimums === null ? null : $minimums);
    }
}
