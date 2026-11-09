<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use Domain\Student\Repository\HiatusRepository;
use Domain\Student\StudentHiatus;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlHiatusRepository implements HiatusRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function lockStudent(EntityId $student): void
    {
        $this->sql->lock("sis_students", $student->value, "id");
    }

    public function lock(EntityId $id): void
    {
        $this->sql->lock("sis_student_hiatuses", $id->value, "id");
    }

    public function find(EntityId $id): ?StudentHiatus
    {
        $row = $this->sql->rows("sis_student_hiatuses", ["id" => $id->value], 1)[0] ?? null;
        return $row === null ? null : $this->hydrate($row);
    }

    public function add(StudentHiatus $hiatus): void
    {
        $this->insert("sis_student_hiatuses", [
            "id" => $hiatus->id->value,
            "student_id" => $hiatus->studentId->value,
            "code" => $hiatus->code->value,
            "starts_on" => $hiatus->dates->start->format("Y-m-d"),
            "ends_on" => $hiatus->dates->end?->format("Y-m-d"),
            "voided" => (int) $hiatus->voided,
            "version" => $hiatus->version,
        ]);
    }

    public function save(StudentHiatus $hiatus, int $expectedVersion): void
    {
        if (
            $this->sql->update(
                "sis_student_hiatuses",
                [
                    "ends_on" => $hiatus->dates->end?->format("Y-m-d"),
                    "voided" => (int) $hiatus->voided,
                    "version" => $hiatus->version,
                ],
                ["id" => $hiatus->id->value, "version" => $expectedVersion],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("Reload the current hiatus before changing it.");
        }
    }

    public function overlaps(StudentHiatus $hiatus): bool
    {
        $q = $this->sql->quote(...);
        $values = [
            $hiatus->studentId->value,
            $hiatus->code->value,
            $hiatus->id->value,
            $hiatus->dates->start->format("Y-m-d"),
        ];
        $end = $hiatus->dates->end?->format("Y-m-d");
        if ($end !== null) {
            $values[] = $end;
        }
        return $this->sql->fetch(
            "SELECT " .
                $q("id") .
                " FROM " .
                $q("sis_student_hiatuses") .
                " WHERE " .
                $q("student_id") .
                " = ? AND " .
                $q("code") .
                " = ? AND " .
                $q("voided") .
                " = 0" .
                " AND " .
                $q("id") .
                " <> ? AND (" .
                $q("ends_on") .
                " IS NULL OR " .
                $q("ends_on") .
                " >= ?)" .
                ($end === null ? "" : " AND " . $q("starts_on") . " <= ?"),
            $values,
            1,
        ) !== [];
    }

    public function page(EntityId $student, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        return array_map(
            $this->hydrate(...),
            $this->sql->fetch(
                "SELECT * FROM " .
                    $q("sis_student_hiatuses") .
                    " WHERE " .
                    $q("student_id") .
                    " = ?" .
                    ($after === null ? "" : " AND " . $q("id") . " < ?") .
                    " ORDER BY " .
                    $q("id") .
                    " DESC",
                $after === null ? [$student->value] : [$student->value, $after->value],
                $limit,
            ),
        );
    }

    public function action(EntityId $id): ?array
    {
        return $this->sql->rows("sis_student_hiatus_actions", ["id" => $id->value], 1)[0] ?? null;
    }

    public function returned(EntityId $hiatus): ?array
    {
        return $this->sql->rows(
            "sis_student_hiatus_actions",
            [
                "hiatus_id" => $hiatus->value,
                "action" => "returned",
            ],
            1,
        )[0] ?? null;
    }

    public function addAction(
        EntityId $id,
        EntityId $hiatus,
        EntityId $actor,
        string $action,
        #[\SensitiveParameter] string $encrypted,
        string $on,
    ): void {
        $this->insert("sis_student_hiatus_actions", [
            "id" => $id->value,
            "hiatus_id" => $hiatus->value,
            "actor_id" => $actor->value,
            "action" => $action,
            "encrypted_content" => $encrypted,
            "created_at" => $on,
        ]);
    }

    public function history(EntityId $hiatus, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT a.*, u." .
                $q("first_name") .
                " AS " .
                $q("actor_given_name") .
                ", u." .
                $q("last_name") .
                " AS " .
                $q("actor_family_name") .
                " FROM " .
                $q("sis_student_hiatus_actions") .
                " a JOIN " .
                $q("users") .
                " u ON u." .
                $q("user_id") .
                " = a." .
                $q("actor_id") .
                " WHERE a." .
                $q("hiatus_id") .
                " = ?" .
                ($after === null ? "" : " AND a." . $q("id") . " < ?") .
                " ORDER BY a." .
                $q("id") .
                " DESC",
            $after === null ? [$hiatus->value] : [$hiatus->value, $after->value],
            $limit,
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): StudentHiatus
    {
        return new StudentHiatus(
            new EntityId($row["id"]),
            new EntityId($row["student_id"]),
            HiatusCode::parse($row["code"]),
            new DateRange(
                new DateTimeImmutable($row["starts_on"]),
                $row["ends_on"] === null ? null : new DateTimeImmutable($row["ends_on"]),
            ),
            (bool) $row["voided"],
            (int) $row["version"],
        );
    }

    /** @param array<string, scalar|null> $values */
    private function insert(string $table, #[\SensitiveParameter] array $values): void
    {
        try {
            $this->sql->insert($table, $values);
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ["23000", "23505"], true)) {
                throw new ConcurrentModificationException("Check the existing hiatus operation before retrying.");
            }
            throw $error;
        }
    }
}
