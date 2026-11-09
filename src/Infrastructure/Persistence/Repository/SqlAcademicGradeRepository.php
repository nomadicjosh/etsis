<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Gradebook\Repository\AcademicGradeRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAcademicGradeRepository implements AcademicGradeRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function hasPursuit(EntityId $student): bool
    {
        return $this->sql->rows("sis_student_programs", ["student_id" => $student->value], 1) !== [];
    }
    public function grade(EntityId $id): array
    {
        return $this->sql->one("sis_grades", ["id" => $id->value]);
    }
    public function legacyGrades(): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_grades") .
                " WHERE " .
                $q("scale_version_id") .
                " IS NULL ORDER BY " .
                $q("code"),
            [],
            201,
        );
    }
    public function pursuits(EntityId $student): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT a." .
                $q("id") .
                ", a." .
                $q("program_id") .
                ", p." .
                $q("code") .
                ", p." .
                $q("title") .
                " FROM " .
                $q("sis_student_programs") .
                " a JOIN " .
                $q("sis_programs") .
                " p ON p." .
                $q("id") .
                " = a." .
                $q("program_id") .
                " WHERE a." .
                $q("student_id") .
                " = ? AND a." .
                $q("status") .
                " = ? ORDER BY a." .
                $q("id"),
            [$student->value, "active"],
            21,
        );
    }
}
