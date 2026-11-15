<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\HumanResources\Enum\HrLibraryKind;
use Domain\HumanResources\JobDefinition;
use Domain\HumanResources\PayGrade;
use Domain\HumanResources\Repository\HrLibraryRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;
use RuntimeException;

final readonly class SqlHrLibraryRepository implements HrLibraryRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    private function table(HrLibraryKind $kind): string
    {
        return $kind === HrLibraryKind::Jobs ? 'sis_hr_jobs' : 'sis_hr_pay_grades';
    }
    public function find(HrLibraryKind $kind, EntityId $id): ?array
    {
        $row = $this->sql->rows($this->table($kind), ['id' => $id->value], 1)[0] ?? null;
        return $row === null ? null : $this->restore($kind, $row);
    }
    public function page(HrLibraryKind $kind, string $search, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(LOWER(' . $q('code') . ') LIKE ? OR LOWER(' . $q('title') . ') LIKE ?)';
            $params = ['%' . mb_strtolower($search) . '%', '%' . mb_strtolower($search) . '%'];
        }
        if ($after !== null) {
            $where[] = $q('id') . ' > ?';
            $params[] = $after->value;
        }
        $rows = $this->sql->fetch('SELECT * FROM ' . $q($this->table($kind))
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY ' . $q('id'), $params, 51);
        return array_map(fn(array $row): array => $this->restore($kind, $row), $rows);
    }
    public function lock(HrLibraryKind $kind, EntityId $id): void
    {
        $this->sql->lock($this->table($kind), $id->value, 'id');
    }
    public function save(PayGrade|JobDefinition $value, ?int $expectedVersion): void
    {
        $kind = $value instanceof PayGrade ? HrLibraryKind::PayGrades : HrLibraryKind::Jobs;
        $fields = $value->fields();
        $fields['active'] = (int) $value->active;
        $fields['version'] = $expectedVersion === null ? 0 : $expectedVersion + 1;
        try {
            if ($expectedVersion === null) {
                $this->sql->insert($this->table($kind), $fields);
            } else {
                unset($fields['id']);
                if (
                    $this->sql->update(
                        $this->table($kind),
                        $fields,
                        ['id' => $value->id->value, 'version' => $expectedVersion]
                    ) !== 1
                ) {
                    throw new ConcurrentModificationException('Reload HR setup before saving.');
                }
            }
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new ValidationException([['field' => 'code',
                    'message' => 'That definition code is already used.']]);
            }
            throw $error;
        }
    }
    public function conflicts(PayGrade $grade): bool
    {
        $q = $this->sql->quote(...);
        $query = 'SELECT ' . $q('id') . ' FROM ' . $q('sis_hr_jobs')
        . ' WHERE ' . $q('pay_grade_id') . ' = ? AND ' . $q('active') . ' = 1';
        $params = [$grade->id->value];
        if ($grade->active) {
            $query .= ' AND (' . $q('currency') . ' <> ? OR ' . $q('basis') . ' <> ? OR '
            . $q('rate_minor') . ' < ? OR ' . $q('rate_minor') . ' > ?)';
            array_push($params, $grade->currency, $grade->basis, $grade->minimumMinor, $grade->maximumMinor);
        }
        return $this->sql->fetch($query, $params, 1) !== [];
    }
    /** @param array<string,mixed> $row
     * @return array{value:PayGrade|JobDefinition,version:int}
     */
    private function restore(HrLibraryKind $kind, array $row): array
    {
        if (!in_array((string) $row['active'], ['0', '1'], true) || (int) $row['version'] < 0) {
            throw new RuntimeException('Invalid HR definition projection.');
        }
        $id = new EntityId($row['id']);
        $value = $kind === HrLibraryKind::PayGrades
        ? new PayGrade(
            $id,
            $row['code'],
            $row['title'],
            $row['currency'],
            $row['basis'],
            (int) $row['minimum_minor'],
            (int) $row['maximum_minor'],
            (bool) $row['active']
        )
        : new JobDefinition(
            $id,
            $row['code'],
            $row['title'],
            $row['currency'],
            $row['basis'],
            (bool) $row['active'],
            $row['pay_grade_id'] === null ? null : new EntityId($row['pay_grade_id']),
            $row['rate_minor'] === null ? null : (int) $row['rate_minor'],
            $row['weekly_minutes'] === null ? null : (int) $row['weekly_minutes']
        );
        return ['value' => $value, 'version' => (int) $row['version']];
    }
}
