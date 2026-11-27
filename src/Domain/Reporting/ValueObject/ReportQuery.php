<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Reporting\Enum\ReportDateBucket;
use Domain\Shared\ValueObject\EntityId;

final readonly class ReportQuery
{
    /** @var array<string,mixed> */
    public array $schema;
    /** @var array<string,mixed> */
    private array $baseSchema;
    /** @var array<string,mixed> */
    public array $matchingLogic;
    /** @param array<string, mixed> $definition
     * @param array<string, mixed> $schema
     * @param list<string>|null $populationStudents Internal bound restriction, never part of a saved definition.
     */
    public function __construct(
        public array $definition,
        array $schema,
        public ?array $populationStudents = null,
    ) {
        $this->baseSchema = $schema;
        if ($schema['financial_as_of'] ?? false) {
            new ReportFinancialCutoff($definition['financial_as_of'] ?? null);
            foreach ($schema['fields'] as &$field) {
                if (isset($field['historical_measure']) || ($field['expression'] ?? null) === 'historical_cutoff') {
                    $field['financial_as_of'] = $definition['financial_as_of'];
                }
            }
            unset($field);
        } elseif (array_key_exists('financial_as_of', $definition)) {
            self::invalid('A recorded cutoff is only available for historical student financial sources.');
        }
        $this->schema = $schema = ReportGraph::expand($schema, $definition['aliases'] ?? []);
        if (($definition["dataset"] ?? null) !== $schema["id"]) {
            self::invalid("Choose a report dataset.");
        }
        if (
            $populationStudents !== null &&
            (self::studentIds($populationStudents) !== $populationStudents ||
                ($schema['student_population'] ?? false) !== true ||
                ($definition['columns'][0]['field'] ?? null) !== 'student_id' ||
                ($definition['distinct'] ?? false) !== true ||
                array_any($definition['columns'], static fn(array $c): bool => $c['aggregate'] !== 'none'))
        ) {
            self::invalid('Use a distinct student population for a bounded selection.');
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
            if (array_key_exists('label_mapping', $column)) {
                if (
                    !is_array($column['label_mapping']) || isset($column['calculation']) ||
                    isset($column['date_bucket'])
                ) {
                    self::invalid(\Codefy\Framework\Helpers\trans(
                        'Custom labels cannot be combined with a calculation or calendar grouping.',
                    ));
                }
                new ReportLabelMapping($column['label_mapping']);
                $type = 'string';
            }
            if (
                array_key_exists('date_bucket', $column) &&
                (!is_string($column['date_bucket']) || ReportDateBucket::tryFrom($column['date_bucket']) === null ||
                    !in_array($type, ['date', 'datetime'], true))
            ) {
                self::invalid('Calendar grouping requires a date field and Day, Month or Year.');
            }
            $calc = $column["calculation"] ?? null;
            if ($calc !== null) {
                if (!is_array($calc)) {
                    self::invalid("Choose numeric fields and a valid calculation.");
                }
                new ReportCalculation($calc);
                if (
                    !in_array($type, ['integer', 'double'], true) ||
                    (isset($calc['field']) &&
                        !in_array($schema['fields'][$calc['field']]['type'] ?? null, ['integer', 'double'], true))
                ) {
                    self::invalid("Choose numeric fields and a valid calculation.");
                }
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
        ReportRowSelection::validate($definition['record_selection'] ?? [], $schema);
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
        foreach ($columns as $column) {
            if (($column['conditions'] ?? null) !== null && $column['aggregate'] === 'none') {
                self::invalid('Column conditions require a summary such as Count, Sum, Minimum or Maximum.');
            }
            $before = $count;
            $this->group($column['conditions'] ?? null, $schema['fields'], 0, $count);
            if (($column['conditions'] ?? null) !== null && $before === $count) {
                self::invalid('Add a column condition or clear the column conditions.');
            }
            foreach ($column['label_mapping']['branches'] ?? [] as $branch) {
                $before = $count;
                $this->group($branch['when'], $schema['fields'], 0, $count);
                if ($before === $count) {
                    self::invalid(\Codefy\Framework\Helpers\trans(
                        'Each custom label needs at least one condition.',
                    ));
                }
            }
        }
        $existence = $definition['existence'] ?? [];
        if (!is_array($existence) || count($existence) > 6) {
            self::invalid('Choose up to six related-record conditions.');
        }
        foreach ($existence as $relation => $match) {
            if (
                !is_string($relation) || !isset($schema['relationships'][$relation]) || !is_array($match) ||
                !in_array($match['mode'] ?? null, ['any', 'none'], true)
            ) {
                self::invalid('Choose a known relationship and whether matching records must exist.');
            }
            $this->group($match['filters'] ?? null, $this->relatedFields($relation), 0, $count);
        }
        $this->matchingLogic = ReportMatchLogic::resolve(
            $definition['existence_logic'] ?? null,
            array_keys($existence),
        );
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
        $this->validateMoney($columns);
        $this->validateRootMetrics($columns);
    }

    /** Prevent one-to-many joins from multiplying already-counted quantities at their source grain. */
    private function validateRootMetrics(array $columns): void
    {
        $totals = array_filter($columns, fn(array $c): bool =>
            in_array($c['aggregate'], ['sum', 'avg'], true) &&
            (($this->schema['fields'][$c['field']]['root_metric'] ?? false) ||
                (isset($c['calculation']['field']) &&
                    ($this->schema['fields'][$c['calculation']['field']]['root_metric'] ?? false))));
        if ($totals === []) {
            return;
        }
        $relations = array_merge(
            array_keys($this->definition['joins'] ?? []),
            array_keys($this->definition['join_filters'] ?? []),
            array_keys($this->definition['record_selection'] ?? [])
        );
        $collect = function (?array $group) use (&$collect, &$relations): void {
            foreach ($group['rules'] ?? [] as $rule) {
                if (isset($rule['condition'])) {
                    $collect($rule);
                } else {
                    $relations[] = $this->schema['fields'][$rule['id']]['relation'];
                }
            }
        };
        $collect($this->definition['filters'] ?? null);
        foreach ($this->definition['join_filters'] ?? [] as $filter) {
            $collect($filter);
        }
        foreach ($columns as $column) {
            $relations[] = $this->schema['fields'][$column['field']]['relation'];
            if (isset($column['calculation']['field'])) {
                $relations[] = $this->schema['fields'][$column['calculation']['field']]['relation'];
            }
            foreach (self::predicateGroups($column) as $group) {
                $collect($group);
            }
        }
        foreach (array_unique($relations) as $relation) {
            while ($relation !== $this->schema['root']) {
                $r = $this->schema['relationships'][$relation];
                if (
                    ($r['many'] ?? false) &&
                    ($this->definition['record_selection'][$relation]['ties'] ?? null) !== 'one'
                ) {
                    self::invalid('Choose one related row or use matching-record conditions before totaling ' .
                        'record quantities. Multiple related rows would repeat each source record.');
                }
                $relation = $r['parent'];
            }
        }
    }

    /** Currency is a grouping dimension, never a hidden assumption about the institution's default.
     * @param list<array<string,mixed>> $columns
     */
    private function validateMoney(array $columns): void
    {
        foreach ($columns as $column) {
            if (isset($column['label_mapping'])) {
                continue; // The output is text, not a monetary amount.
            }
            $field = $this->schema['fields'][$column['field']];
            $other = isset($column['calculation']['field'])
            ? $this->schema['fields'][$column['calculation']['field']] : null;
            $currency = $field['currency_field'] ?? $other['currency_field'] ?? null;
            if ($currency !== null && isset($column['calculation']['value'])) {
                self::invalid(\Codefy\Framework\Helpers\trans(
                    'Use another monetary field in the same currency and units instead of a constant.',
                ));
            }
            if ($currency === null || in_array($column['aggregate'], ['count', 'count_distinct'], true)) {
                continue;
            }
            if (isset($column['calculation'])) {
                if (
                    ($field['currency_field'] ?? null) !== ($other['currency_field'] ?? null)
                    || ($field['money_grain'] ?? $this->schema['root']) !==
                        ($other['money_grain'] ?? $this->schema['root'])
                    || !in_array($column['calculation']['operator'], ['+', '-'], true)
                ) {
                    self::invalid('Combine monetary amounts only with amounts in the same currency and units.');
                }
                if (($field['expression'] ?? null) !== ($other['expression'] ?? null)) {
                    self::invalid('Do not combine currency amounts with minor-unit amounts.');
                }
            }
            if (
                !array_any($columns, static fn(array $c): bool =>
                $c['field'] === $currency && $c['aggregate'] === 'none' && !isset($c['label_mapping']))
            ) {
                self::invalid('Keep Currency as an output column when reporting amounts. Summaries group by currency.');
            }
            if ($column['aggregate'] !== 'none') {
                $relations = array_merge(
                    array_keys($this->definition['joins'] ?? []),
                    array_keys($this->definition['join_filters'] ?? []),
                    array_keys($this->definition['record_selection'] ?? [])
                );
                foreach ($columns as $c) {
                    $relations[] = $this->schema['fields'][$c['field']]['relation'];
                    if (isset($c['calculation']['field'])) {
                        $relations[] = $this->schema['fields'][$c['calculation']['field']]['relation'];
                    }
                }
                $collect = function (?array $group) use (&$collect, &$relations): void {
                    foreach ($group['rules'] ?? [] as $rule) {
                        if (isset($rule['condition'])) {
                            $collect($rule);
                        } else {
                            $relations[] = $this->schema['fields'][$rule['id']]['relation'];
                        }
                    }
                };
                $collect($this->definition['filters'] ?? null);
                foreach ($this->definition['join_filters'] ?? [] as $filter) {
                    $collect($filter);
                }
                foreach ($columns as $c) {
                    foreach (self::predicateGroups($c) as $group) {
                        $collect($group);
                    }
                }
                foreach ($relations as $relation) {
                    while ($relation !== $this->schema['root']) {
                        $r = $this->schema['relationships'][$relation];
                        if (
                            ($r['many'] ?? false) &&
                            $relation !== ($field['money_grain'] ?? null) &&
                            ($this->definition['record_selection'][$relation]['ties'] ?? null) !== 'one'
                        ) {
                            self::invalid(
                                'Choose one related row or use matching-record conditions before totaling money. '
                                . 'Joining multiple related rows would repeat an account balance.'
                            );
                        }
                        $relation = $r['parent'];
                    }
                }
            }
        }
    }

    /** Fields within this related record and its descendants, never its parent or a sibling branch.
     * @return array<string,array<string,mixed>>
     */
    public function relatedFields(string $relation): array
    {
        return array_filter($this->schema['fields'], function (array $field) use ($relation): bool {
            $current = $field['relation'];
            while ($current !== $this->schema['root']) {
                if ($current === $relation) {
                    return true;
                }
                $current = $this->schema['relationships'][$current]['parent'];
            }
            return false;
        });
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
                if ($type === 'datetime') {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
                    if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
                        self::invalid('Enter a UTC date and time as YYYY-MM-DD HH:mm:ss.');
                    }
                }
            }
        }
    }

    /** Distinct underlying students, preserving the original row restrictions and relationship choices.
     * @param array<mixed>|null $students An explicit bounded shortlist, or null for a population page.
     */
    public function studentPopulation(?array $students = null): self
    {
        if (
            ($this->schema['student_population'] ?? false) !== true ||
            array_any(
                $this->definition['columns'],
                static fn(array $column): bool => $column['aggregate'] !== 'none'
            ) ||
            ($this->definition['having']['rules'] ?? []) !== []
        ) {
            self::invalid('Use an unsummarized report that identifies individual students.');
        }
        $definition = $this->definition;
        // Output fields can introduce joins too. Keep them even when replacing the visible columns.
        foreach ($definition['columns'] as $column) {
            $fields = [$column['field'], $column['calculation']['field'] ?? null];
            $collect = function (?array $group) use (&$collect, &$fields): void {
                foreach ($group['rules'] ?? [] as $rule) {
                    if (isset($rule['condition'])) {
                        $collect($rule);
                    } else {
                        $fields[] = $rule['id'];
                    }
                }
            };
            foreach (self::predicateGroups($column) as $group) {
                $collect($group);
            }
            foreach ($fields as $field) {
                if ($field === null) {
                    continue;
                }
                $relation = $this->schema['fields'][$field]['relation'];
                if ($relation !== $this->schema['root']) {
                    $definition['joins'][$relation] ??= 'left';
                }
            }
        }
        $definition['columns'] = array_map(
            static fn(string $field): array =>
            ['field' => $field, 'label' => $field, 'aggregate' => 'none'],
            ['student_id', 'institutional_id', 'full_name', 'active']
        );
        $definition['distinct'] = true;
        // A missing student relationship cannot identify a person for an office action.
        if ($this->schema['root'] !== 'student') {
            $definition['joins']['student'] = 'inner';
        }
        $definition['having'] = null;
        $definition['sort'] = [['column' => 0, 'direction' => 'asc']];
        return new self($definition, $this->baseSchema, $students === null ? null : self::studentIds($students));
    }

    /** @param array<mixed> $students
     * @return list<string>
     */
    private static function studentIds(array $students): array
    {
        if (!array_is_list($students) || count($students) < 1 || count($students) > 25) {
            self::invalid('Choose between one and twenty-five distinct students.');
        }
        $ids = [];
        foreach ($students as $id) {
            if (!is_string($id) || isset($ids[$id])) {
                self::invalid('Choose distinct students from this report.');
            }
            $ids[$id] = new EntityId($id);
        }
        ksort($ids, SORT_STRING);
        return array_keys($ids);
    }

    public function outputType(int $index): string
    {
        $column = $this->definition["columns"][$index];
        return match (true) {
            in_array($column["aggregate"], ["count", "count_distinct"], true) => "integer",
            $column["aggregate"] === "avg", isset($column["calculation"]) => "double",
            isset($column['label_mapping']) => 'string',
            isset($column['date_bucket']) => ReportDateBucket::from($column['date_bucket'])->outputType(),
            default => $this->schema["fields"][$column["field"]]["type"],
        };
    }

    /** @return list<array|null> */
    private static function predicateGroups(array $column): array
    {
        return [$column['conditions'] ?? null, ...array_column($column['label_mapping']['branches'] ?? [], 'when')];
    }

    /** @return list<string> */
    public function conditionalColumns(): array
    {
        return array_values(array_map(
            static fn(array $column): string => $column['label'],
            array_filter($this->definition['columns'], static fn(array $column): bool =>
                ($column['conditions'] ?? null) !== null),
        ));
    }

    /** @return list<string> */
    public function labelledColumns(): array
    {
        return array_values(array_map(
            static fn(array $column): string => $column['label'],
            array_filter($this->definition['columns'], static fn(array $column): bool =>
                isset($column['label_mapping'])),
        ));
    }

    /** @return list<array{record:string,date:string,direction:string,ties:string}> */
    public function rowSelectionSummary(): array
    {
        $result = [];
        foreach ($this->definition["record_selection"] ?? [] as $relation => $selection) {
            $result[] = [
                "record" => $this->schema["relationships"][$relation]["label"],
                "date" => $this->schema["fields"][$selection["date_field"]]["label"],
                "direction" => $selection["direction"],
                "ties" => $selection["ties"],
            ];
        }
        return $result;
    }

    /** @return array{0:list<string>,1:list<string>} */
    private function references(): array
    {
        $fields = array_column($this->definition["columns"], "field");
        foreach ($this->definition["columns"] as $column) {
            if (isset($column["calculation"]["field"])) {
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
        foreach ($this->definition['columns'] as $column) {
            foreach (self::predicateGroups($column) as $group) {
                $collect($group);
            }
        }
        foreach ($this->definition["join_filters"] ?? [] as $group) {
            $collect($group);
        }
        foreach ($this->definition['existence'] ?? [] as $match) {
            $collect($match['filters'] ?? null);
        }
        $relations = array_merge(
            array_keys($this->definition["joins"] ?? []),
            array_keys($this->definition["join_filters"] ?? []),
            array_keys($this->definition['existence'] ?? []),
            array_keys($this->definition['aliases'] ?? []),
        );
        foreach ($this->definition['record_selection'] ?? [] as $selection) {
            $fields[] = $selection['date_field'];
        }
        $relations = array_merge($relations, array_keys($this->definition['record_selection'] ?? []));
        return [$fields, $relations];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $permissions = $this->schema["permissions"];
        [$fields, $relations] = $this->references();
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

    /** Internal read-model dependency checks reuse the exact hidden-field/ancestor permission traversal. */
    public function usesProjection(string $projection): bool
    {
        [$fields, $relations] = $this->references();
        foreach ($fields as $id) {
            $relations[] = $this->schema['fields'][$id]['relation'];
        }
        foreach ($relations as $relation) {
            while ($relation !== $this->schema['root']) {
                $node = $this->schema['relationships'][$relation];
                if (($node['projection'] ?? null) === $projection) {
                    return true;
                }
                $relation = $node['parent'];
            }
        }
        return false;
    }

    private static function invalid(string $message): never
    {
        throw new ValidationException([["field" => "report", "message" => $message]]);
    }
}
