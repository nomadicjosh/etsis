<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Student\Repository\LoadDeterminationRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlLoadDeterminationRepository implements LoadDeterminationRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function find(EntityId $id): ?array
    {
        return $this->sql->rows("sis_load_determinations", ["id" => $id->value], 1)[0] ?? null;
    }
    public function insert(array $row): void
    {
        $this->sql->insert("sis_load_determinations", $row);
    }
    public function page(
        EntityId $student,
        EntityId $pursuit,
        EntityId $term,
        ?EntityId $after,
    ): array {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_load_determinations") .
                " WHERE " .
                $q("student_id") .
                " = ? AND " .
                $q("pursuit_id") .
                " = ? AND " .
                $q("term_id") .
                " = ?" .
                ($after === null ? "" : " AND " . $q("id") . " < ?") .
                " ORDER BY " .
                $q("id") .
                " DESC",
            [
                $student->value,
                $pursuit->value,
                $term->value,
                ...$after === null ? [] : [$after->value],
            ],
            11,
        );
    }
}
