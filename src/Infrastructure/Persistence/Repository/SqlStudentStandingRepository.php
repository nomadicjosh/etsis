<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentStandingRepository;
use Domain\Student\ValueObject\StandingPeriods;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlStudentStandingRepository implements StudentStandingRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function definitions(string $search, ?EntityId $after, bool $activeOnly): array
    {
        $q = $this->sql->quote(...);
        $like = "%" . str_replace(["!", "%", "_"], ["!!", "!%", "!_"], mb_strtolower($search)) . "%";
        $where =
        "(LOWER(" . $q("code") . ") LIKE ? ESCAPE '!' OR LOWER(" . $q("title") . ") LIKE ? ESCAPE '!')";
        $params = [$like, $like];
        if ($activeOnly) {
            $where .= " AND " . $q("active") . " = 1";
        }
        if ($after !== null) {
            $where .= " AND " . $q("id") . " > ?";
            $params[] = $after->value;
        }
        $rows = $this->sql->fetch(
            "SELECT * FROM " . $q("sis_standing_definitions") . " WHERE " . $where . " ORDER BY " . $q("id"),
            $params,
            21,
        );
        $rows = array_map($this->normalize(...), $rows);
        return [
            "items" => array_slice($rows, 0, 20),
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }

    public function definition(EntityId $id): array
    {
        return $this->normalize(
            $this->sql->current("sis_standing_definitions", $id->value) ??
                throw new \Domain\Shared\Exception\RecordNotFoundException("Standing definition not found."),
        );
    }

    public function lockDefinition(EntityId $id): void
    {
        $this->sql->lock("sis_standing_definitions", $id->value, "version");
    }

    public function saveDefinition(
        EntityId $id,
        string $code,
        string $title,
        bool $active,
        ?int $version,
    ): array {
        $other = $this->sql->rows("sis_standing_definitions", ["code" => $code], 1)[0] ?? null;
        if ($other !== null && $other["id"] !== $id->value) {
            throw new ValidationException([
                ["field" => "code", "message" => "That standing code is already in use."],
            ]);
        }
        $facts = [
            "code" => $code,
            "title" => $title,
            "active" => (int) $active,
            "version" => ($version ?? 0) + 1,
        ];
        if ($version === null) {
            $this->sql->insert("sis_standing_definitions", ["id" => $id->value] + $facts);
        } elseif (
            $this->sql->update("sis_standing_definitions", $facts, [
                "id" => $id->value,
                "version" => $version,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("Reload the standing definition before saving.");
        }
        return $this->definition($id);
    }

    public function rows(EntityId $student, EntityId $pursuit): array
    {
        $rows = $this->sql->rows("sis_student_standing", ["pursuit_id" => $pursuit->value], 51);
        foreach ($rows as &$row) {
            if ($row["student_id"] !== $student->value) {
                throw new CorruptHistoryException();
            }
            $row = array_intersect_key($row, array_flip(StandingPeriods::FIELDS));
        }
        unset($row);
        try {
            return new StandingPeriods($rows)->rows;
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function project(EntityId $student, EntityId $pursuit, array $rows): void
    {
        foreach ($rows as $row) {
            $old = $this->sql->rows("sis_student_standing", ["id" => $row["id"]], 1)[0] ?? null;
            if (
                $old !== null &&
                ($old["student_id"] !== $student->value || $old["pursuit_id"] !== $pursuit->value)
            ) {
                throw new CorruptHistoryException();
            }
        }
        $this->sql->execute(
            "DELETE FROM " .
                $this->sql->quote("sis_student_standing") .
                " WHERE " .
                $this->sql->quote("pursuit_id") .
                " = ?",
            [$pursuit->value],
        );
        foreach ($rows as $row) {
            $this->sql->insert(
                "sis_student_standing",
                $row + ["student_id" => $student->value, "pursuit_id" => $pursuit->value],
            );
        }
    }

    private function normalize(array $row): array
    {
        $row["active"] = (bool) $row["active"];
        $row["version"] = (int) $row["version"];
        return $row;
    }
}
