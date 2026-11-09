<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\HiatusImpactRepository;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlHiatusImpactRepository implements HiatusImpactRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function lockTerm(EntityId $term): void
    {
        $this->sql->lock("sis_terms", $term->value, "id");
    }

    public function enrollments(EntityId $student, string $effectiveDate): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT e." .
                $q("id") .
                ", e." .
                $q("section_id") .
                ", e." .
                $q("term_id") .
                ", e." .
                $q("status") .
                ", e." .
                $q("registered_at") .
                ", e." .
                $q("version") .
                ", s." .
                $q("course_id") .
                ", s." .
                $q("code") .
                " AS " .
                $q("section_code") .
                ", c." .
                $q("code") .
                " AS " .
                $q("course_code") .
                ", c." .
                $q("title") .
                " AS " .
                $q("course_title") .
                ", t." .
                $q("code") .
                " AS " .
                $q("term_code") .
                ", t." .
                $q("starts_on") .
                ", t." .
                $q("ends_on") .
                ", t." .
                $q("add_drop_ends_on") .
                ", a." .
                $q("completed_milli") .
                ", a." .
                $q("grade_points_milli") .
                ", a." .
                $q("grade_id") .
                ", a." .
                $q("version") .
                " AS " .
                $q("credit_version") .
                " FROM " .
                $q("sis_enrollments") .
                " e" .
                " JOIN " .
                $q("sis_sections") .
                " s ON s." .
                $q("id") .
                " = e." .
                $q("section_id") .
                " JOIN " .
                $q("sis_courses") .
                " c ON c." .
                $q("id") .
                " = s." .
                $q("course_id") .
                " JOIN " .
                $q("sis_terms") .
                " t ON t." .
                $q("id") .
                " = e." .
                $q("term_id") .
                " JOIN " .
                $q("sis_academic_credits") .
                " a ON a." .
                $q("source_id") .
                " = e." .
                $q("id") .
                " WHERE e." .
                $q("student_id") .
                " = ? AND e." .
                $q("status") .
                " IN ('A', 'N')" .
                " AND t." .
                $q("ends_on") .
                " >= ? ORDER BY e." .
                $q("id"),
            [$student->value, $effectiveDate],
            101,
        );
        foreach ($rows as &$row) {
            $row["version"] = (int) $row["version"];
            $row["completed_milli"] = (int) $row["completed_milli"];
            $row["grade_points_milli"] = (int) $row["grade_points_milli"];
            $row["credit_version"] = (int) $row["credit_version"];
            foreach (["starts_on", "ends_on", "add_drop_ends_on"] as $field) {
                $row[$field] = new DateTimeImmutable($row[$field])->format("Y-m-d");
            }
        }
        unset($row);
        return $rows;
    }
}
