<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Reporting\Repository\RelationalReportRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlRelationalReportRepository implements RelationalReportRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function get(EntityId $id): ?array
    {
        return $this->sql->rows("sis_report_queries", ["id" => $id->value], 1)[0] ?? null;
    }
    public function operation(EntityId $id): ?array
    {
        return $this->sql->rows("sis_report_query_changes", ["id" => $id->value], 1)[0] ?? null;
    }
    public function save(EntityId $id, string $name, string $encrypted, int $expectedVersion): void
    {
        if ($expectedVersion === -1) {
            $this->sql->insert("sis_report_queries", [
                "id" => $id->value,
                "version" => 0,
                "encrypted_content" => $encrypted,
            ]);
        } elseif (
            $this->sql->update(
                "sis_report_queries",
                ["version" => $expectedVersion + 1, "encrypted_content" => $encrypted],
                ["id" => $id->value, "version" => $expectedVersion],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The report definition changed. Reload before saving.");
        }
        $this->sql->update("sis_reports", ["name" => $name], ["id" => $id->value]);
    }
    public function record(
        EntityId $operation,
        EntityId $report,
        EntityId $actor,
        int $version,
        string $encrypted,
        string $on,
    ): void {
        $this->sql->insert("sis_report_query_changes", [
            "id" => $operation->value,
            "report_id" => $report->value,
            "actor_id" => $actor->value,
            "version" => $version,
            "created_at" => $on,
            "encrypted_content" => $encrypted,
        ]);
    }
}
