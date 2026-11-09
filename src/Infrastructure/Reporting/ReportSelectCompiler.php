<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Reporting\ValueObject\ReportQuery;
use Infrastructure\Persistence\SqlRecords;

final readonly class ReportSelectCompiler
{
    public function __construct(private SqlRecords $sql)
    {
    }

    /** @return array{sql: string, parameters: list<scalar|null>} */
    public function compile(ReportQuery $query): array
    {
        $s = $query->schema;
        $d = $query->definition;
        $q = $this->sql->quote(...);
        $used = [];
        $parameters = [];
        $whereParameters = [];
        $joinParameters = [];
        $values = [];
        $selected = [];
        $groups = [];
        $keys = [];
        $expression = function (string $id) use ($s, $q, &$used): string {
            $field = $s["fields"][$id];
            $used[$field["relation"]] = true;
            $name = $q($field["relation"]) . "." . $q($field["column"]);
            if (($field["expression"] ?? "") === "full_name") {
                $given = "COALESCE(" . $q($field["relation"]) . "." . $q("given_name") . ", '')";
                $family = "COALESCE(" . $q($field["relation"]) . "." . $q("family_name") . ", '')";
                $name = match ($this->sql->driver()) {
                    "mysql" => "CONCAT(" . $given . ", ' ', " . $family . ")",
                    "sqlsrv" => $given . " + ' ' + " . $family,
                    default => $given . " || ' ' || " . $family,
                };
            }
            if (($field["expression"] ?? "") === "registration_label") {
                $name =
                "CASE " .
                $name .
                " WHEN 'A' THEN 'Registered' WHEN 'N' THEN 'Registered'"
                . " WHEN 'D' THEN 'Dropped' WHEN 'W' THEN 'Withdrawn' WHEN 'V' THEN 'Voided' ELSE 'Other' END";
            }
            return isset($field["scale"]) ? "(" . $name . " / " . (int) $field["scale"] . ".0)" : $name;
        };
        $aggregate = false;
        foreach ($d["columns"] as $i => $column) {
            $rowExpression = $expression($column["field"]);
            if (isset($column["calculation"])) {
                $other = $expression($column["calculation"]["field"]);
                $op = $column["calculation"]["operator"];
                $rowExpression =
                "(" .
                $rowExpression .
                ($op === "/" ? " * 1.0 / NULLIF(" . $other . ", 0)" : " " . $op . " " . $other) .
                ")";
            }
            $values[] = $rowExpression . " AS " . $q("v" . $i);
            $term = $q("data") . "." . $q("v" . $i);
            $mode = $column["aggregate"];
            if ($mode !== "none") {
                $aggregate = true;
                $term = ($mode === "count_distinct" ? "COUNT(DISTINCT " : strtoupper($mode) . "(") . $term . ")";
            } else {
                $groups[] = $term;
            }
            $selected[] = $term . " AS " . $q("c" . $i);
        }
        $where = $this->condition($d["filters"] ?? null, $expression, $whereParameters, $s["fields"]);
        foreach (array_merge(array_keys($d["joins"] ?? []), array_keys($d["join_filters"] ?? [])) as $name) {
            $used[$name] = true;
        }
        $joins = [];
        $join = function (string $name) use (&$join, &$joins, &$keys, &$joinParameters, $expression, $s, $q, $d): void {
            if ($name === $s["root"] || isset($joins[$name])) {
                return;
            }
            $r = $s["relationships"][$name];
            $join($r["parent"]);
            $joins[$name] =
            (($d["joins"][$name] ?? "left") === "inner" ? " INNER JOIN " : " LEFT JOIN ") .
            $q($r["table"]) .
            " " .
            $q($name) .
            " ON " .
            $q($r["parent"]) .
            "." .
            $q($r["left"]) .
            " = " .
            $q($name) .
            "." .
            $q($r["right"]);
            $condition = $this->condition(
                $d["join_filters"][$name] ?? null,
                $expression,
                $joinParameters,
                $s["fields"],
            );
            if ($condition !== "") {
                $joins[$name] .= " AND (" . $condition . ")";
            }
            if ($r["many"] ?? false) {
                $keys[] = $q($name) . "." . $q("id");
            }
        };
        foreach (array_keys($used) as $name) {
            $join($name);
        }
        array_unshift($keys, $q($s["root"]) . "." . $q("id"));
        if (!$aggregate && !($d["distinct"] ?? false)) {
            foreach ($keys as $i => $key) {
                $values[] = $key . " AS " . $q("rowkey" . $i);
                $selected[] = $q("data") . "." . $q("rowkey" . $i) . " AS " . $q("rowkey" . $i);
            }
        }
        $inner =
        "SELECT " .
        implode(", ", $values) .
        " FROM " .
        $q($s["table"]) .
        " " .
        $q($s["root"]) .
        implode("", $joins) .
        ($where === "" ? "" : " WHERE " . $where);
        $statement =
        "SELECT " .
        ($d["distinct"] ?? false ? "DISTINCT " : "") .
        implode(", ", $selected) .
        " FROM (" .
        $inner .
        ") " .
        $q("data") .
        ($aggregate && $groups !== [] ? " GROUP BY " . implode(", ", $groups) : "");
        // Filter aggregate aliases through an outer SELECT, keeping positional parameters portable across dialects.
        $outputFields = [];
        foreach ($d["columns"] as $i => $column) {
            $outputFields["c" . $i] = ["type" => $query->outputType($i)];
        }
        $having = $this->condition(
            $d["having"] ?? null,
            static fn(string $id): string => $q("result") . "." . $q($id),
            $parameters,
            $outputFields,
        );
        $sorts = [];
        foreach ($d["sort"] ?? [] as $sort) {
            $sorts["c" . $sort["column"]] = strtoupper($sort["direction"]);
        }
        foreach (array_keys($d["columns"]) as $i) {
            $sorts["c" . $i] ??= "ASC";
        }
        if (!$aggregate && !($d["distinct"] ?? false)) {
            foreach (array_keys($keys) as $i) {
                $sorts["rowkey" . $i] = "ASC";
            };
        }
        $order = [];
        foreach ($sorts as $key => $direction) {
            $name = $q("result") . "." . $q($key);
            $order[] = "CASE WHEN " . $name . " IS NULL THEN 0 ELSE 1 END";
            $order[] = $name . " " . $direction;
        }
        $parameters = array_merge($joinParameters, $whereParameters, $parameters);
        return [
            "sql" =>
                "SELECT * FROM (" .
                $statement .
                ") " .
                $q("result") .
                ($having === "" ? "" : " WHERE " . $having) .
                " ORDER BY " .
                implode(", ", $order),
            "parameters" => $parameters,
        ];
    }

    /** @param array<string, mixed>|null $group
     * @param callable(string): string $expression
     * @param list<scalar|null> $parameters
     * @param array<string, mixed> $fields
     */
    private function condition(?array $group, callable $expression, array &$parameters, array $fields): string
    {
        $parts = [];
        foreach ($group["rules"] ?? [] as $rule) {
            if (isset($rule["condition"])) {
                $nested = $this->condition($rule, $expression, $parameters, $fields);
                if ($nested !== "") {
                    $parts[] = "(" . $nested . ")";
                }
                continue;
            }
            $field = $expression($rule["id"]);
            $op = $rule["operator"];
            if (in_array($op, ["is_null", "is_not_null"], true)) {
                $parts[] = $field . ($op === "is_null" ? " IS NULL" : " IS NOT NULL");
                continue;
            }
            $values = in_array($op, ["in", "not_in", "between", "not_between"], true)
            ? $rule["value"]
            : [$rule["value"]];
            if ($fields[$rule["id"]]["type"] === "boolean") {
                $values = array_map(static fn(mixed $v): int => (int) $v, $values);
            }
            if (in_array($op, ["contains", "not_contains", "begins_with", "ends_with"], true)) {
                $literal = str_replace(["!", "%", "_"], ["!!", "!%", "!_"], $values[0]);
                $parameters[] = ($op === "begins_with" ? "" : "%") . $literal . ($op === "ends_with" ? "" : "%");
                $parts[] = $field . ($op === "not_contains" ? " NOT LIKE ?" : " LIKE ?") . " ESCAPE '!'";
                continue;
            }
            foreach ($values as $value) {
                if (!is_scalar($value) && $value !== null) {
                    throw new \InvalidArgumentException('Report comparison values must be scalar.');
                }
                $parameters[] = $value;
            }
            $placeholder = in_array($fields[$rule["id"]]["type"], ["integer", "double"], true)
            ? "CAST(? AS DECIMAL(30, 10))"
            : "?";
            $parts[] =
            $field .
            match ($op) {
                "in", "not_in" => ($op === "in" ? " IN (" : " NOT IN (") .
                implode(", ", array_fill(0, count($values), $placeholder)) .
                ")",
                "between" => " BETWEEN " . $placeholder . " AND " . $placeholder,
                "not_between" => " NOT BETWEEN " . $placeholder . " AND " . $placeholder,
                "equal" => " = " . $placeholder,
                "not_equal" => " <> " . $placeholder,
                "less" => " < " . $placeholder,
                "less_or_equal" => " <= " . $placeholder,
                "greater" => " > " . $placeholder,
                "greater_or_equal" => " >= " . $placeholder,
                default => throw new \InvalidArgumentException("Unsupported report comparison."),
            };
        }
        return implode(" " . ($group["condition"] ?? "AND") . " ", $parts);
    }
}
