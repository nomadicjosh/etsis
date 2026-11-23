<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\Repository\AdmissionRetentionRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAdmissionRetentionRepository implements AdmissionRetentionRepository
{
    private const string POLICIES = 'sis_admission_retention_policies';
    private const string CATEGORIES = 'sis_admission_document_categories';

    public function __construct(private SqlRecords $sql)
    {
    }

    public function current(): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT * FROM ' . $q(self::POLICIES)
            . ' ORDER BY ' . $q('revision') . ' DESC', [], 1)[0] ?? null;
    }

    public function policy(EntityId $id): ?array
    {
        return $this->sql->rows(self::POLICIES, ['id' => $id->value], 1)[0] ?? null;
    }

    public function policies(?int $before = null): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT * FROM ' . $q(self::POLICIES)
            . ($before === null ? '' : ' WHERE ' . $q('revision') . ' < ?')
            . ' ORDER BY ' . $q('revision') . ' DESC', $before === null ? [] : [$before], 21);
    }

    public function addPolicy(array $row): void
    {
        $this->sql->insert(self::POLICIES, $row);
    }

    public function classification(EntityId $id): ?array
    {
        return $this->sql->rows(self::CATEGORIES, ['id' => $id->value], 1)[0] ?? null;
    }

    public function classifications(EntityId $document, ?EntityId $before = null): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT * FROM ' . $q(self::CATEGORIES) . ' WHERE ' . $q('document_id') . ' = ?'
            . ($before === null ? '' : ' AND ' . $q('id') . ' < ?') . ' ORDER BY ' . $q('id') . ' DESC',
            $before === null ? [$document->value] : [$document->value, $before->value],
            21
        );
    }

    public function addClassification(array $row): void
    {
        $this->sql->insert(self::CATEGORIES, $row);
    }
}
