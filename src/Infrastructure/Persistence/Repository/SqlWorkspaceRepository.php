<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Workspace\Repository\WorkspaceRepository;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;

final readonly class SqlWorkspaceRepository implements WorkspaceRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function records(string $dataset, ?EntityId $owner, string $search, ?EntityId $after): array
    {
        [$select, $from, $searchFields] = $this->definition($dataset);
        $q = $this->sql->quote(...);
        $conditions = [];
        $parameters = [];
        if ($dataset === "faculty") {
            $conditions[] = "r." . $q("owner_user_id") . " IS NOT NULL";
        }
        if ($dataset === "sharedreports" && $owner !== null) {
            $conditions[] =
            "(r." .
            $q("owner_user_id") .
            " = ? OR EXISTS (SELECT 1 FROM " .
            $q("sis_report_shares") .
            " rs WHERE rs." .
            $q("report_id") .
            " = r." .
            $q("id") .
            " AND rs." .
            $q("user_id") .
            " = ?))";
            $parameters = [$owner->value, $owner->value];
        } elseif ($owner !== null) {
            $conditions[] = "r." . $q($dataset === "teaching" ? "faculty_user_id" : "owner_user_id") . " = ?";
            $parameters[] = $owner->value;
        }
        if ($search !== "") {
            $or = [];
            // Escape LIKE metacharacters with a portable explicit escape character.
            $term = "%" . str_replace(["!", "%", "_"], ["!!", "!%", "!_"], mb_strtolower($search)) . "%";
            foreach ($searchFields as $field) {
                $or[] = "LOWER(" . $field . ") LIKE ? ESCAPE '!'";
                $parameters[] = $term;
            }
            if ($or !== []) {
                $conditions[] = "(" . implode(" OR ", $or) . ")";
            }
        }
        if ($after !== null) {
            $conditions[] = "r." . $q("id") . " < ?";
            $parameters[] = $after->value;
        }
        $rows = $this->sql->fetch(
            "SELECT " .
                $select .
                " FROM " .
                $from .
                ($conditions === [] ? "" : " WHERE " . implode(" AND ", $conditions)) .
                " ORDER BY r." .
                $q("id") .
                " DESC",
            $parameters,
            51,
        );
        $more = count($rows) === 51;
        $rows = array_slice($rows, 0, 50);

        return ["items" => $rows, "next_after" => $more ? $rows[49]["id"] : null];
    }

    /** @return array{string, string, list<string>} */
    private function definition(string $dataset): array
    {
        $q = $this->sql->quote(...);
        $columns = static fn(array $names): string => implode(
            ", ",
            array_map(static fn(string $name): string => "r." . $q($name), $names),
        );
        if ($dataset === "terms") {
            return [
                "r.*, c." .
                $q("name") .
                ", c." .
                $q("kind") .
                ", c." .
                $q("academic_year_id") .
                ", y." .
                $q("code") .
                " AS " .
                $q("academic_year_code"),
                $q("sis_terms") .
                " r LEFT JOIN " .
                $q("sis_term_calendar") .
                " c ON c." .
                $q("id") .
                " = r." .
                $q("id") .
                " LEFT JOIN " .
                $q("sis_academic_years") .
                " y ON y." .
                $q("id") .
                " = c." .
                $q("academic_year_id"),
                ["r." . $q("code"), "c." . $q("name"), "y." . $q("code")],
            ];
        }
        if (in_array($dataset, ["people", "faculty"], true)) {
            return [
                "r." .
                $q("id") .
                ", r." .
                $q("given_name") .
                ", r." .
                $q("family_name") .
                ", r." .
                $q("owner_user_id") .
                ", i." .
                $q("institutional_id"),
                $q("sis_persons") .
                " r JOIN " .
                $q("sis_person_identifiers") .
                " i ON i." .
                $q("person_id") .
                " = r." .
                $q("id"),
                ["r." . $q("given_name"), "r." . $q("family_name"), "i." . $q("institutional_id")],
            ];
        }
        if (in_array($dataset, ["sections", "teaching", "registration"], true)) {
            $course = new \Infrastructure\Persistence\SectionCourseSql($this->sql);
            $code = $course->value("code", "c." . $q("code"));
            $title = $course->value("title", "c." . $q("title"));
            return [
                $columns([
                    "id",
                    "course_id",
                    "term_id",
                    "faculty_user_id",
                    "code",
                    "capacity",
                    "active",
                    "web_registration",
                    "fee_minor",
                    "currency",
                ]) .
                ", " .
                $code .
                " AS " .
                $q("course_code") .
                ", " .
                $title .
                " AS " .
                $q("course_title") .
                ", scb." .
                $q("course_version_id") .
                ", t." .
                $q("code") .
                " AS " .
                $q("term_code") .
                ", sd." .
                $q("mode") .
                " AS " .
                $q("delivery_mode"),
                $q("sis_sections") .
                " r JOIN " .
                $q("sis_courses") .
                " c ON c." .
                $q("id") .
                " = r." .
                $q("course_id") .
                " JOIN " .
                $q("sis_terms") .
                " t ON t." .
                $q("id") .
                " = r." .
                $q("term_id") .
                " LEFT JOIN " .
                $q("sis_section_delivery") .
                " sd ON sd." .
                $q("section_id") .
                " = r." .
                $q("id") .
                $course->joins("r"),
                ["r." . $q("code"), $code, $title, "t." . $q("code")],
            ];
        }
        if (in_array($dataset, ["external", "equivalencies"], true)) {
            [$select, $from] = new SqlTransferCatalogRepository($this->sql)->definition($dataset);
            $fields =
            $dataset === "external"
            ? ["r." . $q("code"), "r." . $q("title"), "i." . $q("name")]
            : [
                        "e." . $q("code"),
                        "e." . $q("title"),
                        "i." . $q("name"),
                        "c." . $q("code"),
                        "c." . $q("title"),
                    ];

            return [$select, $from, $fields];
        }
        if ($dataset === "timesheets") {
            return [
                $columns(["id", "employee_id", "starts_at", "ends_at", "status", "version"]) .
                ", p." .
                $q("given_name") .
                ", p." .
                $q("family_name") .
                ", i." .
                $q("institutional_id"),
                $q("sis_timesheets") .
                " r JOIN " .
                $q("sis_employees") .
                " e ON e." .
                $q("id") .
                " = r." .
                $q("employee_id") .
                " JOIN " .
                $q("sis_persons") .
                " p ON p." .
                $q("id") .
                " = e." .
                $q("person_id") .
                " JOIN " .
                $q("sis_person_identifiers") .
                " i ON i." .
                $q("person_id") .
                " = p." .
                $q("id"),
                [
                    "p." . $q("given_name"),
                    "p." . $q("family_name"),
                    "i." . $q("institutional_id"),
                    "r." . $q("status"),
                ],
            ];
        }
        if ($dataset === "accounts") {
            return [
                $columns(["id", "student_id", "term_id", "currency"]) .
                ", p." .
                $q("given_name") .
                ", p." .
                $q("family_name") .
                ", i." .
                $q("institutional_id"),
                $q("sis_student_accounts") .
                " r JOIN " .
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
                $q("id"),
                ["p." . $q("given_name"), "p." . $q("family_name"), "i." . $q("institutional_id")],
            ];
        }
        if (in_array($dataset, ["transferstudents", "registrationstudents"], true)) {
            return [
                $columns(["id", "person_id", "active"]) .
                ", p." .
                $q("given_name") .
                ", p." .
                $q("family_name") .
                ", i." .
                $q("institutional_id"),
                $q("sis_students") .
                " r JOIN " .
                $q("sis_persons") .
                " p ON p." .
                $q("id") .
                " = r." .
                $q("person_id") .
                " JOIN " .
                $q("sis_person_identifiers") .
                " i ON i." .
                $q("person_id") .
                " = p." .
                $q("id"),
                ["p." . $q("given_name"), "p." . $q("family_name"), "i." . $q("institutional_id")],
            ];
        }
        if (in_array($dataset, ["students", "applications", "employees"], true)) {
            $tables = [
                "students" => "sis_students",
                "applications" => "sis_applications",
                "employees" => "sis_employees",
            ];
            $table = $tables[$dataset];
            $extra = match ($dataset) {
                "students" => ["active", "primary_program_id", "entry_term_id"],
                "applications" => ["status", "program_id", "entry_term_id", "version"],
                default => ["job_title", "active"],
            };

            return [
                $columns(["id", "person_id", ...$extra]) .
                ", p." .
                $q("given_name") .
                ", p." .
                $q("family_name") .
                ", i." .
                $q("institutional_id"),
                $q($table) .
                " r JOIN " .
                $q("sis_persons") .
                " p ON p." .
                $q("id") .
                " = r." .
                $q("person_id") .
                " JOIN " .
                $q("sis_person_identifiers") .
                " i ON i." .
                $q("person_id") .
                " = p." .
                $q("id"),
                ["p." . $q("given_name"), "p." . $q("family_name"), "i." . $q("institutional_id")],
            ];
        }
        $definitions = [
            "programs" => ["sis_programs", ["id", "code", "title"], ["code", "title"]],
            "terms" => ["sis_terms", ["id", "code", "starts_on", "ends_on", "add_drop_ends_on"], ["code"]],
            "courses" => [
                "sis_courses",
                ["id", "code", "title", "credits_milli", "active"],
                ["code", "title"],
            ],
            "sections" => [
                "sis_sections",
                [
                    "id",
                    "course_id",
                    "term_id",
                    "faculty_user_id",
                    "code",
                    "capacity",
                    "active",
                    "web_registration",
                    "fee_minor",
                    "currency",
                ],
                ["code"],
            ],
            "rooms" => ["sis_rooms", ["id", "code", "capacity"], ["code"]],
            "bookings" => [
                "sis_bookings",
                ["id", "room_id", "title", "status", "version"],
                ["title", "status"],
            ],
            "timesheets" => [
                "sis_timesheets",
                ["id", "employee_id", "starts_at", "ends_at", "status", "version"],
                ["status"],
            ],
            "accounts" => ["sis_student_accounts", ["id", "student_id", "term_id", "currency"], ["currency"]],
            "journals" => ["sis_journals", ["id", "reference", "currency", "posted_on"], ["reference"]],
            "reports" => ["sis_reports", ["id", "name", "program_id", "term_id"], ["name"]],
            "portal" => [
                "sis_portal_posts",
                ["id", "code", "title", "kind", "status", "version"],
                ["title", "code"],
            ],
            "transcripts" => [
                "sis_transcript_requests",
                ["id", "student_id", "delivery", "status", "requested_at", "version"],
                ["status", "delivery"],
            ],
            "campaigns" => ["sis_campaigns", ["id", "status", "version"], ["status"]],
            "rules" => ["sis_rule_definitions", ["id", "name", "version"], ["name"]],
        ];
        $definitions += [
            "grades" => ["sis_grades", ["id", "code", "points_milli", "passes", "counts_gpa"], ["code"]],
            "billing" => ["sis_billing_rules", ["id", "term_id", "program_id"], []],
            "ledger" => ["sis_ledger_accounts", ["id", "code", "title", "currency"], ["code", "title"]],
            "institutions" => ["sis_external_institutions", ["id", "code", "name"], ["code", "name"]],
            "external" => [
                "sis_external_courses",
                ["id", "institution_id", "code", "title"],
                ["code", "title"],
            ],
            "equivalencies" => [
                "sis_transfer_equivalencies",
                [
                    "id",
                    "external_course_id",
                    "course_id",
                    "starts_on",
                    "ends_on",
                    "minimum_grade_rank",
                    "credits_milli",
                    "grade_id",
                ],
                [],
            ],
        ];
        $definitions["teaching"] = $definitions["sections"];
        $definitions["sharedreports"] = $definitions["reports"];
        if (!isset($definitions[$dataset])) {
            throw new InvalidArgumentException("Unknown workspace dataset.");
        }
        [$table, $fields, $search] = $definitions[$dataset];
        return [
            $columns($fields),
            $q($table) . " r",
            array_map(static fn(string $name): string => "r." . $q($name), $search),
        ];
    }

    public function metrics(array $permissions): array
    {
        $cards = [];
        $pipeline = [];
        $intake = [];
        $queue = [];
        $allowed = static fn(string $permission): bool => in_array($permission, $permissions, true);
        if ($allowed("sis:student:read")) {
            $cards[] = [
                "label" => "Active students",
                "value" => $this->count("sis_students", ["active" => 1]),
                "caption" => "Across all academic programs",
                "icon" => "mortarboard",
                "destination" => "students",
            ];
            $q = $this->sql->quote(...);
            $intake = $this->sql->fetch(
                "SELECT t." .
                    $q("code") .
                    " AS " .
                    $q("label") .
                    ", COUNT(s." .
                    $q("id") .
                    ") AS " .
                    $q("value") .
                    " FROM " .
                    $q("sis_terms") .
                    " t LEFT JOIN " .
                    $q("sis_students") .
                    " s ON s." .
                    $q("entry_term_id") .
                    " = t." .
                    $q("id") .
                    " GROUP BY t." .
                    $q("code") .
                    ", t." .
                    $q("starts_on") .
                    " ORDER BY t." .
                    $q("starts_on") .
                    " DESC",
                [],
                8,
            );
            $intake = array_reverse($intake);
        }
        if ($allowed("sis:admissions:manage")) {
            $cards[] = [
                "label" => "Applications to review",
                "value" => $this->count("sis_applications", ["status" => "submitted"]),
                "caption" => "Ready for an admissions decision",
                "icon" => "inbox",
                "destination" => "applications",
            ];
            foreach (["submitted", "accepted", "rejected", "matriculated"] as $status) {
                $pipeline[] = [
                    "label" => ucfirst($status),
                    "value" => $this->count("sis_applications", ["status" => $status]),
                ];
            }
        }
        if ($allowed("sis:registration:manage")) {
            $cards[] = [
                "label" => "Active course sections",
                "value" => $this->count("sis_sections", ["active" => 1]),
                "caption" => "Available in the course schedule",
                "icon" => "collection",
                "destination" => "sections",
            ];
        }
        $queues = [
            ["sis:scheduling:manage", "sis_bookings", "Room requests", "bookings"],
            ["sis:hr:manage", "sis_timesheets", "Timesheets", "timesheets"],
        ];
        foreach ($queues as [$permission, $table, $label, $destination]) {
            if ($allowed($permission)) {
                $queue[] = [
                    "label" => $label,
                    "value" => $this->count($table, ["status" => "pending"]),
                    "destination" => $destination,
                ];
            }
        }
        if ($allowed("sis:finance:read")) {
            $cards[] = [
                "label" => "Student accounts",
                "value" => $this->count("sis_student_accounts"),
                "caption" => "Statements with an auditable history",
                "icon" => "wallet2",
                "destination" => "accounts",
            ];
        }

        return ["cards" => $cards, "intake" => $intake, "pipeline" => $pipeline, "queue" => $queue];
    }

    /** @param array<string, scalar> $criteria */
    private function count(string $table, array $criteria = []): int
    {
        $q = $this->sql->quote(...);
        $where = [];
        foreach ($criteria as $field => $value) {
            $where[] = $q($field) . " = ?";
        }
        $rows = $this->sql->fetch(
            "SELECT COUNT(*) AS " .
                $q("total") .
                " FROM " .
                $q($table) .
                ($where === [] ? "" : " WHERE " . implode(" AND ", $where)),
            array_values($criteria),
        );

        return (int) $rows[0]["total"];
    }

    public function personWorkspace(EntityId $person, array $sections): array
    {
        $result = [];
        foreach ($sections as $section) {
            if ($section === "accounts") {
                $q = $this->sql->quote(...);
                $result[$section] = $this->sql->fetch(
                    "SELECT a." .
                        $q("id") .
                        ", a." .
                        $q("currency") .
                        ", a." .
                        $q("term_id") .
                        " FROM " .
                        $q("sis_student_accounts") .
                        " a JOIN " .
                        $q("sis_students") .
                        " s ON s." .
                        $q("id") .
                        " = a." .
                        $q("student_id") .
                        " WHERE s." .
                        $q("person_id") .
                        " = ?",
                    [$person->value],
                    50,
                );
            } else {
                $table = [
                    "students" => "sis_students",
                    "employees" => "sis_employees",
                    "applications" => "sis_applications",
                ][$section];
                $result[$section] = $this->sql->rows($table, ["person_id" => $person->value], 50);
            }
        }

        return $result;
    }

    public function ownPerson(EntityId $user): array
    {
        $row = $this->sql->one("sis_persons", ["owner_user_id" => $user->value]);

        return ["id" => $row["id"], "given_name" => $row["given_name"], "family_name" => $row["family_name"]];
    }
}
