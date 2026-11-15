<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Communications\Service\MessageCipher;
use Domain\HumanResources\EmployeePosition;
use Domain\HumanResources\Repository\PositionRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

final readonly class SqlPositionRepository implements PositionRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }
    public function find(EntityId $id): ?EmployeePosition
    {
        $row = $this->sql->rows('sis_employee_positions', ['id' => $id->value], 1)[0] ?? null;
        return $row === null ? null : $this->restore($row);
    }
    public function page(EntityId $employee, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            'SELECT p.*, h.' . $q('version') . ' AS ' . $q('head_version')
            . ' FROM ' . $q('sis_employee_positions') . ' p LEFT JOIN ' . $q('sis_history_streams')
            . ' h ON h.' . $q('aggregate_id') . ' = p.' . $q('id') . ' AND h.' . $q('stream_type') . ' = ?'
            . ' WHERE p.' . $q('employee_id') . ' = ?' . ($after === null ? '' : ' AND p.' . $q('id') . ' < ?')
            . ' ORDER BY p.' . $q('id') . ' DESC',
            $after === null ? ['employment_position', $employee->value]
                : ['employment_position', $employee->value, $after->value],
            21
        );
        return array_map(function (array $row): EmployeePosition {
            if ((string) $row['head_version'] !== (string) $row['version']) {
                throw new CorruptHistoryException();
            }
            return $this->restore($row);
        }, $rows);
    }
    public function lock(EntityId $id): void
    {
        $this->sql->lock('sis_employee_positions', $id->value, 'id');
    }
    public function save(EmployeePosition $position, int $expectedVersion): void
    {
        if ($position->version !== $expectedVersion + 1) {
            throw new ConcurrentModificationException('Reload the position assignment.');
        }
        $sealed = $this->cipher->seal(json_encode(
            ['purpose' => 'hr.position', 'facts' => $position->facts()],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        if ($expectedVersion === 0) {
            $this->sql->insert('sis_employee_positions', ['id' => $position->id->value,
                'employee_id' => $position->employeeId->value, 'encrypted_position' => $sealed,
                'version' => $position->version]);
        } elseif (
            $this->sql->update('sis_employee_positions', ['encrypted_position' => $sealed,
            'version' => $position->version], ['id' => $position->id->value,
                'employee_id' => $position->employeeId->value,
                'version' => $expectedVersion]) !== 1
        ) {
            throw new ConcurrentModificationException('Reload the position assignment.');
        }
    }
    /** @param array<string,mixed> $row */
    private function restore(array $row): EmployeePosition
    {
        try {
            if (!is_string($row['encrypted_position']) || strlen($row['encrypted_position']) > 65536) {
                throw new CorruptHistoryException();
            }
            $data = json_decode($this->cipher->open($row['encrypted_position']), true, 12, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) || count($data) !== 2 || !array_key_exists('facts', $data)
                || ($data['purpose'] ?? null) !== 'hr.position'
            ) {
                throw new CorruptHistoryException();
            }
            $position = EmployeePosition::restore($data['facts']);
            if (
                $position->id->value !== $row['id'] || $position->employeeId->value !== $row['employee_id']
                || (string) $position->version !== (string) $row['version']
            ) {
                throw new CorruptHistoryException();
            }
            return $position;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }
}
