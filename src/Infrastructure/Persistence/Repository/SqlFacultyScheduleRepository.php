<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\FacultyScheduleRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlFacultyScheduleRepository implements FacultyScheduleRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function candidates(array $faculty, string $firstDay, string $lastDay, EntityId $excluding): array
    {
        if ($faculty === [] || count($faculty) > 20) {
            throw new \InvalidArgumentException("Choose a bounded faculty schedule.");
        }
        $q = $this->sql->quote(...);
        return array_column(
            $this->sql->fetch(
                "SELECT DISTINCT v." .
                    $q("section_id") .
                    " FROM " .
                    $q("sis_section_versions") .
                    " v JOIN " .
                    $q("sis_section_meeting_plans") .
                    " p ON p." .
                    $q("id") .
                    " = v." .
                    $q("section_id") .
                    " WHERE v." .
                    $q("faculty_user_id") .
                    " IN (" .
                    implode(",", array_fill(0, count($faculty), "?")) .
                    ")" .
                    " AND v." .
                    $q("instruction_starts_on") .
                    " <= ? AND v." .
                    $q("instruction_ends_on") .
                    " >= ?" .
                    " AND v." .
                    $q("section_id") .
                    " <> ? ORDER BY v." .
                    $q("section_id"),
                [...$faculty, $lastDay, $firstDay, $excluding->value],
                51,
            ),
            "section_id",
        );
    }
}
