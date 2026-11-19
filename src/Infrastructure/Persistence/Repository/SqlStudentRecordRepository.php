<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\EventSourcing\CorruptHistoryException;
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

    public function primaryCatalog(EntityId $student, ?EntityId $assignment = null): ?array
    {
        $q = $this->sql->quote(...);
        $columns = [
            "c." . $q("id"),
            "c." . $q("revision"),
            "c." . $q("program_id") . " AS " . $q("catalog_program_id"),
            "c." . $q("published") . " AS " . $q("catalog_published"),
            "c." . $q("year_id"),
            "b." . $q("version") . " AS " . $q("binding_version"),
            "y." . $q("code") . " AS " . $q("year_code"),
        ];
        $rows = $this->sql->fetch(
            "SELECT " . implode(", ", $columns) .
                " FROM " . $q("sis_students") . " s" .
                " JOIN " . $q("sis_student_programs") . " a" .
                " ON a." . $q("student_id") . " = s." . $q("id") .
                " AND a." . $q("program_id") . " = s." . $q("primary_program_id") .
                " JOIN " . $q("sis_student_catalogs") . " b" .
                " ON b." . $q("assignment_id") . " = a." . $q("id") .
                " JOIN " . $q("sis_program_catalogs") . " c" .
                " ON c." . $q("id") . " = b." . $q("catalog_id") .
                " JOIN " . $q("sis_academic_years") . " y" .
                " ON y." . $q("id") . " = c." . $q("year_id") .
                " WHERE s." . $q("id") . " = ? AND a." .
                $q($assignment === null ? "status" : "id") . " = ?",
            [$student->value, $assignment?->value ?? "active"],
            1,
        );
        $row = $rows[0] ?? null;
        if ($row !== null) {
            if (!in_array($row["catalog_published"], [0, 1, "0", "1", false, true], true)) {
                throw new CorruptHistoryException();
            }
            $row["catalog_published"] = (bool) $row["catalog_published"];
            foreach (["revision" => 1, "binding_version" => 0] as $field => $minimum) {
                $value = $row[$field];
                if (
                    (!is_int($value) &&
                        (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1)) ||
                    (int) $value < $minimum
                ) {
                    throw new CorruptHistoryException();
                }
                $row[$field] = (int) $value;
            }
        }
        return $row;
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
                        "message" =>
                            "This program and entry term already exist. Reopen the existing assignment.",
                    ],
                ]);
            }
            throw $error;
        }
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
                ", CASE WHEN pd." .
                $q("id") .
                " IS NOT NULL THEN pc." .
                $q("program_code") .
                " ELSE p." .
                $q("code") .
                " END AS " .
                $q("program_code") .
                ", CASE WHEN pd." .
                $q("id") .
                " IS NOT NULL THEN pc." .
                $q("program_title") .
                " ELSE p." .
                $q("title") .
                " END AS " .
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
                $q("sis_program_catalog_details") .
                " pd ON pd." .
                $q("id") .
                " = pc." .
                $q("id") .
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
