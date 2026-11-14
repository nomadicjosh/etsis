<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Reporting\Repository\ReportLifecycleRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlReportLifecycleRepository implements ReportLifecycleRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function get(EntityId $report): ?array
    {
        return $this->sql->rows('sis_report_lifecycles', ['id' => $report->value], 1)[0] ?? null;
    }
    public function save(EntityId $report, array $state): void
    {
        if ($this->get($report) === null) {
            $this->sql->insert('sis_report_lifecycles', ['id' => $report->value] + $state);
        } else {
            $this->sql->update('sis_report_lifecycles', $state, ['id' => $report->value]);
        }
    }
    public function savePages(EntityId $report, #[\SensitiveParameter] array $encryptedPages): void
    {
        foreach ($encryptedPages as $page => $encrypted) {
            $this->sql->insert('sis_report_snapshot_pages', ['id' => EntityId::generate()->value,
                'report_id' => $report->value, 'page' => $page, 'encrypted_content' => $encrypted]);
        }
    }
    public function page(EntityId $report, int $page): ?string
    {
        return $this->sql->rows('sis_report_snapshot_pages', ['report_id' => $report->value,
            'page' => $page], 1)[0]['encrypted_content'] ?? null;
    }
    public function expired(string $cutoff, int $limit): array
    {
        $q = $this->sql->quote(...);
        return array_column($this->sql->fetch('SELECT ' . $q('id') . ' FROM ' . $q('sis_report_lifecycles')
            . ' WHERE ' . $q('removed_at') . ' IS NULL AND ' . $q('expires_at') . ' <= ? ORDER BY '
            . $q('expires_at') . ', ' . $q('id'), [$cutoff], $limit), 'id');
    }
    public function remove(EntityId $report, string $on): void
    {
        foreach (['sis_report_snapshot_pages', 'sis_report_shares', 'sis_report_query_changes'] as $table) {
            $this->sql->execute('DELETE FROM ' . $this->sql->quote($table) . ' WHERE '
                . $this->sql->quote('report_id') . ' = ?', [$report->value]);
        }
        $this->sql->execute('DELETE FROM ' . $this->sql->quote('sis_report_queries') . ' WHERE '
            . $this->sql->quote('id') . ' = ?', [$report->value]);
        $this->sql->update('sis_report_lifecycles', ['removed_at' => $on, 'encrypted_manifest' => null,
            'capture_id' => null, 'captured_at' => null], ['id' => $report->value]);
        $this->sql->update('sis_reports', ['name' => 'Removed report', 'program_id' => null,
            'term_id' => null, 'active' => null], ['id' => $report->value]);
    }
}
