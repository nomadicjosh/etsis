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

    public function page(
        EntityId $program,
        string $search,
        ?EntityId $after,
        ?\Domain\Catalog\ValueObject\CurriculumComponentKind $kind = null,
    ): array {
        $q = $this->sql->quote(...);
        $where = [$q("program_id") . " = ?"];
        $params = [$program->value];
        if ($kind !== null) {
            $where[] = $q("kind") . " = ?";
            $params[] = $kind->value;
        }
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
                        "program_id",
                        "code",
                        "title",
                        "active",
                        "revision",
                        "kind",
                        "department_id",
                        "parent_major_id",
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
        return $this->withMajors($this->withDepartments($rows));
    }

    public function get(EntityId $program, EntityId $id): ProgramSpecialization
    {
        $row = $this->sql->one("sis_program_specializations", [
            "id" => $id->value,
            "program_id" => $program->value,
        ]);
        $row = $this->withJointIds([$row])[0];
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
            $row["parent_major_id"] === null ? null : new EntityId($row["parent_major_id"]),
            array_map(static fn (string $id): EntityId => new EntityId($id), $row['additional_department_ids']),
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
        unset($row['additional_department_ids']);
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
        $current = $this->sql->rows('sis_component_departments', ['component_id' => $value->id->value], 6);
        if (count($current) > 5) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $ids = $value->describe()['additional_department_ids'];
        foreach ($current as $reference) {
            if (!in_array($reference['department_id'], $ids, true)) {
                $this->sql->execute('DELETE FROM ' . $this->sql->quote('sis_component_departments')
                    . ' WHERE ' . $this->sql->quote('id') . ' = ?', [$reference['id']]);
            }
        }
        $existing = array_column($current, 'department_id');
        foreach ($ids as $department) {
            if (!in_array($department, $existing, true)) {
                $this->sql->insert('sis_component_departments', ['id' => EntityId::generate()->value,
                    'component_id' => $value->id->value, 'department_id' => $department]);
            }
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
                'additional_departments' => $row['additional_departments'],
                "parent_major" => $row["parent_major"],
            ],
            $this->withMajors($this->withDepartments($rows)),
        );
    }
    /** One bounded lookup for a page/publication; never resolve a foreign-program parent. */
    private function withMajors(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_column($rows, "parent_major_id"))));
        $majors = [];
        if ($ids !== []) {
            $q = $this->sql->quote(...);
            $majors = array_column(
                $this->sql->fetch(
                    "SELECT " .
                        implode(", ", array_map($q, ["id", "program_id", "code", "title"])) .
                        " FROM " .
                        $q("sis_program_specializations") .
                        " WHERE " .
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
            $parent =
            $row["parent_major_id"] === null
            ? null
            : $majors[$row["parent_major_id"]] ??
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            if ($parent !== null && $parent["program_id"] !== $row["program_id"]) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            $row["parent_major"] =
            $parent === null
            ? null
            : array_intersect_key($parent, array_flip(["id", "code", "title"]));
        }
        unset($row);
        return $rows;
    }

    private function withDepartments(array $rows): array
    {
        $rows = $this->withJointIds($rows);
        $ids = array_values(array_unique(array_filter(array_column($rows, "department_id"))));
        foreach ($rows as $row) {
            $ids = array_merge($ids, $row['additional_department_ids']);
        }
        $ids = array_values(array_unique($ids));
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
                    601,
                ),
                null,
                "id",
            );
        }
        $definition = static function (string $id) use ($departments): array {
            $d =
            $departments[$id] ??
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            if (
                $d["kind"] !== "department" ||
                ($d["school_id"] !== null && $d["school_ref_kind"] !== "school")
            ) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            return new \Domain\Catalog\ValueObject\DepartmentDefinition([
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
        };
        foreach ($rows as &$row) {
            $row['department'] = $row['department_id'] === null ? null : $definition($row['department_id']);
            $row['additional_departments'] = new \Domain\Catalog\ValueObject\AdditionalDepartments(
                array_map($definition, $row['additional_department_ids']),
                $row['department_id'] === null ? null : new EntityId($row['department_id']),
            )->definitions;
        }
        unset($row);
        return $rows;
    }

    private function withJointIds(array $rows): array
    {
        $owners = [];
        if ($rows !== []) {
            $q = $this->sql->quote(...);
            $ids = array_column($rows, 'id');
            $references = $this->sql->fetch('SELECT ' . $q('component_id') . ', ' . $q('department_id')
                . ' FROM ' . $q('sis_component_departments') . ' WHERE ' . $q('component_id')
                . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids, 501);
            if (count($references) > 500) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            foreach ($references as $reference) {
                $owners[$reference['component_id']][] = new EntityId($reference['department_id']);
            }
        }
        foreach ($rows as &$row) {
            $row['additional_department_ids'] = array_map(
                static fn (EntityId $id): string => $id->value,
                \Domain\Catalog\ValueObject\AdditionalDepartments::ids(
                    $owners[$row['id']] ?? [],
                    $row['department_id'] === null ? null : new EntityId($row['department_id'])
                )
            );
        }
        unset($row);
        return $rows;
    }
}
