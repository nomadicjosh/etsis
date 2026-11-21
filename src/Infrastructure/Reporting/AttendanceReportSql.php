<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Infrastructure\Persistence\SqlRecords;

/** Non-removable source boundary; report filters and optional joins cannot reopen closed registrations. */
final readonly class AttendanceReportSql
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function activeRegistration(string $attendance): string
    {
        $q = $this->sql->quote(...);
        $c = static fn(string $alias, string $column): string => $q($alias) . "." . $q($column);
        return "EXISTS (SELECT 1 FROM " .
        $q("sis_enrollments") .
        " " .
        $q("attendance_enrollment") .
        " WHERE " .
        $c("attendance_enrollment", "student_id") .
        " = " .
        $c($attendance, "student_id") .
        " AND " .
        $c("attendance_enrollment", "section_id") .
        " = " .
        $c($attendance, "section_id") .
        " AND " .
        $c("attendance_enrollment", "status") .
        " IN ('A', 'N'))";
    }
}
