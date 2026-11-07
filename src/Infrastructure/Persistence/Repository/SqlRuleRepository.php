<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Rules\Repository\RuleRepository;
use Domain\Rules\RuleSet;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlRuleRepository implements RuleRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function save(EntityId $id, EntityId $owner, string $name, RuleSet $rules): void
    {
        $this->sql->insert('sis_rule_definitions', ['id' => $id->value, 'owner_user_id' => $owner->value,
            'name' => $name, 'rules_json' => json_encode($rules->tree, JSON_THROW_ON_ERROR), 'version' => 0]);
    }

    public function get(EntityId $id): array
    {
        $row = $this->sql->one('sis_rule_definitions', ['id' => $id->value]);
        $row['rules'] = json_decode($row['rules_json'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['rules_json']);

        return $row;
    }

    public function students(RuleSet $rules): array
    {
        $q = $this->sql->quote(...);
        $parameters = [];
        $where = $this->compile($rules->tree, $parameters);

        return $this->sql->fetch('SELECT s.' . $q('id') . ', i.' . $q('institutional_id')
            . ', s.' . $q('active') . ', p.' . $q('code') . ' AS ' . $q('program') . ', t.' . $q('code')
            . ' AS ' . $q('term') . ' FROM ' . $q('sis_students') . ' s JOIN ' . $q('sis_person_identifiers')
            . ' i ON i.' . $q('person_id') . ' = s.' . $q('person_id') . ' JOIN ' . $q('sis_programs')
            . ' p ON p.' . $q('id') . ' = s.' . $q('primary_program_id') . ' JOIN ' . $q('sis_terms')
            . ' t ON t.' . $q('id') . ' = s.' . $q('entry_term_id') . ' WHERE ' . $where
            . ' ORDER BY s.' . $q('id'), $parameters, 500);
    }

    /**
     * @param array<string, mixed> $group
     * @param list<scalar|null> $parameters
     */
    private function compile(array $group, array &$parameters): string
    {
        $conditions = [];
        foreach ($group['rules'] as $rule) {
            if (isset($rule['condition'])) {
                $conditions[] = $this->compile($rule, $parameters);
                continue;
            }
            $columns = ['program_id' => 'primary_program_id', 'term_id' => 'entry_term_id', 'active' => 'active'];
            $column = $columns[$rule['id']];
            $field = 's.' . $this->sql->quote($column);
            $operator = ['equal' => '=', 'not_equal' => '<>', 'in' => 'IN', 'not_in' => 'NOT IN',
                'less' => '<', 'greater' => '>'][$rule['operator']];
            $values = is_array($rule['value']) ? $rule['value'] : [$rule['value']];
            foreach ($values as $value) {
                if (!is_scalar($value) && $value !== null) {
                    throw new \InvalidArgumentException('Invalid rule value.');
                }
                $parameters[] = is_bool($value) ? (int) $value : $value;
            }
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $conditions[] = $field . ' ' . $operator . ' '
            . (is_array($rule['value']) ? '(' . $placeholders . ')' : '?');
        }

        return '(' . implode(' ' . $group['condition'] . ' ', $conditions) . ')';
    }
}
