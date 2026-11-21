<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Reporting\Enum\ReportDateBucket;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\Persistence\SqlSelectLimit;

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
        $columnParameters = [];
        $whereParameters = [];
        $joinParameters = [];
        $values = [];
        $selected = [];
        $groups = [];
        $keys = [];
        $expression = function (string $id) use ($s, &$used): string {
            $field = $s["fields"][$id];
            $used[$field["relation"]] = true;
            if (isset($field['currency_relation'])) {
                $used[$field['currency_relation']] = true;
            }
            return $this->fieldExpression($field, $field['relation']);
        };
        $aggregate = false;
        foreach ($d["columns"] as $i => $column) {
            $rowExpression = $expression($column["field"]);
            if (isset($column['date_bucket'])) {
                $rowExpression = ReportCalendarSql::bucket(
                    $rowExpression,
                    $s['fields'][$column['field']]['type'],
                    ReportDateBucket::from($column['date_bucket']),
                    $this->sql->driver(),
                );
            }
            if (isset($column["calculation"])) {
                $other = $expression($column["calculation"]["field"]);
                $op = $column["calculation"]["operator"];
                $rowExpression =
                "(" .
                $rowExpression .
                ($op === "/" ? " * 1.0 / NULLIF(" . $other . ", 0)" : " " . $op . " " . $other) .
                ")";
            }
            $condition = $this->condition(
                $column['conditions'] ?? null,
                $expression,
                $columnParameters,
                $s['fields'],
            );
            if ($condition !== '') {
                $rowExpression = 'CASE WHEN ' . $condition . ' THEN ' . $rowExpression . ' ELSE NULL END';
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
            $metadata = $s['fields'][$column['field']];
            if (($metadata['expression'] ?? null) === 'account_money') {
                foreach ($d['columns'] as $currencyIndex => $currencyColumn) {
                    if (
                        $currencyColumn['field'] === $metadata['currency_field']
                        && $currencyColumn['aggregate'] === 'none'
                    ) {
                        $currencyValue = $q('data') . '.' . $q('v' . $currencyIndex);
                        $term = 'ROUND(' . $term . ', ' . $this->currencyPrecision($currencyValue) . ')';
                        break;
                    }
                }
            }
            $selected[] = $term . " AS " . $q("c" . $i);
        }
        $where = $this->condition($d["filters"] ?? null, $expression, $whereParameters, $s["fields"]);
        $matching = $this->matchingGroup($query->matchingLogic, $d['existence'] ?? [], $s, $whereParameters);
        if ($matching !== '') {
            $where = $where === '' ? $matching : '(' . $where . ') AND ' . $matching;
        }
        if (isset($s['source_scope'])) {
            $scope = match ($s['source_scope']) {
                'active_attendance' => new AttendanceReportSql($this->sql)->activeRegistration($s['root']),
                default => throw new \LogicException('Unknown reporting source scope.'),
            };
            $where = $where === '' ? $scope : '(' . $where . ') AND ' . $scope;
        }
        $related = array_merge(
            array_keys($d["joins"] ?? []),
            array_keys($d["join_filters"] ?? []),
            array_keys($d['record_selection'] ?? []),
        );
        foreach ($related as $name) {
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
            $this->relationshipCondition($name, $r);
            $selection = $d['record_selection'][$name] ?? null;
            if ($selection !== null) {
                $joins[$name] .= ' AND ' . $this->selectedRows(
                    $name,
                    $selection,
                    $d['join_filters'][$name] ?? null,
                    $s,
                    $joinParameters,
                );
            }
            $condition = $this->condition(
                $selection === null ? ($d["join_filters"][$name] ?? null) : null,
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
        if ($query->populationStudents !== null) {
            // Keep the report's complete typed filter allowance; selection is a separate bound restriction.
            $restriction = $q('result') . '.' . $q('c0') . ' IN ('
            . implode(', ', array_fill(0, count($query->populationStudents), '?')) . ')';
            $having = $having === '' ? $restriction : '(' . $having . ') AND ' . $restriction;
            array_push($parameters, ...$query->populationStudents);
        }
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
        $parameters = array_merge($columnParameters, $joinParameters, $whereParameters, $parameters);
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

    /** Shared field semantics for outputs, ordinary filters and related-record conditions.
     * @param array<string,mixed> $field
     */
    private function fieldExpression(array $field, string $alias): string
    {
        $q = $this->sql->quote(...);
        $name = $q($alias) . '.' . $q($field['column']);
        if (($field['expression'] ?? '') === 'full_name') {
            $given = 'COALESCE(' . $q($alias) . '.' . $q('given_name') . ", '')";
            $family = 'COALESCE(' . $q($alias) . '.' . $q('family_name') . ", '')";
            $name = match ($this->sql->driver()) {
                'mysql' => 'CONCAT(' . $given . ", ' ', " . $family . ')',
                'sqlsrv' => $given . " + ' ' + " . $family,
                default => $given . " || ' ' || " . $family,
            };
        }
        if (($field['expression'] ?? '') === 'section_enrollment_count') {
            return new \Infrastructure\Persistence\SectionEnrollmentSql($this->sql)->count($alias);
        }
        if (($field['expression'] ?? '') === 'section_below_target') {
            return new \Infrastructure\Persistence\SectionEnrollmentSql($this->sql)->below($alias);
        }
        if (($field['expression'] ?? '') === 'account_money') {
            $currency = $q($field['currency_relation']) . '.' . $q('currency');
            // Unknown currency precision is not silently treated as cents.
            $name = '(' . $name . ' * 1.0 / (' . $this->currencyPrecision($currency, divisor: true) . '))';
        }
        if (($field['expression'] ?? '') === 'registration_label') {
            $name = "CASE " . $name . " WHEN 'A' THEN 'Registered' WHEN 'N' THEN 'Registered'"
            . " WHEN 'D' THEN 'Dropped' WHEN 'W' THEN 'Withdrawn' WHEN 'V' THEN 'Voided' ELSE 'Other' END";
        }
        return isset($field['scale']) ? '(' . $name . ' / ' . (int) $field['scale'] . '.0)' : $name;
    }

    private function currencyPrecision(string $currency, bool $divisor = false): string
    {
        $groups = [];
        $iso = new \Money\Currencies\ISOCurrencies();
        foreach ($iso as $unit) {
            $groups[$iso->subunitFor($unit)][] = "'" . $unit->getCode() . "'";
        }
        $expression = 'CASE';
        foreach ($groups as $digits => $codes) {
            $expression .= ' WHEN ' . $currency . ' IN (' . implode(', ', $codes) . ') THEN '
            . ($divisor ? 10 ** $digits : $digits);
        }
        return $expression . ' ELSE NULL END';
    }

    /** The complete related row is selected before ordinary output filters; matching cards remain independent.
     * @param array<string,mixed> $selection
     * @param array<string,mixed>|null $filters
     * @param array<string,mixed> $schema
     * @param list<scalar|null> $parameters */
    private function selectedRows(
        string $name,
        array $selection,
        ?array $filters,
        array $schema,
        array &$parameters,
    ): string {
        $q = $this->sql->quote(...);
        $r = $schema["relationships"][$name];
        $date = $schema["fields"][$selection["date_field"]];
        $path = \Domain\Reporting\ValueObject\ReportRowSelection::dates($schema, $name)[
        $selection["date_field"]
        ];
        $candidate = function (
            string $prefix,
            string $parent,
            string $parentColumn
        ) use (
            $q,
            $r,
            $date,
            $path,
            $schema,
            $name,
            $filters,
            &$parameters,
        ): array {
            $row = $prefix . "_row";
            $aliases = [$name => $row];
            $from = $q($r["table"]) . " " . $q($row);
            foreach ($path as $i => $step) {
                $next = $schema["relationships"][$step];
                $alias = $prefix . "_date_" . $i;
                $from .=
                " LEFT JOIN " .
                $q($next["table"]) .
                " " .
                $q($alias) .
                " ON " .
                $q($aliases[$next["parent"]]) .
                "." .
                $q($next["left"]) .
                " = " .
                $q($alias) .
                "." .
                $q($next["right"]);
                $aliases[$step] = $alias;
            }
            $expression = function (string $id) use ($schema, $name, $row): string {
                $field = $schema["fields"][$id];
                if ($field["relation"] !== $name) {
                    throw new \LogicException("A selected-row filter must belong to its own related record.");
                }
                if (($field["currency_relation"] ?? null) === $name) {
                    $field["currency_relation"] = $row;
                }
                return $this->fieldExpression($field, $row);
            };
            $condition = $this->condition($filters, $expression, $parameters, $schema["fields"]);
            $dateColumn = $q($aliases[$date["relation"]]) . "." . $q($date["column"]);
            $where =
            $q($row) .
            "." .
            $q($r["right"]) .
            " = " .
            $q($parent) .
            "." .
            $q($parentColumn) .
            " AND " .
            $dateColumn .
            " IS NOT NULL" .
            ($condition === "" ? "" : " AND (" . $condition . ")");
            return [
                "from" => $from,
                "where" => $where,
                "date" => $dateColumn,
                "id" => $q($row) . "." . $q("id"),
            ];
        };
        $pick = $candidate("pick", $r["parent"], $r["left"]);
        // Each inner comparison is correlated to its immediate parent, including on Oracle.
        $rank = $candidate("rank", "pick_row", $r["right"]);
        $operator = $selection["direction"] === "latest" ? ">" : "<";
        $better = $rank["date"] . " " . $operator . " " . $pick["date"];
        if ($selection["ties"] === "one") {
            $better .=
            " OR (" .
            $rank["date"] .
            " = " .
            $pick["date"] .
            " AND " .
            $rank["id"] .
            " " .
            $operator .
            " " .
            $pick["id"] .
            ")";
        }
        return $q($name) .
        "." .
        $q("id") .
        " IN (SELECT " .
        $pick["id"] .
        " FROM " .
        $pick["from"] .
        " WHERE " .
        $pick["where"] .
        " AND NOT EXISTS (SELECT 1 FROM " .
        $rank["from"] .
        " WHERE " .
        $rank["where"] .
        " AND (" .
        $better .
        ")))";
    }

    /** Validated All/Any groups retain the outer filter and correlated result grain.
     * @param array<string,mixed> $group
     * @param array<string,mixed> $matches
     * @param array<string,mixed> $schema
     * @param list<scalar|null> $parameters */
    private function matchingGroup(array $group, array $matches, array $schema, array &$parameters): string
    {
        $parts = [];
        foreach ($group["rules"] as $rule) {
            $parts[] = isset($rule["condition"])
            ? $this->matchingGroup($rule, $matches, $schema, $parameters)
            : $this->existence($rule["relation"], $matches[$rule["relation"]], $schema, $parameters);
        }
        return $parts === [] ? "" : "(" . implode(" " . $group["condition"] . " ", $parts) . ")";
    }

    /** A correlated condition filters the outer row without adding related rows to its result grain.
     * @param array<string,mixed> $match
     * @param array<string,mixed> $schema
     * @param list<scalar|null> $parameters
     */
    private function existence(string $relation, array $match, array $schema, array &$parameters): string
    {
        $q = $this->sql->quote(...);
        $root = $schema['root'];
        $path = [];
        for ($current = $relation; $current !== $root; $current = $schema['relationships'][$current]['parent']) {
            array_unshift($path, $current);
        }
        $first = $path[0];
        $alias = static fn(string $name): string => $name === $root ? $root : 'match_' . $name;
        $mapped = static fn(array $r): array => array_replace($r, ['parent' => $alias($r['parent'])]);
        $joins = [];
        $join = function (
            string $name,
            bool $required = false
        ) use (
            &$join,
            &$joins,
            $schema,
            $first,
            $q,
            $alias,
            $mapped
        ): void {
            if ($name === $first || isset($joins[$name])) {
                return;
            }
            $r = $schema['relationships'][$name];
            $join($r['parent'], $required);
            $joins[$name] = ($required ? ' INNER JOIN ' : ' LEFT JOIN ') . $q($r['table']) . ' ' . $q($alias($name))
            . ' ON ' . $this->relationshipCondition($alias($name), $mapped($r));
        };
        foreach (array_slice($path, 1) as $name) {
            $join($name, true);
        }
        $expression = function (string $id) use ($schema, $alias, $join): string {
            $field = $schema['fields'][$id];
            $join($field['relation']);
            return $this->fieldExpression($field, $alias($field['relation']));
        };
        $filters = $this->condition($match['filters'] ?? null, $expression, $parameters, $schema['fields']);
        $r = $schema['relationships'][$first];
        return ($match['mode'] === 'none' ? 'NOT EXISTS' : 'EXISTS') . ' (SELECT 1 FROM '
        . $q($r['table']) . ' ' . $q($alias($first)) . implode('', $joins) . ' WHERE '
        . $this->relationshipCondition($alias($first), $mapped($r))
        . ($filters === '' ? '' : ' AND (' . $filters . ')') . ')';
    }

    /** Repository-owned latest-row relationships never accept SQL from a saved definition or request.
     * @param array<string,mixed> $relation
     */
    private function relationshipCondition(string $name, array $relation): string
    {
        $q = $this->sql->quote(...);
        $column = static fn(string $alias, string $field): string => $q($alias) . '.' . $q($field);
        if (!isset($relation['source'])) {
            return $column($relation['parent'], $relation['left']) . ' = ' . $column($name, $relation['right']);
        }
        if ($relation['source'] !== 'latest_registration_term') {
            throw new \LogicException('Unknown reporting relationship source.');
        }
        $e = static fn(string $field): string => $column('latest_e', $field);
        $t = static fn(string $field): string => $column('latest_t', $field);
        // Search only this student's indexed registrations. Repeated courses return the same term.
        // Retained late drops/withdrawals count, cancellations do not. Codes/click time never rank terms.
        $select = 'SELECT ' . $t('id') . ' FROM ' . $q('sis_enrollments') . ' ' . $q('latest_e')
        . ' INNER JOIN ' . $q('sis_terms') . ' ' . $q('latest_t') . ' ON ' . $t('id') . ' = ' . $e('term_id')
        . ' WHERE ' . $e('student_id') . ' = ' . $column($relation['parent'], 'id')
        . ' AND ' . $e('status') . " IN ('A', 'N', 'D', 'W')"
        . ' ORDER BY ' . $t('starts_on') . ' DESC, ' . $t('ends_on') . ' DESC, ' . $t('id') . ' DESC';
        return $column($name, 'id') . ' = (' . SqlSelectLimit::apply($select, $this->sql->driver(), 1) . ')';
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
            $placeholder = in_array($fields[$rule["id"]]["type"], ["integer", "double"], true) ||
            ($fields[$rule["id"]]['expression'] ?? null) === 'section_below_target'
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
