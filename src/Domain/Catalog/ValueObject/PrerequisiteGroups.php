<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Complete one course from every group. The empty set means no prerequisites. */
final readonly class PrerequisiteGroups
{
    /** @var list<list<string>> */
    public array $groups;

    /** @param list<list<string>> $groups */
    public function __construct(array $groups)
    {
        if (!array_is_list($groups) || count($groups) > 100) {
            throw new InvalidArgumentException('Use up to 100 prerequisite courses in distinct choice groups.');
        }
        $ids = [];
        foreach ($groups as &$group) {
            if (!is_array($group) || !array_is_list($group) || $group === [] || count($group) > 100) {
                throw new InvalidArgumentException('Every prerequisite group needs at least one course.');
            }
            foreach ($group as $id) {
                if (!is_string($id)) {
                    throw new InvalidArgumentException('Use course identities in prerequisite groups.');
                }
                new EntityId($id);
                $ids[] = $id;
            }
            sort($group, SORT_STRING);
        }
        unset($group);
        if (count($ids) > 100 || count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('Use each prerequisite course once, with at most 100 courses.');
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
        $ids = array_merge([], ...$this->groups);
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @param list<string> $completed */
    public function missing(array $completed): int
    {
        return count(array_filter($this->groups, static fn(array $group): bool =>
            array_intersect($group, $completed) === []));
    }

    /** Preserve choices when cloning only the explicitly selected prerequisite courses.
     * @param list<string> $selected */
    public function subset(array $selected): self
    {
        return new self(array_values(array_filter(array_map(static fn(array $group): array =>
            array_values(array_intersect($group, $selected)), $this->groups))));
    }
}
