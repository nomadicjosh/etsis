<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\Repository\AdmissionDocumentHoldRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAdmissionDocumentHoldRepository implements AdmissionDocumentHoldRepository
{
    private const string TABLE = 'sis_admission_document_holds';

    public function __construct(private SqlRecords $sql)
    {
    }

    public function latest(EntityId $person, ?EntityId $application): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT * FROM ' . $q(self::TABLE) . ' WHERE ' . $q('person_id') . ' = ? AND '
            . $q('application_id') . ($application === null ? ' IS NULL' : ' = ?')
            . ' ORDER BY ' . $q('scope_sequence') . ' DESC',
            $application === null ? [$person->value] : [$person->value, $application->value],
            1,
        )[0] ?? null;
    }

    public function receipt(EntityId $operation): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT * FROM ' . $q(self::TABLE) . ' WHERE ' . $q('id') . ' = ? OR '
            . $q('release_operation_id') . ' = ?',
            [$operation->value, $operation->value],
            1,
        )[0] ?? null;
    }

    public function history(EntityId $person, EntityId $application, ?EntityId $before = null): array
    {
        $q = $this->sql->quote(...);
        $params = [$person->value, $application->value];
        $where = $q('person_id') . ' = ? AND (' . $q('application_id') . ' IS NULL OR '
        . $q('application_id') . ' = ?)';
        if ($before !== null) {
            $where .= ' AND ' . $q('id') . ' < ?';
            $params[] = $before->value;
        }
        return $this->sql->fetch(
            'SELECT * FROM ' . $q(self::TABLE) . ' WHERE ' . $where . ' ORDER BY ' . $q('id') . ' DESC',
            $params,
            21,
        );
    }

    public function add(array $row): void
    {
        $this->sql->insert(self::TABLE, $row);
    }

    public function release(EntityId $id, array $values): void
    {
        if ($this->sql->update(self::TABLE, $values, ['id' => $id->value, 'version' => 1, 'active' => 1]) !== 1) {
            throw new ConcurrentModificationException('Review the current document hold before releasing it.');
        }
    }
}
