<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Graduation\GraduationCase;
use Domain\Graduation\Repository\GraduationRepository;
use Domain\Graduation\ValueObject\GraduationSchema;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlGraduationRepository implements GraduationRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function find(EntityId $pursuit): ?array
    {
        return $this->sql->rows(
            "sis_graduation_cases",
            ["id" => $pursuit->value],
            1,
        )[0] ?? null;
    }

    public function save(GraduationCase $root, int $expected): void
    {
        $s = $root->state();
        $row = [
            "id" => (string) $root->aggregateId(),
            "student_id" => $s["student_id"],
            "version" => $root->playhead(),
            "source_hash" => GraduationSchema::hash($s),
            "eligible" => (int) ($s["eligibility"]["eligible"] ?? false),
        ];
        if ($expected === 0) {
            $this->sql->insert("sis_graduation_cases", $row);
        } elseif (
            $this->sql->update("sis_graduation_cases", $row, [
                "id" => $row["id"],
                "version" => $expected,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The graduation decision changed. Review again.",
            );
        }
    }

    public function queue(?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_graduation_cases") .
                " WHERE " .
                $q("eligible") .
                " = ?" .
                ($after === null ? "" : " AND " . $q("id") . " > ?") .
                " ORDER BY " .
                $q("id"),
            [1, ...$after === null ? [] : [$after->value]],
            11,
        );
    }
}
