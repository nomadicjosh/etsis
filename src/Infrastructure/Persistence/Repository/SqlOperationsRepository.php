<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Workspace\Repository\OperationsRepository;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;

final readonly class SqlOperationsRepository implements OperationsRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function page(
        string $context,
        EntityId $id,
        ?EntityId $after,
        string $search = "",
        string $gradeStatus = "all",
    ): array {
        $q = $this->sql->quote(...);
        $columns = static fn(string $alias, array $names): string => implode(
            ", ",
            array_map(static fn(string $name): string => $alias . "." . $q($name), $names),
        );
        [$table, $foreign, $fields] = match ($context) {
            "roster" => ["sis_enrollments", "section_id", ["id", "student_id", "status", "basis"]],
            "enrollments" => ["sis_enrollments", "student_id", ["id", "section_id", "status", "term_id", "basis"]],
            "assignments" => [
                "sis_assignments",
                "section_id",
                ["id", "title", "possible_hundredths", "bonus_hundredths", "due_on"],
            ],
            "prerequisites" => ["sis_prerequisites", "course_id", ["id", "required_course_id"]],
            "window" => ["sis_reg_windows", "term_id", ["id", "opens_at", "closes_at", "course_limit"]],
            "audit" => ["sis_audit", "subject_id", ["id", "action", "recorded_at", "actor_id"]],
            "deliveries" => [
                "sis_deliveries",
                "campaign_id",
                ["id", "recipient_user_id", "status", "version", "disposition", "resolution_id"],
            ],
            default => throw new InvalidArgumentException("Unknown operations context."),
        };
        $select = $columns("r", $fields);
        $from = $q($table) . " r";
        if ($context === "prerequisites") {
            $from .=
            " JOIN " . $q("sis_courses") . " c ON c." . $q("id") . " = r." . $q("required_course_id");
            $select .= ", " . $columns("c", ["code", "title"]);
        }
        if ($context === "deliveries") {
            $from .=
            " LEFT JOIN " .
            $q("sis_persons") .
            " p ON p." .
            $q("owner_user_id") .
            " = r." .
            $q("recipient_user_id");
            $select .= ", " . $columns("p", ["given_name", "family_name"]);
        }
        if ($context === "roster") {
            $from .=
            " JOIN " .
            $q("sis_students") .
            " s ON s." .
            $q("id") .
            " = r." .
            $q("student_id") .
            " JOIN " .
            $q("sis_persons") .
            " p ON p." .
            $q("id") .
            " = s." .
            $q("person_id") .
            " JOIN " .
            $q("sis_person_identifiers") .
            " i ON i." .
            $q("person_id") .
            " = p." .
            $q("id") .
            " JOIN " .
            $q("sis_academic_credits") .
            " c ON c." .
            $q("source_id") .
            " = r." .
            $q("id") .
            " LEFT JOIN " .
            $q("sis_grades") .
            " g ON g." .
            $q("id") .
            " = c." .
            $q("grade_id");
            $select .=
            ", " .
            $columns("p", ["given_name", "family_name"]) .
            ", i." .
            $q("institutional_id") .
            ", c." .
            $q("version") .
            " AS " .
            $q("grade_version") .
            ", g." .
            $q("code") .
            " AS " .
            $q("grade_code");
        }
        if ($context === "enrollments") {
            $from .=
            " JOIN " .
            $q("sis_sections") .
            " s ON s." .
            $q("id") .
            " = r." .
            $q("section_id") .
            " JOIN " .
            $q("sis_academic_credits") .
            " c ON c." .
            $q("source_id") .
            " = r." .
            $q("id");
            $select .=
            ", s." .
            $q("code") .
            " AS " .
            $q("section_code") .
            ", c." .
            $q("course_code") .
            ", c." .
            $q("course_title");
        }
        $parameters = [$id->value];
        $where = "r." . $q($foreign) . " = ?";
        if ($context === "roster") {
            if (!in_array($gradeStatus, ["all", "active", "needs_grade", "graded"], true)) {
                throw new InvalidArgumentException("Choose a valid roster filter.");
            }
            if ($gradeStatus !== "all") {
                $where .= " AND r." . $q("status") . " IN ('A','N')";
            }
            if (in_array($gradeStatus, ["needs_grade", "graded"], true)) {
                $where .=
                " AND r." . $q("basis") . " = 'standard' AND c." .
                $q("grade_id") . ($gradeStatus === "graded" ? " IS NOT NULL" : " IS NULL");
            }
            $terms = preg_split("/\s+/u", trim($search), -1, PREG_SPLIT_NO_EMPTY);
            if ($terms === false) {
                throw new InvalidArgumentException("Use a valid roster search.");
            }
            foreach ($terms as $term) {
                $pattern = "%" . str_replace(["!", "%", "_"], ["!!", "!%", "!_"], mb_strtolower($term)) . "%";
                $where .=
                " AND (LOWER(p." .
                $q("given_name") .
                ") LIKE ? ESCAPE '!' OR LOWER(p." .
                $q("family_name") .
                ") LIKE ? ESCAPE '!' OR LOWER(i." .
                $q("institutional_id") .
                ") LIKE ? ESCAPE '!')";
                array_push($parameters, $pattern, $pattern, $pattern);
            }
        }
        if ($after !== null) {
            $where .= " AND r." . $q("id") . " < ?";
            $parameters[] = $after->value;
        }
        $rows = $this->sql->fetch(
            "SELECT " . $select . " FROM " . $from . " WHERE " . $where . " ORDER BY r." . $q("id") . " DESC",
            $parameters,
            51,
        );
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);

        return ["items" => $rows, "next_after" => $more ? $rows[49]["id"] : null];
    }
}
