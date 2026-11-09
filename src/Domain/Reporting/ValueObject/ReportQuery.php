<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

use Domain\Shared\Exception\ValidationException;

final readonly class ReportQuery
{
    /** @param array<string, mixed> $definition
     * @param array<string, mixed> $schema
     */
    public function __construct(public array $definition, public array $schema)
    {
        if (($definition["dataset"] ?? null) !== $schema["id"]) {
            self::invalid("Choose a report dataset.");
        }
        $columns = $definition["columns"] ?? null;
        if (!is_array($columns) || !array_is_list($columns) || count($columns) < 1 || count($columns) > 16) {
            self::invalid("Choose between 1 and 16 output columns.");
        }
        foreach ($columns as $column) {
            if (
                !is_array($column) ||
                !is_string($column["field"] ?? null) ||
                !isset($schema["fields"][$column["field"]]) ||
                !is_string($column["label"] ?? null) ||
                trim($column["label"]) === "" ||
                mb_strlen($column["label"]) > 80 ||
                !mb_check_encoding($column["label"], "UTF-8") ||
                preg_match('/[\x00-\x1F\x7F]/', $column["label"])
            ) {
                self::invalid("Choose valid fields and readable column labels.");
            }
            $aggregate = $column["aggregate"] ?? null;
            if (!in_array($aggregate, ["none", "count", "count_distinct", "sum", "avg", "min", "max"], true)) {
                self::invalid("Choose a supported column summary.");
            }
            $type = $schema["fields"][$column["field"]]["type"];
            $calc = $column["calculation"] ?? null;
            if (
                $calc !== null &&
                (!is_array($calc) ||
                    !in_array($calc["operator"] ?? null, ["+", "-", "*", "/"], true) ||
                    !is_string($calc["field"] ?? null) ||
                    !isset($schema["fields"][$calc["field"]]) ||
                    !in_array($type, ["integer", "double"], true) ||
                    !in_array($schema["fields"][$calc["field"]]["type"], ["integer", "double"], true))
            ) {
                self::invalid("Choose numeric fields and a valid calculation.");
            }
            if (in_array($aggregate, ["sum", "avg"], true) && !in_array($type, ["integer", "double"], true)) {
                self::invalid("Sum and average require a numeric field.");
            }
            if (in_array($aggregate, ["min", "max"], true) && $type === "boolean") {
                self::invalid("Summarize Boolean values with counts.");
            }
        }
        $joins = $definition["joins"] ?? [];
        if (!is_array($joins) || count($joins) > 12) {
            self::invalid("Choose a bounded relationship list.");
        }
        foreach ($joins as $name => $type) {
            if (!isset($schema["relationships"][$name]) || !in_array($type, ["left", "inner"], true)) {
                self::invalid("Choose known inner or left relationships.");
            }
        }
        if (!is_bool($definition["distinct"] ?? false)) {
            self::invalid("Use a Boolean distinct choice.");
        }
        $count = 0;
        $joinFilters = $definition["join_filters"] ?? [];
        if (!is_array($joinFilters) || count($joinFilters) > 12) {
            self::invalid("Choose valid related-row conditions.");
        }
        foreach ($joinFilters as $relation => $filter) {
            if (!isset($schema["relationships"][$relation])) {
                self::invalid("Choose a known relationship.");
            }
            $local = array_filter($schema["fields"], static fn(array $field): bool => $field["relation"] === $relation);
            $this->group($filter, $local, 0, $count);
        }
        $this->group($definition["filters"] ?? null, $schema["fields"], 0, $count);
        $measures = [];
        foreach ($columns as $i => $column) {
            if ($column["aggregate"] !== "none") {
                $measures["c" . $i] = ["type" => $this->outputType($i)];
            }
        }
        $this->group($definition["having"] ?? null, $measures, 0, $count);
        $sorts = $definition["sort"] ?? [];
        if (!is_array($sorts) || !array_is_list($sorts) || count($sorts) > 3) {
            self::invalid("Choose up to three sort columns.");
        }
        foreach ($sorts as $sort) {
            if (
                !is_array($sort) ||
                !is_int($sort["column"] ?? null) ||
                !isset($columns[$sort["column"]]) ||
                !in_array($sort["direction"] ?? null, ["asc", "desc"], true)
            ) {
                self::invalid("Choose valid output sorting.");
            }
        }
    }

    /** @param array<string, mixed> $fields */
    private function group(mixed $group, array $fields, int $depth, int &$count): void
    {
        if ($group === null) {
            return;
        }
        if (
            !is_array($group) ||
            $depth > 4 ||
            !in_array($group["condition"] ?? null, ["AND", "OR"], true) ||
            !is_array($group["rules"] ?? null) ||
            !array_is_list($group["rules"]) ||
            count($group["rules"]) > 40
        ) {
            self::invalid("Use nested condition groups up to four levels and 40 conditions.");
        }
        foreach ($group["rules"] as $rule) {
            if (!is_array($rule)) {
                self::invalid("Choose valid filter conditions.");
            }
            if (isset($rule["condition"])) {
                $this->group($rule, $fields, $depth + 1, $count);
                continue;
            }
            if (++$count > 40 || !is_string($rule["id"] ?? null) || !isset($fields[$rule["id"]])) {
                self::invalid("Choose authorized filter fields.");
            }
            $op = $rule["operator"] ?? null;
            if (
                !in_array(
                    $op,
                    [
                        "equal",
                        "not_equal",
                        "less",
                        "less_or_equal",
                        "greater",
                        "greater_or_equal",
                        "contains",
                        "not_contains",
                        "begins_with",
                        "ends_with",
                        "in",
                        "not_in",
                        "between",
                        "not_between",
                        "is_null",
                        "is_not_null",
                    ],
                    true,
                )
            ) {
                self::invalid("Choose a supported comparison.");
            }
            $type = $fields[$rule["id"]]["type"];
            if (in_array($op, ["contains", "not_contains", "begins_with", "ends_with"], true) && $type !== "string") {
                self::invalid("Text matching requires a text field.");
            }
            if (in_array($op, ["is_null", "is_not_null"], true)) {
                continue;
            }
            $values = in_array($op, ["in", "not_in", "between", "not_between"], true)
            ? $rule["value"] ?? null
            : [$rule["value"] ?? null];
            if (
                !is_array($values) ||
                !array_is_list($values) ||
                $values === [] ||
                count($values) > 100 ||
                (in_array($op, ["between", "not_between"], true) && count($values) !== 2)
            ) {
                self::invalid("Choose valid condition values.");
            }
            foreach ($values as $value) {
                if ($type === "boolean") {
                    if (!in_array($value, [true, false, 0, 1, "0", "1"], true)) {
                        self::invalid("Choose yes or no.");
                    }
                } elseif (in_array($type, ["integer", "double"], true)) {
                    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 1e12) {
                        self::invalid("Enter a bounded numeric value.");
                    }
                } elseif (
                    !is_string($value) ||
                    mb_strlen($value) > 191 ||
                    !mb_check_encoding($value, "UTF-8") ||
                    preg_match('/[\x00-\x1F\x7F]/', $value)
                ) {
                    self::invalid("Enter a valid text value.");
                }
                if ($type === "date") {
                    $date = \DateTimeImmutable::createFromFormat("!Y-m-d", $value);
                    if ($date === false || $date->format("Y-m-d") !== $value) {
                        self::invalid("Enter an ISO calendar date.");
                    }
                }
            }
        }
    }

    public function outputType(int $index): string
    {
        $column = $this->definition["columns"][$index];
        return match (true) {
            in_array($column["aggregate"], ["count", "count_distinct"], true) => "integer",
            $column["aggregate"] === "avg", isset($column["calculation"]) => "double",
            default => $this->schema["fields"][$column["field"]]["type"],
        };
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $permissions = $this->schema["permissions"];
        $fields = array_column($this->definition["columns"], "field");
        foreach ($this->definition["columns"] as $column) {
            if (isset($column["calculation"])) {
                $fields[] = $column["calculation"]["field"];
            }
        }
        $collect = function (?array $group) use (&$collect, &$fields): void {
            foreach ($group["rules"] ?? [] as $rule) {
                if (isset($rule["condition"])) {
                    $collect($rule);
                } else {
                    $fields[] = $rule["id"];
                }
            }
        };
        $collect($this->definition["filters"] ?? null);
        foreach ($this->definition["join_filters"] ?? [] as $group) {
            $collect($group);
        }
        $relations = array_merge(
            array_keys($this->definition["joins"] ?? []),
            array_keys($this->definition["join_filters"] ?? []),
        );
        foreach ($fields as $id) {
            $permissions = array_merge($permissions, $this->schema["fields"][$id]["permissions"]);
            $relations[] = $this->schema["fields"][$id]["relation"];
        }
        foreach ($relations as $relation) {
            while ($relation !== $this->schema["root"]) {
                $node = $this->schema["relationships"][$relation];
                $permissions = array_merge($permissions, $node["permissions"]);
                $relation = $node["parent"];
            }
        }
        foreach (array_keys($this->definition["joins"] ?? []) as $id) {
            $permissions = array_merge($permissions, $this->schema["relationships"][$id]["permissions"]);
        }
        return array_values(array_unique($permissions));
    }

    private static function invalid(string $message): never
    {
        throw new ValidationException([["field" => "report", "message" => $message]]);
    }
}
