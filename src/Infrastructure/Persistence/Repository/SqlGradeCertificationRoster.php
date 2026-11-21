<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Gradebook\Repository\GradeCertificationRoster;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlGradeCertificationRoster implements GradeCertificationRoster
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function lockDefinitions(EntityId $section): void
    {
        $row =
        $this->sql->current("sis_sections", $section->value) ??
        throw new \Domain\Shared\Exception\RecordNotFoundException();
        $this->sql->lock("sis_courses", $row["course_id"], "id");
        $this->sql->lock("sis_terms", $row["term_id"], "id");
    }
    public function termCode(EntityId $term): string
    {
        return $this->sql->one("sis_terms", ["id" => $term->value])["code"];
    }
    public function rows(EntityId $section): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                $q("id") .
                ", " .
                $q("student_id") .
                " FROM " .
                $q("sis_enrollments") .
                " WHERE " .
                $q("section_id") .
                " = ? AND " .
                $q("status") .
                " IN (?, ?, ?) ORDER BY " .
                $q("student_id") .
                ", " .
                $q("id"),
            [$section->value, "A", "N", "W"],
            51,
        );
    }
}
