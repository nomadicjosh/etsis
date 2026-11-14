<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Domain\Catalog\ValueObject\ComponentCourseSharing;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Explicit normalized component membership; repeated library groups need distinct occurrence identities. */
final readonly class AllocationSharing
{
    /** @param array<string,string> $groupComponents
     * @param array<string,CourseGroupReuse> $groupReuse
     */
    public function __construct(
        public array $groupComponents,
        public array $groupReuse,
        public ComponentCourseSharing $components,
    ) {
        if ($groupComponents === [] || count($groupComponents) > 25 || count($groupReuse) > 25) {
            throw new InvalidArgumentException('Provide a bounded complete component map.');
        }
        foreach ($groupComponents as $group => $component) {
            new EntityId($group);
            new EntityId($component);
            if (!($groupReuse[$component] ?? null) instanceof CourseGroupReuse) {
                throw new InvalidArgumentException('Retain each component’s within-group sharing choice.');
            }
        }
        if (array_diff(array_keys($groupReuse), array_values($groupComponents)) !== []) {
            throw new InvalidArgumentException('Component policies must match the supplied groups.');
        }
    }

    /** One course may appear once per group, subject to both independent policies.
     * @param list<string> $usedGroups
     */
    public function permits(array $usedGroups, string $group): bool
    {
        $component = $this->groupComponents[$group] ??
        throw new InvalidArgumentException('Unknown group occurrence.');
        foreach ($usedGroups as $used) {
            $previous = $this->groupComponents[$used] ??
            throw new InvalidArgumentException('Unknown group occurrence.');
            $sameComponent = $previous === $component;
            if (
                $used === $group || ($sameComponent && $this->groupReuse[$component] === CourseGroupReuse::OneGroup) ||
                (!$sameComponent && $this->components === ComponentCourseSharing::OneComponent)
            ) {
                return false;
            }
        }
        return true;
    }

    /** Sharing across components makes their searches independent; shared groups can be independent too.
     * @return list<list<string>>
     */
    public function partitions(): array
    {
        if ($this->components === ComponentCourseSharing::OneComponent) {
            return [array_keys($this->groupComponents)];
        }
        $parts = [];
        foreach ($this->groupComponents as $group => $component) {
            $key = $this->groupReuse[$component] === CourseGroupReuse::MultipleGroups
            ? 'group:' . $group : 'component:' . $component;
            $parts[$key][] = $group;
        }
        ksort($parts, SORT_STRING);
        return array_values($parts);
    }

    public function permitsMultipleGroups(): bool
    {
        $groups = array_keys($this->groupComponents);
        foreach ($groups as $i => $a) {
            foreach (array_slice($groups, $i + 1) as $b) {
                if ($this->permits([$a], $b)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param list<string> $groups */
    public function subset(array $groups): self
    {
        $map = array_intersect_key($this->groupComponents, array_fill_keys($groups, true));
        return new self(
            $map,
            array_intersect_key($this->groupReuse, array_fill_keys(array_values($map), true)),
            $this->components,
        );
    }
}
