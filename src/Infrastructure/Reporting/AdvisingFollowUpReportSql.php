<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Infrastructure\Persistence\SqlRecords;

/** A report cannot re-open conversations pinned to a different canonical account. */
final readonly class AdvisingFollowUpReportSql
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function currentOwner(string $root): string
    {
        $q = $this->sql->quote(...);
        $c = static fn(string $alias, string $column): string => $q($alias) . "." . $q($column);
        return "EXISTS (SELECT 1 FROM " .
        $q("sis_students") .
        " " .
        $q("followup_student") .
        " INNER JOIN " .
        $q("sis_persons") .
        " " .
        $q("followup_person") .
        " ON " .
        $c("followup_person", "id") .
        " = " .
        $c("followup_student", "person_id") .
        " INNER JOIN " .
        $q("sis_student_programs") .
        " " .
        $q("followup_pursuit") .
        " ON " .
        $c("followup_pursuit", "student_id") .
        " = " .
        $c("followup_student", "id") .
        " WHERE " .
        $c("followup_student", "id") .
        " = " .
        $c($root, "student_id") .
        " AND " .
        $c("followup_pursuit", "id") .
        " = " .
        $c($root, "pursuit_id") .
        " AND " .
        $c("followup_person", "owner_user_id") .
        " = " .
        $c($root, "owner_user_id") .
        ")";
    }

    public function requireReady(): void
    {
        $q = $this->sql->quote(...);
        if (
            $this->sql->fetch(
                "SELECT " .
                    $q("id") .
                    " FROM " .
                    $q("sis_advising_checkins") .
                    " WHERE " .
                    $q("held_on") .
                    " IS NULL OR " .
                    $q("recorded_on") .
                    " IS NULL",
                [],
                1,
            ) !== []
        ) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "report",
                    "message" =>
                        "Advising dates need verified conversion before reporting. " .
                        "No partial result was returned. Ask an administrator to finish the advising report migration.",
                ],
            ]);
        }
    }
}
