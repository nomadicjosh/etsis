<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\DegreeAudit\Repository\RequirementsRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\RecordNotFoundException;
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
    public function specialization(EntityId $program, EntityId $specialization): array
    {
        $row = $this->sql->one('sis_program_specializations', [
            'id' => $specialization->value, 'program_id' => $program->value,
        ]);
        return ['id' => $row['id'], 'code' => $row['code'], 'title' => $row['title']];
    }
    public function lock(EntityId $id): void
    {
        $this->sql->lock($this->table($id), $id->value, 'id');
    }
    /**
     * @throws JsonException
     */
    public function find(EntityId $program, EntityId $year, ?EntityId $specialization = null): ?array
    {
        $criteria = ['program_id' => $program->value, 'academic_year_id' => $year->value];
        if ($specialization !== null) {
            $criteria['specialization_id'] = $specialization->value;
        }
        $r =
        $this->sql->rows(
            $specialization === null ? 'sis_degree_audit_templates' : 'sis_specialization_audit_templates',
            $criteria,
            1,
        )[0] ?? null;
        return $r === null ? null : $this->decode($r);
    }
    /**
     * @throws JsonException
     */
    public function get(EntityId $id): array
    {
        return $this->decode($this->sql->one($this->table($id), ['id' => $id->value]));
    }
    public function page(EntityId $program, ?EntityId $after = null, ?EntityId $specialization = null): array
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
                $q($specialization === null ? 'sis_degree_audit_templates' : 'sis_specialization_audit_templates') .
                " t JOIN " .
                $q("sis_academic_years") .
                " y ON y." .
                $q("id") .
                " = t." .
                $q("academic_year_id") .
                " WHERE t." .
                $q("program_id") .
                " = ?" .
                ($specialization === null ? '' : ' AND t.' . $q('specialization_id') . ' = ?') .
                ($after === null ? "" : " AND t." . $q("id") . " < ?") .
                " ORDER BY t." .
                $q("id") .
                " DESC",
            [$program->value, ...($specialization === null ? [] : [$specialization->value]),
                ...($after === null ? [] : [$after->value])],
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
    public function create(
        EntityId $id,
        EntityId $program,
        EntityId $year,
        array $definition,
        ?EntityId $specialization = null,
    ): void {
        if ($specialization !== null) {
            $this->sql->one('sis_program_specializations', [
                'id' => $specialization->value, 'program_id' => $program->value,
            ]);
        }
        $table = $specialization === null ? 'sis_degree_audit_templates' : 'sis_specialization_audit_templates';
        $this->sql->insert($table, [
            "id" => $id->value,
            "program_id" => $program->value,
            "academic_year_id" => $year->value,
            "revision" => 0,
            "draft_json" => json_encode($definition, JSON_THROW_ON_ERROR),
            "published_version_id" => null,
            "publication_position" => 0,
        ] + ($specialization === null ? [] : ['specialization_id' => $specialization->value]));
    }
    public function save(EntityId $id, int $expected, array $definition): void
    {
        if (
            $this->sql->update(
                $this->table($id),
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
            $this->table($id),
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
        $row['specialization_id'] ??= null;
        return $row;
    }
    private function table(EntityId $id): string
    {
        $degree = $this->sql->rows('sis_degree_audit_templates', ['id' => $id->value], 1) !== [];
        $specialization = $this->sql->rows('sis_specialization_audit_templates', ['id' => $id->value], 1) !== [];
        if ($degree && $specialization) {
            throw new CorruptHistoryException();
        }
        if (!$degree && !$specialization) {
            throw new RecordNotFoundException('Requirements draft not found.');
        }
        return $degree ? 'sis_degree_audit_templates' : 'sis_specialization_audit_templates';
    }
}
