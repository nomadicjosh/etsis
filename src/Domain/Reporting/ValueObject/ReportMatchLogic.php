<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

use Domain\Shared\Exception\ValidationException;

/** Every related-record card participates once; grouping never removes a source's permission requirement. */
final class ReportMatchLogic
{
    /** @param list<string> $relations
     * @return array<string,mixed> */
    public static function resolve(mixed $logic, array $relations): array
    {
        if ($logic === null) {
            return [
                "condition" => "AND",
                "rules" => array_map(static fn(string $id): array => ["relation" => $id], $relations),
            ];
        }
        $seen = [];
        $nodes = 0;
        self::group($logic, $relations, $seen, 0, $nodes);
        if (count($seen) !== count($relations)) {
            self::invalid();
        }
        return $logic;
    }

    private static function group(mixed $group, array $relations, array &$seen, int $depth, int &$nodes): void
    {
        if (
            ++$nodes > 18 ||
            $depth > 3 ||
            !is_array($group) ||
            count($group) !== 2 ||
            !in_array($group["condition"] ?? null, ["AND", "OR"], true) ||
            !is_array($group["rules"] ?? null) ||
            !array_is_list($group["rules"]) ||
            count($group["rules"]) > 6 ||
            ($group["rules"] === [] && ($depth > 0 || $relations !== []))
        ) {
            self::invalid();
        }
        foreach ($group["rules"] as $rule) {
            if (is_array($rule) && array_key_exists("condition", $rule)) {
                self::group($rule, $relations, $seen, $depth + 1, $nodes);
                continue;
            }
            if (
                ++$nodes > 18 ||
                !is_array($rule) ||
                count($rule) !== 1 ||
                !is_string($rule["relation"] ?? null) ||
                !in_array($rule["relation"], $relations, true) ||
                isset($seen[$rule["relation"]])
            ) {
                self::invalid();
            }
            $seen[$rule["relation"]] = true;
        }
    }

    private static function invalid(): never
    {
        throw new ValidationException([
            [
                "field" => "existence_logic",
                "message" =>
                    "Place every related-record condition in one All or Any group. " .
                    "Use up to four levels and no empty groups.",
            ],
        ]);
    }
}
