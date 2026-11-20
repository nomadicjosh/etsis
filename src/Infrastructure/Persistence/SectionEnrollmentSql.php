<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use InvalidArgumentException;

/** Shared current-projection count; aliases are owned by repositories/approved report metadata. */
final readonly class SectionEnrollmentSql
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function count(string $section): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $section) !== 1) {
            throw new InvalidArgumentException("Invalid section alias.");
        }
        $q = $this->sql->quote(...);
        return "(SELECT COUNT(*) FROM " .
        $q("sis_enrollments") .
        " " .
        $q("target_enrollment") .
        " WHERE " .
        $q("target_enrollment") .
        "." .
        $q("section_id") .
        " = " .
        $q($section) .
        "." .
        $q("id") .
        " AND " .
        $q("target_enrollment") .
        "." .
        $q("status") .
        " IN ('A', 'N'))";
    }

    public function below(string $section): string
    {
        $count = $this->count($section);
        $minimum = $this->sql->quote($section) . "." . $this->sql->quote("minimum_enrollment");
        return "(CASE WHEN " .
        $minimum .
        " > 0 THEN CASE WHEN " .
        $count .
        " < " .
        $minimum .
        " THEN 1 ELSE 0 END ELSE NULL END)";
    }
}
