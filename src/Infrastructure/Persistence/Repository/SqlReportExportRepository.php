<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Reporting\Repository\ReportExportRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlReportExportRepository implements ReportExportRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function get(EntityId $id): ?array
    {
        return $this->sql->rows('sis_report_exports', ['id' => $id->value], 1)[0] ?? null;
    }

    public function latest(EntityId $report, EntityId $actor): ?array
    {
        $q = $this->sql->quote(...);
        $fields = ['id', 'report_id', 'actor_id', 'definition_version', 'capture_id', 'status',
            'created_at', 'expires_at', 'started_at', 'completed_at', 'row_count'];
        return $this->sql->fetch(
            'SELECT ' . implode(', ', array_map($q, $fields)) . ' FROM ' . $q('sis_report_exports') . ' WHERE '
            . $q('report_id') . ' = ? AND ' . $q('actor_id') . ' = ? ORDER BY ' . $q('id') . ' DESC',
            [$report->value, $actor->value],
            1
        )[0] ?? null;
    }

    public function add(array $job): void
    {
        $this->sql->insert('sis_report_exports', $job);
    }

    public function lock(EntityId $id): void
    {
        $this->sql->lock('sis_report_exports', $id->value, 'id');
    }

    public function update(EntityId $id, array $values, array $expected = []): bool
    {
        return $this->sql->update('sis_report_exports', $values, ['id' => $id->value] + $expected) === 1;
    }

    public function pending(string $now, int $limit): array
    {
        $q = $this->sql->quote(...);
        return array_column($this->sql->fetch(
            'SELECT ' . $q('id') . ' FROM ' . $q('sis_report_exports')
            . ' WHERE ' . $q('expires_at') . ' > ? AND (' . $q('status') . ' = ? OR ('
            . $q('status') . ' = ? AND ' . $q('lease_until') . ' <= ?)) ORDER BY ' . $q('id'),
            [$now, 'queued', 'running', $now],
            $limit
        ), 'id');
    }

    public function saveChunks(EntityId $id, array $chunks): void
    {
        $this->clear($id);
        foreach ($chunks as $number => $encrypted) {
            $this->sql->insert('sis_report_export_chunks', ['id' => EntityId::generate()->value,
                'export_id' => $id->value, 'chunk_number' => $number, 'encrypted_content' => $encrypted]);
        }
    }

    public function chunk(EntityId $id, int $number): ?string
    {
        return $this->sql->rows('sis_report_export_chunks', ['export_id' => $id->value,
            'chunk_number' => $number], 1)[0]['encrypted_content'] ?? null;
    }

    public function clear(EntityId $id): void
    {
        $this->sql->execute('DELETE FROM ' . $this->sql->quote('sis_report_export_chunks') . ' WHERE '
            . $this->sql->quote('export_id') . ' = ?', [$id->value]);
    }

    public function expired(string $now, int $limit): array
    {
        $q = $this->sql->quote(...);
        return array_column($this->sql->fetch(
            'SELECT ' . $q('id') . ' FROM ' . $q('sis_report_exports')
            . ' WHERE ' . $q('expires_at') . ' <= ? ORDER BY ' . $q('expires_at') . ', ' . $q('id'),
            [$now],
            $limit
        ), 'id');
    }

    public function remove(EntityId $id): void
    {
        $this->clear($id);
        $this->sql->execute('DELETE FROM ' . $this->sql->quote('sis_report_exports') . ' WHERE '
            . $this->sql->quote('id') . ' = ?', [$id->value]);
    }
}
