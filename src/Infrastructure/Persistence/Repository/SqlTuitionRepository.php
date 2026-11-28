<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Tuition\Repository\TuitionRepository;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlTuitionRepository implements TuitionRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function sectionLabel(EntityId $enrollment): string
    {
        $row = $this->sql->one("sis_enrollments", ["id" => $enrollment->value]);
        return $this->sql->one("sis_sections", [
            "id" => $row["section_id"],
        ])["code"];
    }
    public function earlierRegistration(
        EntityId $student,
        EntityId $term,
        EntityId $current,
    ): bool {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                $q("id") .
                " FROM " .
                $q("sis_enrollments") .
                " WHERE " .
                $q("student_id") .
                " = ? AND " .
                $q("term_id") .
                " = ? AND " .
                $q("id") .
                " <> ?",
            [$student->value, $term->value, $current->value],
            1,
        ) !== [];
    }
    private function table(string $kind): string
    {
        if (!in_array($kind, ["schedules", "assignments", "bills"], true)) {
            throw new \InvalidArgumentException();
        }
        return "sis_tuition_" . $kind;
    }
    public function find(string $kind, EntityId $id): ?array
    {
        return $this->sql->rows(
            $this->table($kind),
            ["id" => $id->value],
            1,
        )[0] ?? null;
    }
    public function save(
        string $kind,
        EntityId $id,
        array $values,
        int $expected,
    ): void {
        if ($expected === 0) {
            $this->sql->insert(
                $this->table($kind),
                ["id" => $id->value] + $values,
            );
        } elseif (
            $this->sql->update($this->table($kind), $values, [
                "id" => $id->value,
                "version" => $expected,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(
                "This tuition record changed. Review again.",
            );
        }
    }
    public function schedules(?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_tuition_schedules") .
                ($after === null ? "" : " WHERE " . $q("id") . " > ?") .
                " ORDER BY " .
                $q("id"),
            $after === null ? [] : [$after->value],
            21,
        );
    }
    public function bills(
        bool $pending,
        ?EntityId $owner = null,
        ?EntityId $after = null,
    ): array {
        $q = $this->sql->quote(...);
        $joins = "";
        $conditions = [];
        $params = [];
        if ($owner !== null) {
            $joins =
            " JOIN " .
            $q("sis_students") .
            " s ON s." .
            $q("id") .
            " = b." .
            $q("student_id") .
            " JOIN " .
            $q("sis_persons") .
            " p ON p." .
            $q("id") .
            " = s." .
            $q("person_id");
            $conditions[] = "p." . $q("owner_user_id") . " = ?";
            $params[] = $owner->value;
        }
        if ($pending) {
            $conditions[] = "b." . $q("pending_count") . " > 0";
        }
        if ($after !== null) {
            $conditions[] = "b." . $q("id") . " > ?";
            $params[] = $after->value;
        }
        return $this->sql->fetch(
            "SELECT b.* FROM " .
                $q("sis_tuition_bills") .
                " b" .
                $joins .
                ($conditions === []
                    ? ""
                    : " WHERE " . implode(" AND ", $conditions)) .
                " ORDER BY b." .
                $q("id"),
            $params,
            21,
        );
    }
    public function addInvoice(array $values): void
    {
        $this->sql->insert("sis_tuition_invoices", $values);
    }
    public function invoice(EntityId $id): ?array
    {
        return $this->sql->rows(
            "sis_tuition_invoices",
            ["id" => $id->value],
            1,
        )[0] ?? null;
    }
    public function termLabel(EntityId $id): string
    {
        return $this->sql->one("sis_terms", ["id" => $id->value])["code"];
    }
    public function account(EntityId $student, EntityId $term): ?array
    {
        return $this->sql->rows(
            "sis_student_accounts",
            ["student_id" => $student->value, "term_id" => $term->value],
            1,
        )[0] ?? null;
    }
    public function scopeLabel(string $scope, EntityId $id): string
    {
        if ($scope === "student") {
            $student = $this->sql->one("sis_students", ["id" => $id->value]);
            return $this->sql->one("sis_person_identifiers", [
                "person_id" => $student["person_id"],
            ])["institutional_id"];
        }
        [$table, $field] = match ($scope) {
            "program" => ["sis_programs", "code"],
            "term" => ["sis_terms", "code"],
            default => throw new \InvalidArgumentException(),
        };
        return $this->sql->one($table, ["id" => $id->value])[$field];
    }
}
