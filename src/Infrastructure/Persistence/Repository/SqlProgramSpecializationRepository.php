<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\ProgramSpecialization;
use Domain\Catalog\Repository\ProgramSpecializationRepository;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlProgramSpecializationRepository implements ProgramSpecializationRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function page(EntityId $program, string $search, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $where = [$q("program_id") . " = ?"];
        $params = [$program->value];
        if ($search !== "") {
            $where[] =
            "(" . $q("code") . " LIKE ? ESCAPE '!' OR " . $q("title") . " LIKE ? ESCAPE '!')";
            $pattern = "%" . strtr($search, ["!" => "!!", "%" => "!%", "_" => "!_"]) . "%";
            array_push($params, $pattern, $pattern);
        }
        if ($after !== null) {
            $where[] = $q("id") . " > ?";
            $params[] = $after->value;
        }
        $rows = $this->sql->fetch(
            "SELECT " .
                implode(
                    ", ",
                    array_map($q, [
                        "id",
                        "code",
                        "title",
                        "active",
                        "revision",
                        "kind",
                        "department_id",
                    ]),
                ) .
                " FROM " .
                $q("sis_program_specializations") .
                " WHERE " .
                implode(" AND ", $where) .
                " ORDER BY " .
                $q("id"),
            $params,
            21,
        );
        foreach ($rows as &$row) {
            $row["active"] = (bool) $row["active"];
            $row["revision"] = (int) $row["revision"];
        }
        unset($row);
        return $this->withDepartments($rows);
    }

    public function get(EntityId $program, EntityId $id): ProgramSpecialization
    {
        $row = $this->sql->one("sis_program_specializations", [
            "id" => $id->value,
            "program_id" => $program->value,
        ]);
        return new ProgramSpecialization(
            $id,
            $program,
            $row["code"],
            $row["title"],
            $row["description"],
            (bool) $row["active"],
            (int) $row["revision"],
            $row["kind"] === null
                ? null
                : \Domain\Catalog\ValueObject\CurriculumComponentKind::from($row["kind"]),
            $row["department_id"] === null ? null : new EntityId($row["department_id"]),
        );
    }

    public function save(ProgramSpecialization $value, bool $create): void
    {
        $other =
        $this->sql->rows(
            "sis_program_specializations",
            ["program_id" => $value->programId->value, "code" => $value->code],
            1,
        )[0] ?? null;
        if ($other !== null && $other["id"] !== $value->id->value) {
            throw new ValidationException([
                [
                    "field" => "code",
                    "message" => "That specialization code is already used in this program.",
                ],
            ]);
        }
        $row = $value->describe();
        $row["active"] = (int) $value->active;
        if ($create) {
            $this->sql->insert("sis_program_specializations", $row);
        } else {
            unset($row["id"], $row["program_id"]);
            $this->sql->update("sis_program_specializations", $row, [
                "id" => $value->id->value,
                "program_id" => $value->programId->value,
            ]);
        }
    }

    public function offered(EntityId $program): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_program_specializations") .
                " WHERE " .
                $q("program_id") .
                " = ? AND " .
                $q("active") .
                " = ? ORDER BY " .
                $q("id"),
            [$program->value, 1],
            101,
        );
        if (count($rows) > 100) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "specializations",
                    "message" => "Publish at most 100 active choices for one program.",
                ],
            ]);
        }
        return array_map(
            static fn(array $row): array => [
                "id" => $row["id"],
                "code" => $row["code"],
                "title" => $row["title"],
                "kind" => $row["kind"],
                "description" => $row["description"],
                "revision" => (int) $row["revision"],
                "department" => $row["department"],
            ],
            $this->withDepartments($rows),
        );
    }
    private function withDepartments(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_column($rows, "department_id"))));
        $departments = [];
        if ($ids !== []) {
            $q = $this->sql->quote(...);
            $select = [];
            foreach (["id", "kind", "code", "title", "school_id"] as $key) {
                $select[] = "d." . $q($key);
            }
            foreach (["id", "kind", "code", "title"] as $key) {
                $select[] = "s." . $q($key) . " AS " . $q("school_ref_" . $key);
            }
            $departments = array_column(
                $this->sql->fetch(
                    "SELECT " .
                        implode(", ", $select) .
                        " FROM " .
                        $q("sis_catalog_classifications") .
                        " d LEFT JOIN " .
                        $q("sis_catalog_classifications") .
                        " s ON d." .
                        $q("school_id") .
                        " = s." .
                        $q("id") .
                        " WHERE d." .
                        $q("id") .
                        " IN (" .
                        implode(",", array_fill(0, count($ids), "?")) .
                        ")",
                    $ids,
                    100,
                ),
                null,
                "id",
            );
        }
        foreach ($rows as &$row) {
            $row["department"] = null;
            if ($row["department_id"] === null) {
                continue;
            }
            $d =
            $departments[$row["department_id"]] ??
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            if (
                $d["kind"] !== "department" ||
                ($d["school_id"] !== null && $d["school_ref_kind"] !== "school")
            ) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            $row["department"] = new \Domain\Catalog\ValueObject\DepartmentDefinition([
                "id" => $d["id"],
                "code" => $d["code"],
                "title" => $d["title"],
                "school" =>
                    $d["school_id"] === null
                        ? null
                        : [
                            "id" => $d["school_id"],
                            "code" => $d["school_ref_code"],
                            "title" => $d["school_ref_title"],
                        ],
            ])->data;
        }
        unset($row);
        return $rows;
    }
}
