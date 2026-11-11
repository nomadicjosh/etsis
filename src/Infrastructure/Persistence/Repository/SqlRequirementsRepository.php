<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\DegreeAudit\Repository\RequirementsRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use JsonException;

final readonly class SqlRequirementsRepository implements RequirementsRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function context(EntityId $program, EntityId $year): array
    {
        $p = $this->sql->one("sis_programs", ["id" => $program->value]);
        $y = $this->sql->one("sis_academic_years", ["id" => $year->value]);
        return [
            "program_id" => $p["id"],
            "program_code" => $p["code"],
            "program_title" => $p["title"],
            "academic_year_id" => $y["id"],
            "academic_year_code" => $y["code"],
        ];
    }
    public function lockContext(EntityId $program, EntityId $year): void
    {
        $this->sql->lock("sis_programs", $program->value, "id");
        $this->sql->lock("sis_academic_years", $year->value, "id");
    }
    public function lock(EntityId $id): void
    {
        $this->sql->lock("sis_degree_audit_templates", $id->value, "id");
    }
    /**
     * @throws JsonException
     */
    public function find(EntityId $program, EntityId $year): ?array
    {
        $r =
        $this->sql->rows(
            "sis_degree_audit_templates",
            ["program_id" => $program->value, "academic_year_id" => $year->value],
            1,
        )[0] ?? null;
        return $r === null ? null : $this->decode($r);
    }
    /**
     * @throws JsonException
     */
    public function get(EntityId $id): array
    {
        return $this->decode($this->sql->one("sis_degree_audit_templates", ["id" => $id->value]));
    }
    public function page(EntityId $program, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $columns = array_map(static fn(string $column): string => "t." . $q($column), [
            "id",
            "program_id",
            "academic_year_id",
            "revision",
            "published_version_id",
            "publication_position",
        ]);
        $rows = $this->sql->fetch(
            "SELECT " .
                implode(", ", $columns) .
                ", y." .
                $q("code") .
                " AS " .
                $q("academic_year_code") .
                " FROM " .
                $q("sis_degree_audit_templates") .
                " t JOIN " .
                $q("sis_academic_years") .
                " y ON y." .
                $q("id") .
                " = t." .
                $q("academic_year_id") .
                " WHERE t." .
                $q("program_id") .
                " = ?" .
                ($after === null ? "" : " AND t." . $q("id") . " < ?") .
                " ORDER BY t." .
                $q("id") .
                " DESC",
            $after === null ? [$program->value] : [$program->value, $after->value],
            51,
        );
        foreach ($rows as &$row) {
            $row["revision"] = (int) $row["revision"];
            $row["publication_position"] = (int) $row["publication_position"];
        }
        unset($row);
        return $rows;
    }
    /**
     * @throws JsonException
     */
    public function create(EntityId $id, EntityId $program, EntityId $year, array $definition): void
    {
        $this->sql->insert("sis_degree_audit_templates", [
            "id" => $id->value,
            "program_id" => $program->value,
            "academic_year_id" => $year->value,
            "revision" => 0,
            "draft_json" => json_encode($definition, JSON_THROW_ON_ERROR),
            "published_version_id" => null,
            "publication_position" => 0,
        ]);
    }
    public function save(EntityId $id, int $expected, array $definition): void
    {
        if (
            $this->sql->update(
                "sis_degree_audit_templates",
                ["revision" => $expected + 1, "draft_json" => json_encode($definition, JSON_THROW_ON_ERROR)],
                ["id" => $id->value, "revision" => $expected],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The requirement draft changed. Reload before saving.");
        }
    }
    public function published(EntityId $id, EntityId $version, int $position): void
    {
        $this->sql->update(
            "sis_degree_audit_templates",
            ["published_version_id" => $version->value, "publication_position" => $position],
            ["id" => $id->value],
        );
    }
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function decode(array $row): array
    {
        $row["definition"] = json_decode($row["draft_json"], true, 16, JSON_THROW_ON_ERROR);
        unset($row["draft_json"]);
        $row["revision"] = (int) $row["revision"];
        $row["publication_position"] = (int) $row["publication_position"];
        return $row;
    }
}
