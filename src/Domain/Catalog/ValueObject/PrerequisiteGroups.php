<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Count qualifying course requirements under a flat count or a bounded nested rule. */
final readonly class PrerequisiteGroups
{
    /** @var list<list<string>> */
    public array $groups;

    /** @param list<list<string>> $groups
     * @param list<int>|null $minimums Absent on earlier one-course choice publications.
     * @param list<int|null>|null $minimumGradePointsMilli Absent means passing grade only.
     * @param int|null $minimumRequirements Absent means every requirement must be met. */
    public ?array $corequisites;
    public ?PrerequisiteRule $rule;
    public ?array $completionWindows;
    /** @var array<string,list<string>>|null One-way, publication-scoped alternatives for named courses. */
    public ?array $equivalents;

    public function __construct(
        array $groups,
        public ?array $minimums = null,
        public ?array $minimumGradePointsMilli = null,
        ?array $corequisites = null,
        public ?int $minimumRequirements = null,
        ?array $rule = null,
        public ?string $creditOrigin = null,
        public ?array $requirementCreditOrigins = null,
        ?array $completionWindows = null,
        ?array $equivalents = null,
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
        if ($minimumRequirements !== null && ($minimumRequirements < 1 || $minimumRequirements > count($groups))) {
            throw new InvalidArgumentException('Require at least one and no more than the number of prerequisites.');
        }
        if (
            $creditOrigin !== null && (!in_array($creditOrigin, ['either', 'institutional', 'transfer'], true)
            || $groups === [])
        ) {
            throw new InvalidArgumentException('Choose institutional, transfer or either prerequisite coursework.');
        }
        $ids = [];
        if (
            $requirementCreditOrigins !== null && ($groups === [] || !array_is_list($requirementCreditOrigins)
            || count($requirementCreditOrigins) !== count($groups))
        ) {
            throw new InvalidArgumentException('Provide one optional coursework-source override per requirement.');
        }
        foreach ($requirementCreditOrigins ?? [] as $origin) {
            if ($origin !== null && !in_array($origin, ['either', 'institutional', 'transfer'], true)) {
                throw new InvalidArgumentException('Choose inherited, institutional, transfer or either coursework.');
            }
        }
        if ($completionWindows !== null) {
            if ($groups === [] || !array_is_list($completionWindows) || count($completionWindows) !== count($groups)) {
                throw new InvalidArgumentException(
                    \Codefy\Framework\Helpers\trans('Provide one optional completion window per requirement.')
                );
            }
            foreach ($completionWindows as $window) {
                if ($window !== null) {
                    if (!is_array($window)) {
                        throw new InvalidArgumentException(
                            \Codefy\Framework\Helpers\trans('Provide a completion window or leave it unrestricted.')
                        );
                    }
                    new PrerequisiteCompletionWindow($window);
                }
            }
        }
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
        if ($equivalents !== null) {
            $edges = 0;
            foreach ($equivalents as $required => &$alternatives) {
                if (
                    !is_string($required) || !in_array($required, $ids, true)
                    || !is_array($alternatives) || !array_is_list($alternatives) || $alternatives === []
                    || count($alternatives) > 100
                    || count(array_unique($alternatives, SORT_REGULAR)) !== count($alternatives)
                ) {
                    throw new InvalidArgumentException(\Codefy\Framework\Helpers\trans(
                        'Choose distinct equivalent courses for an existing prerequisite course.'
                    ));
                }
                foreach ($alternatives as $alternative) {
                    if (!is_string($alternative) || $alternative === $required) {
                        throw new InvalidArgumentException(\Codefy\Framework\Helpers\trans(
                            'An equivalent must be a different course identity.'
                        ));
                    }
                    new EntityId($alternative);
                }
                $edges += count($alternatives);
                sort($alternatives, SORT_STRING);
            }
            unset($alternatives);
            ksort($equivalents, SORT_STRING);
            if (
                $edges > 500 || count(array_unique([
                ...$ids, ...($corequisites ?? []), ...array_merge([], ...array_values($equivalents)),
                ])) > 100
            ) {
                throw new InvalidArgumentException(\Codefy\Framework\Helpers\trans(
                    'Use up to 100 distinct entry-requirement courses and 500 equivalent links.'
                ));
            }
        }
        $this->equivalents = $equivalents;
        $this->corequisites = $corequisites;
        if ($rule !== null && $minimumRequirements !== null) {
            throw new InvalidArgumentException('Choose an overall count or a nested rule, not both.');
        }
        $this->rule = $rule === null ? null : new PrerequisiteRule($rule, count($groups));
        $this->completionWindows = $completionWindows === null ? null : array_map(
            static fn(?array $window): ?array => $window === null ? null
                : new PrerequisiteCompletionWindow($window)->definition,
            $completionWindows,
        );
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
        $ids = array_values(array_unique([...$this->candidateCourses(), ...($this->corequisites ?? [])]));
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @return list<string> Actual courses that can support the named prerequisites; no transitive inference. */
    public function candidateCourses(): array
    {
        $ids = array_values(array_unique([
            ...$this->courses(), ...array_merge([], ...array_values($this->equivalents ?? [])),
        ]));
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** Keep the original named-course positions, followed by equivalent-course labels. */
    public function displayCourses(): array
    {
        return [...$this->courses(), ...array_values(array_diff($this->candidateCourses(), $this->courses()))];
    }

    private function inRequirement(int $index, string $course): bool
    {
        foreach ($this->groups[$index] as $required) {
            if ($required === $course || in_array($course, $this->equivalents[$required] ?? [], true)) {
                return true;
            }
        }
        return false;
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
        $met = [];
        $counts = $this->counts();
        foreach ($this->groups as $index => $group) {
            $met[$index] = count($this->completedInGroup($index, $completed, $points)) >= $counts[$index];
        }
        return $this->missingFromOutcomes($met);
    }

    /** The caller qualifies actual coursework separately for each requirement. */
    public function missingFromOutcomes(array $met): int
    {
        return $this->rule?->missing($met) ?? max(
            0,
            $this->requiredRequirements() - count(array_filter($met)),
        );
    }

    public function originForRequirement(int $index): string
    {
        return $this->requirementCreditOrigins[$index] ?? $this->creditOrigin ?? 'either';
    }

    /** A course may occur in several requirements with different source policies. */
    public function acceptsOrigin(string $course, string $origin): bool
    {
        foreach ($this->groups as $index => $group) {
            $policy = $this->originForRequirement($index);
            if ($this->inRequirement($index, $course) && ($policy === 'either' || $policy === $origin)) {
                return true;
            }
        }
        return false;
    }

    public function hasRecency(): bool
    {
        return array_any($this->completionWindows ?? [], static fn(?array $window): bool =>
            isset($window['max_age_months']));
    }

    public function acceptsCompletion(int $index, ?string $completedOn, ?string $startsOn = null): bool
    {
        $window = $this->completionWindows[$index] ?? null;
        return $window === null || new PrerequisiteCompletionWindow($window)->accepts($completedOn, $startsOn);
    }

    public function acceptsWork(string $course, string $origin, ?string $completedOn, ?string $startsOn = null): bool
    {
        foreach ($this->groups as $index => $group) {
            $policy = $this->originForRequirement($index);
            if (
                $this->inRequirement($index, $course) && ($policy === 'either' || $policy === $origin)
                && $this->acceptsCompletion($index, $completedOn, $startsOn)
            ) {
                return true;
            }
        }
        return false;
    }

    public function requiredRequirements(): int
    {
        return $this->minimumRequirements ?? count($this->groups);
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
        $available = array_values(array_filter(
            array_intersect($this->candidateCourses(), $completed),
            static fn(string $course): bool => $floor === null ||
            (isset($points[$course]) && $points[$course] >= $floor)
        ));
        // Maximum bipartite matching: one actual course fills at most one named slot in a requirement.
        // Reassign an earlier match when needed; greedy assignment can reject a feasible completion.
        $matches = [];
        $visit = function (string $required, array &$seen) use (&$visit, &$matches, $available): bool {
            foreach ([$required, ...($this->equivalents[$required] ?? [])] as $candidate) {
                if (isset($seen[$candidate]) || !in_array($candidate, $available, true)) {
                    continue;
                }
                $seen[$candidate] = true;
                if (!isset($matches[$candidate]) || $visit($matches[$candidate], $seen)) {
                    $matches[$candidate] = $required;
                    return true;
                }
            }
            return false;
        };
        foreach ($this->groups[$index] as $required) {
            $seen = [];
            $visit($required, $seen);
        }
        $met = array_values($matches);
        sort($met, SORT_STRING);
        return $met;
    }

    /** Preserve original minima when cloning or removing an indexed course.
     * @param list<string> $selected */
    public function subset(array $selected): self
    {
        $groups = $minimums = $floors = $origins = $windows = [];
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
            $origins[] = $this->requirementCreditOrigins[$index] ?? null;
            $windows[] = $this->completionWindows[$index] ?? null;
        }
        if ($groups !== [] && $this->minimumRequirements !== null && count($groups) < $this->minimumRequirements) {
            throw new ValidationException([['field' => 'prerequisites',
                'message' => 'Keep enough prerequisite requirements to meet the retained overall minimum.']]);
        }
        if ($groups !== [] && $this->rule !== null && count($groups) !== count($this->groups)) {
            throw new ValidationException([['field' => 'prerequisites',
                'message' => 'Keep every nested requirement or clear all prerequisites; ' .
                    'edit the new draft to restructure it.']]);
        }
        $equivalents = $this->equivalents === null ? null : array_intersect_key(
            $this->equivalents,
            array_fill_keys(array_merge([], ...$groups), true),
        );
        foreach ($equivalents ?? [] as $alternatives) {
            if (array_diff($alternatives, $selected) !== []) {
                throw new ValidationException([['field' => 'prerequisites',
                    'message' => \Codefy\Framework\Helpers\trans(
                        'Keep every equivalent of a retained prerequisite or omit that prerequisite entirely.'
                    )]]);
            }
        }
        return new self(
            $groups,
            $this->minimums === null ? null : $minimums,
            $this->minimumGradePointsMilli === null ? null : $floors,
            $this->corequisites === null ? null : array_values(array_intersect($this->corequisites, $selected)),
            $groups === [] ? null : $this->minimumRequirements,
            $groups === [] ? null : $this->rule?->definition,
            $groups === [] ? null : $this->creditOrigin,
            $groups === [] || $this->requirementCreditOrigins === null ? null : $origins,
            $groups === [] || $this->completionWindows === null ? null : $windows,
            $equivalents,
        );
    }
}
