<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\Repository\AdmissionDocumentRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAdmissionDocumentRepository implements AdmissionDocumentRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function find(EntityId $id): ?array
    {
        return $this->sql->rows('sis_admission_documents', ['id' => $id->value], 1)[0] ?? null;
    }
    public function forApplication(EntityId $application): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT * FROM ' . $q('sis_admission_documents') . ' WHERE '
            . $q('application_id') . ' = ? AND ' . $q('status') . ' <> ? ORDER BY ' . $q('id'),
            [$application->value, 'purged'],
            21
        );
    }
    public function retainedCount(EntityId $person): int
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch('SELECT COUNT(*) AS ' . $q('n') . ' FROM ' . $q('sis_admission_documents')
            . ' WHERE ' . $q('person_id') . ' = ? AND ' . $q('status') . ' <> ?', [$person->value, 'purged'], 1);
        return (int) $rows[0]['n'];
    }
    public function add(array $row): void
    {
        $this->sql->insert('sis_admission_documents', $row);
    }
    public function lock(EntityId $id): void
    {
        $this->sql->lock('sis_admission_documents', $id->value, 'version');
    }
    public function change(EntityId $id, int $version, array $values): void
    {
        $affected = $this->sql->update(
            'sis_admission_documents',
            $values + ['version' => $version + 1],
            ['id' => $id->value, 'version' => $version]
        );
        if ($affected !== 1) {
            throw new ConcurrentModificationException('Reload the document before continuing.');
        }
    }
    public function scanCandidates(string $at, string $stale, int $limit, bool $scan = true): array
    {
        $q = $this->sql->quote(...);
        $table = $q('sis_admission_documents');
        $rows = $this->sql->fetch('SELECT * FROM ' . $table . ' WHERE ' . $q('status')
            . ' = ? ORDER BY ' . $q('id'), ['purging'], $limit);
        if (!$scan || count($rows) >= $limit) {
            return $rows;
        }
        return [...$rows, ...$this->sql->fetch(
            'SELECT * FROM ' . $table . ' WHERE ('
            . $q('status') . ' = ? AND ' . $q('scan_after') . ' <= ?) OR (' . $q('status') . ' = ? AND '
            . $q('scan_after') . ' <= ?) ORDER BY ' . $q('scan_after') . ', ' . $q('id'),
            ['quarantined', $at, 'scanning', $stale],
            $limit - count($rows)
        )];
    }
}
