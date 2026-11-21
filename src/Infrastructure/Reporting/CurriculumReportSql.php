<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Shared\Exception\ValidationException;
use Infrastructure\Persistence\SqlRecords;

/** Check index completion and stream positions; ordinary reports never decrypt/replay academic history. */
final readonly class CurriculumReportSql
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function cohortMatches(string $alias): string
    {
        $q = $this->sql->quote(...);
        $c = static fn(string $a, string $f): string => $q($a) . "." . $q($f);
        return "CASE WHEN EXISTS (SELECT 1 FROM " .
        $q("sis_student_catalogs") .
        " curriculum_binding WHERE " .
        $c("curriculum_binding", "assignment_id") .
        " = " .
        $c($alias, "assignment_id") .
        " AND " .
        $c("curriculum_binding", "catalog_id") .
        " = " .
        $c($alias, "program_version_id") .
        " AND " .
        $c("curriculum_binding", "version") .
        " = " .
        $c($alias, "cohort_binding_revision") .
        ") THEN 1 ELSE 0 END";
    }
    public function requireReady(): void
    {
        $q = $this->sql->quote(...);
        $c = static fn(string $a, string $f): string => $q($a) . "." . $q($f);
        $bad = $this->sql->fetch(
            "SELECT " .
                $c("s", "id") .
                " FROM " .
                $q("sis_student_specializations") .
                " s LEFT JOIN " .
                $q("sis_history_streams") .
                " h ON " .
                $c("h", "aggregate_id") .
                " = " .
                $c("s", "id") .
                " AND " .
                $c("h", "stream_type") .
                " = ? WHERE " .
                $c("s", "component_count") .
                " IS NULL OR " .
                $c("h", "id") .
                " IS NULL OR " .
                $c("h", "version") .
                " <> " .
                $c("s", "stream_version") .
                " OR " .
                $c("s", "component_count") .
                " <> (SELECT COUNT(*) FROM " .
                $q("sis_student_curriculum_components") .
                " c WHERE " .
                $c("c", "assignment_id") .
                " = " .
                $c("s", "id") .
                ")" .
                " OR EXISTS (SELECT 1 FROM " .
                $q("sis_student_curriculum_components") .
                " c WHERE " .
                $c("c", "assignment_id") .
                " = " .
                $c("s", "id") .
                " AND (" .
                $c("c", "selection_position") .
                " <> " .
                $c("s", "stream_version") .
                " OR " .
                $c("c", "selection_decision_id") .
                " <> " .
                $c("s", "decision_id") .
                " OR " .
                $c("c", "student_id") .
                " <> " .
                $c("s", "student_id") .
                " OR " .
                $c("c", "program_version_id") .
                " <> " .
                $c("s", "program_version_id") .
                "))",
            ["student_specializations"],
            1,
        );
        if ($bad === []) {
            $bad = $this->sql->fetch(
                "SELECT " . $c("c", "id") . " FROM " . $q("sis_student_curriculum_components") .
                    " c LEFT JOIN " . $q("sis_student_specializations") . " s ON " .
                    $c("s", "id") . " = " . $c("c", "assignment_id") .
                    " WHERE " . $c("s", "id") . " IS NULL",
                [],
                1,
            );
        }
        if ($bad !== []) {
            throw new ValidationException([
                [
                    "field" => "report",
                    "message" =>
                        "Selected curriculum reporting needs projection verification. No " .
                        "partial report was returned. Ask an administrator to complete the " .
                        "curriculum projection migration or investigate its checkpoint.",
                ],
            ]);
        }
    }
}
