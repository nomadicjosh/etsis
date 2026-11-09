<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\StudentProgram;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlStudentRecordRepository implements StudentRecordRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function lock(EntityId $student): void
    {
        $this->sql->lock("sis_students", $student->value, "id");
    }

    public function setActive(EntityId $student, bool $active): void
    {
        $this->sql->update("sis_students", ["active" => (int) $active], ["id" => $student->value]);
    }

    public function setPrimary(EntityId $student, EntityId $program): void
    {
        $this->sql->update("sis_students", ["primary_program_id" => $program->value], ["id" => $student->value]);
    }

    public function program(EntityId $program): array
    {
        $row = $this->sql->one("sis_programs", ["id" => $program->value]);

        return ["id" => $row["id"], "code" => $row["code"], "title" => $row["title"]];
    }

    public function term(EntityId $term): array
    {
        $row = $this->sql->one("sis_terms", ["id" => $term->value]);

        return ["id" => $row["id"], "code" => $row["code"]];
    }

    public function primaryCatalog(EntityId $student): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT c." .
                $q("id") .
                ", c." .
                $q("revision") .
                ", y." .
                $q("code") .
                " AS " .
                $q("year_code") .
                " FROM " .
                $q("sis_students") .
                " s JOIN " .
                $q("sis_student_programs") .
                " a ON a." .
                $q("student_id") .
                " = s." .
                $q("id") .
                " AND a." .
                $q("program_id") .
                " = s." .
                $q("primary_program_id") .
                " JOIN " .
                $q("sis_student_catalogs") .
                " b ON b." .
                $q("assignment_id") .
                " = a." .
                $q("id") .
                " JOIN " .
                $q("sis_program_catalogs") .
                " c ON c." .
                $q("id") .
                " = b." .
                $q("catalog_id") .
                " JOIN " .
                $q("sis_academic_years") .
                " y ON y." .
                $q("id") .
                " = c." .
                $q("year_id") .
                " WHERE s." .
                $q("id") .
                " = ? AND a." .
                $q("status") .
                " = ?",
            [$student->value, "active"],
            1,
        )[0] ?? null;
    }

    public function assignment(EntityId $assignment): StudentProgram
    {
        $row = $this->sql->one("sis_student_programs", ["id" => $assignment->value]);

        return new StudentProgram(
            $assignment,
            new EntityId($row["student_id"]),
            new EntityId($row["program_id"]),
            new EntityId($row["entry_term_id"]),
            $row["status"],
        );
    }

    public function addAssignment(StudentProgram $assignment): void
    {
        try {
            $this->sql->insert("sis_student_programs", [
                "id" => $assignment->id->value,
                "student_id" => $assignment->studentId->value,
                "program_id" => $assignment->programId->value,
                "entry_term_id" => $assignment->entryTermId->value,
                "status" => $assignment->status,
            ]);
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ["23000", "23505"], true)) {
                throw new ValidationException([
                    [
                        "field" => "program_id",
                        "message" => "This program and entry term already exist. Reopen the existing assignment.",
                    ],
                ]);
            }
            throw $error;
        }
    }

    public function saveAssignment(StudentProgram $assignment): void
    {
        $this->sql->update("sis_student_programs", ["status" => $assignment->status], ["id" => $assignment->id->value]);
    }

    public function activeProgram(EntityId $student, EntityId $program, ?EntityId $excluding = null): bool
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                $q("id") .
                " FROM " .
                $q("sis_student_programs") .
                " WHERE " .
                $q("student_id") .
                " = ? AND " .
                $q("program_id") .
                " = ? AND " .
                $q("status") .
                " = ?" .
                ($excluding === null ? "" : " AND " . $q("id") . " <> ?"),
            $excluding === null
                ? [$student->value, $program->value, "active"]
                : [$student->value, $program->value, "active", $excluding->value],
            1,
        ) !== [];
    }

    public function assignments(EntityId $student, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT a." .
                $q("id") .
                ", a." .
                $q("program_id") .
                ", a." .
                $q("entry_term_id") .
                ", a." .
                $q("status") .
                ", p." .
                $q("code") .
                " AS " .
                $q("program_code") .
                ", p." .
                $q("title") .
                " AS " .
                $q("program_title") .
                ", t." .
                $q("code") .
                " AS " .
                $q("term_code") .
                ", cy." .
                $q("code") .
                " AS " .
                $q("catalog_year") .
                ", pc." .
                $q("revision") .
                " AS " .
                $q("catalog_revision") .
                ", pc." .
                $q("id") .
                " AS " .
                $q("catalog_id") .
                " FROM " .
                $q("sis_student_programs") .
                " a JOIN " .
                $q("sis_programs") .
                " p ON p." .
                $q("id") .
                " = a." .
                $q("program_id") .
                " JOIN " .
                $q("sis_terms") .
                " t ON t." .
                $q("id") .
                " = a." .
                $q("entry_term_id") .
                " LEFT JOIN " .
                $q("sis_student_catalogs") .
                " cb ON cb." .
                $q("assignment_id") .
                " = a." .
                $q("id") .
                " LEFT JOIN " .
                $q("sis_program_catalogs") .
                " pc ON pc." .
                $q("id") .
                " = cb." .
                $q("catalog_id") .
                " LEFT JOIN " .
                $q("sis_academic_years") .
                " cy ON cy." .
                $q("id") .
                " = pc." .
                $q("year_id") .
                " WHERE a." .
                $q("student_id") .
                " = ?" .
                ($after === null ? "" : " AND a." . $q("id") . " < ?") .
                " ORDER BY a." .
                $q("id") .
                " DESC",
            $after === null ? [$student->value] : [$student->value, $after->value],
            $limit,
        );
    }
}
