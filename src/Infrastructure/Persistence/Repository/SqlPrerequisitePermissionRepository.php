<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\PrerequisitePermissionRepository;
use Domain\Registration\PrerequisitePermission;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlPrerequisitePermissionRepository implements PrerequisitePermissionRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function current(EntityId $student, EntityId $pursuit, EntityId $section): ?array
    {
        $rows = $this->sql->rows(
            "sis_prerequisite_permissions",
            [
                "student_id" => $student->value,
                "pursuit_id" => $pursuit->value,
                "section_id" => $section->value,
            ],
            2,
        );
        if (count($rows) > 1) {
            throw new CorruptHistoryException();
        }
        if ($rows === []) {
            return null;
        }
        $r = $rows[0];
        try {
            new EntityId($r["id"]);
            new EntityId($r["operation_id"]);
            $position = filter_var($r["position"], FILTER_VALIDATE_INT);
            if ($position === false || $position < 1 || $position > 2147483646) {
                throw new CorruptHistoryException();
            }
            $approval =
            $r["approval"] === null ? null : json_decode($r["approval"], true, 8, JSON_THROW_ON_ERROR);
            return [
                "id" => $r["id"],
                "position" => $position,
                "operation_id" => $r["operation_id"],
                "scope" => [
                    "student_id" => $r["student_id"],
                    "pursuit_id" => $r["pursuit_id"],
                    "section_id" => $r["section_id"],
                ],
                "approval" => PrerequisitePermission::approval($approval),
            ];
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function project(EntityId $id, array $state): void
    {
        $row = $state["scope"] + [
            "position" => $state["position"],
            "operation_id" => $state["operation_id"],
            "approval" =>
                $state["approval"] === null ? null : json_encode($state["approval"], JSON_THROW_ON_ERROR),
        ];
        if ($this->sql->rows("sis_prerequisite_permissions", ["id" => $id->value], 1) === []) {
            $this->sql->insert("sis_prerequisite_permissions", ["id" => $id->value] + $row);
        } else {
            $this->sql->update("sis_prerequisite_permissions", $row, ["id" => $id->value]);
        }
    }

    public function sections(string $search, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $like = "%" . str_replace(["!", "%", "_"], ["!!", "!%", "!_"], mb_strtolower($search)) . "%";
        $params = [$like, $like, $like];
        if ($after !== null) {
            $params[] = $after->value;
        }
        $rows = $this->sql->fetch(
            "SELECT s." .
                $q("id") .
                ", s." .
                $q("code") .
                ", c." .
                $q("title") .
                " AS course_title, t." .
                $q("code") .
                " AS term_code FROM " .
                $q("sis_sections") .
                " s JOIN " .
                $q("sis_courses") .
                " c ON c." .
                $q("id") .
                " = s." .
                $q("course_id") .
                " JOIN " .
                $q("sis_terms") .
                " t ON t." .
                $q("id") .
                " = s." .
                $q("term_id") .
                " WHERE (LOWER(s." .
                $q("code") .
                ") LIKE ? ESCAPE '!' OR LOWER(c." .
                $q("title") .
                ") LIKE ? ESCAPE '!' OR LOWER(t." .
                $q("code") .
                ") LIKE ? ESCAPE '!')" .
                ($after === null ? "" : " AND s." . $q("id") . " > ?") .
                " ORDER BY s." .
                $q("id"),
            $params,
            21,
        );
        return [
            "items" => array_slice($rows, 0, 20),
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }
}
