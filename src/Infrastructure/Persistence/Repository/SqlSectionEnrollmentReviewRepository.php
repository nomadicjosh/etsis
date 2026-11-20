<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\SectionEnrollmentReviewRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SectionCourseSql;
use Infrastructure\Persistence\SectionEnrollmentSql;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlSectionEnrollmentReviewRepository implements SectionEnrollmentReviewRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function page(
        string $state,
        string $search,
        bool $includeInactive,
        ?EntityId $term = null,
        ?EntityId $after = null,
    ): array {
        $q = $this->sql->quote(...);
        $counts = new SectionEnrollmentSql($this->sql);
        $courses = new SectionCourseSql($this->sql);
        $count = $counts->count("s");
        $minimum = "s." . $q("minimum_enrollment");
        $where = [];
        $parameters = [];
        if (!$includeInactive) {
            $where[] = "s." . $q("active") . " = 1";
        }
        if ($term !== null) {
            $where[] = "s." . $q("term_id") . " = ?";
            $parameters[] = $term->value;
        }
        if ($state === "below" || $state === "met") {
            $where[] = $minimum . " > 0 AND " . $count . ($state === "below" ? " < " : " >= ") . $minimum;
        } elseif ($state === "unset") {
            $where[] = "(" . $minimum . " IS NULL OR " . $minimum . " = 0)";
        }
        $code = $courses->value("code", "c." . $q("code"));
        $title = $courses->value("title", "c." . $q("title"));
        if ($search !== "") {
            $like = "%" . str_replace(["!", "%", "_"], ["!!", "!%", "!_"], mb_strtolower($search)) . "%";
            $where[] =
            "(LOWER(s." .
            $q("code") .
            ") LIKE ? ESCAPE '!' OR LOWER(" .
            $code .
            ") LIKE ? ESCAPE '!' OR LOWER(" .
            $title .
            ") LIKE ? ESCAPE '!')";
            array_push($parameters, $like, $like, $like);
        }
        if ($after !== null) {
            $where[] = "s." . $q("id") . " < ?";
            $parameters[] = $after->value;
        }
        $rows = $this->sql->fetch(
            "SELECT s." .
                $q("id") .
                ", s." .
                $q("code") .
                ", s." .
                $q("term_id") .
                ", s." .
                $q("active") .
                ", s." .
                $q("capacity") .
                ", " .
                $minimum .
                ", " .
                $count .
                " AS " .
                $q("enrolled") .
                ", " .
                $code .
                " AS " .
                $q("course_code") .
                ", " .
                $title .
                " AS " .
                $q("course_title") .
                ", t." .
                $q("code") .
                " AS " .
                $q("term_code") .
                ", v." .
                $q("minimum_enrollment") .
                " AS " .
                $q("published_minimum") .
                " FROM " .
                $q("sis_sections") .
                " s JOIN " .
                $q("sis_courses") .
                " c ON c." .
                $q("id") .
                " = s." .
                $q("course_id") .
                $courses->joins("s") .
                " JOIN " .
                $q("sis_terms") .
                " t ON t." .
                $q("id") .
                " = s." .
                $q("term_id") .
                " LEFT JOIN " .
                $q("sis_section_offering_versions") .
                " selected_target ON selected_target." .
                $q("id") .
                " = s." .
                $q("id") .
                " LEFT JOIN " .
                $q("sis_section_versions") .
                " v ON v." .
                $q("id") .
                " = selected_target." .
                $q("publication_id") .
                ($where === [] ? "" : " WHERE " . implode(" AND ", $where)) .
                " ORDER BY s." .
                $q("id") .
                " DESC",
            $parameters,
            51,
        );
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as &$row) {
            foreach (["capacity", "enrolled"] as $field) {
                $row[$field] = (int) $row[$field];
            }
            $row["minimum_enrollment"] = (int) ($row["minimum_enrollment"] ?? 0);
            $row["published_minimum"] =
            $row["published_minimum"] === null ? null : (int) $row["published_minimum"];
            $row["active"] = (bool) $row["active"];
            $row["below_target"] =
            $row["minimum_enrollment"] > 0 && $row["enrolled"] < $row["minimum_enrollment"];
            $row["target_changed"] =
            $row["published_minimum"] !== null &&
            $row["published_minimum"] !== $row["minimum_enrollment"];
        }
        unset($row);
        return ["items" => $rows, "next_after" => $more ? $rows[49]["id"] : null];
    }
}
