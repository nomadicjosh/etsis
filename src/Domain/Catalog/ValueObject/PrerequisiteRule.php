<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use InvalidArgumentException;

/** Bounded counted branches over response-local requirement positions, not student identities. */
final readonly class PrerequisiteRule
{
    public array $definition;

    public function __construct(array $definition, int $requirements)
    {
        if ($requirements < 1 || $requirements > 100) {
            throw new InvalidArgumentException(
                "A nested prerequisite rule needs one to one hundred requirements.",
            );
        }
        $seen = [];
        $nodes = 0;
        $this->definition = self::validate($definition, $requirements, $seen, $nodes, 1);
        sort($seen, SORT_NUMERIC);
        if ($seen !== range(0, $requirements - 1)) {
            throw new InvalidArgumentException(
                "Place every prerequisite requirement in the rule exactly once.",
            );
        }
    }

    private static function validate(
        array $branch,
        int $requirements,
        array &$seen,
        int &$nodes,
        int $depth,
    ): array {
        if (
            $depth > 5 ||
            ++$nodes > 199 ||
            count($branch) !== 2 ||
            array_diff(array_keys($branch), ["minimum", "children"]) !== [] ||
            !is_int($branch["minimum"] ?? null) ||
            !is_array($branch["children"] ?? null) ||
            !array_is_list($branch["children"]) ||
            $branch["children"] === [] ||
            count($branch["children"]) > 100 ||
            $branch["minimum"] < 1 ||
            $branch["minimum"] > count($branch["children"])
        ) {
            throw new InvalidArgumentException(
                "Use up to five rule levels with valid counts and nonempty branches.",
            );
        }
        $children = [];
        foreach ($branch["children"] as $child) {
            if (is_array($child)) {
                $children[] = self::validate($child, $requirements, $seen, $nodes, $depth + 1);
            } elseif (is_int($child) && $child >= 0 && $child < $requirements && ++$nodes <= 199) {
                $seen[] = $child;
                $children[] = $child;
            } else {
                throw new InvalidArgumentException(
                    "Use existing requirement positions in the prerequisite rule.",
                );
            }
        }
        return ["minimum" => $branch["minimum"], "children" => $children];
    }

    /** A positive result counts additional unmet requirement blocks, never additional courses or credits. */
    public function missing(array $met): int
    {
        return self::shortfall($this->definition, $met);
    }

    private static function shortfall(array $branch, array $met): int
    {
        $costs = array_map(
            static fn(int|array $child): int => is_int($child)
                ? (int) !($met[$child] ?? false)
                : self::shortfall($child, $met),
            $branch["children"],
        );
        sort($costs, SORT_NUMERIC);
        return array_sum(array_slice($costs, 0, $branch["minimum"]));
    }

    /** Curated branch/requirement status for staff and student readiness; no supporting record references. */
    public function status(array $met): array
    {
        return self::branchStatus($this->definition, $met);
    }

    private static function branchStatus(array $branch, array $met): array
    {
        $children = array_map(
            static fn(int|array $child): array => is_int($child)
                ? ["requirement" => $child, "met" => (bool) ($met[$child] ?? false)]
                : self::branchStatus($child, $met),
            $branch["children"],
        );
        $completed = count(array_filter($children, static fn(array $child): bool => $child["met"]));
        return [
            "required_count" => $branch["minimum"],
            "completed_count" => $completed,
            "met" => $completed >= $branch["minimum"],
            "children" => $children,
        ];
    }
}
