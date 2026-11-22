<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\DegreeAudit\Repository\DegreeProgressRecordRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlDegreeProgressRecordRepository implements DegreeProgressRecordRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function find(EntityId $id): ?array
    {
        return $this->sql->rows("sis_degree_progress_records", ["id" => $id->value], 1)[0] ?? null;
    }
    public function insert(array $row): void
    {
        $this->sql->insert("sis_degree_progress_records", $row);
    }
    public function page(EntityId $student, EntityId $pursuit, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_degree_progress_records") .
                " WHERE " .
                $q("student_id") .
                " = ? AND " .
                $q("pursuit_id") .
                " = ?" .
                ($after === null ? "" : " AND " . $q("id") . " < ?") .
                " ORDER BY " .
                $q("id") .
                " DESC",
            [$student->value, $pursuit->value, ...$after === null ? [] : [$after->value]],
            6,
        );
    }
}
