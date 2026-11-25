<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\SectionProgramPolicyRepository;
use Domain\Registration\ValueObject\SectionProgramPolicy;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlSectionProgramPolicyRepository implements SectionProgramPolicyRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function current(EntityId $section): SectionProgramPolicy
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT " .
                $q("program_id") .
                " FROM " .
                $q("sis_section_program_eligibility") .
                " WHERE " .
                $q("section_id") .
                " = ? ORDER BY " .
                $q("program_id"),
            [$section->value],
            21,
        );
        try {
            $policy = $this->sql->rows('sis_section_program_policies', ['id' => $section->value], 1)[0] ?? null;
            $required = $policy === null ? false : match ($policy['require_program_campus']) {
                0, '0' => false,
                1, '1' => true,
                default => throw new CorruptHistoryException(),
            };
            return new SectionProgramPolicy(array_column($rows, "program_id"), $required);
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function program(EntityId $program): array
    {
        $r = $this->sql->one("sis_programs", ["id" => $program->value]);
        return array_intersect_key($r, array_flip(["id", "code", "title"]));
    }

    public function programs(EntityId $section): array
    {
        return array_map(
            fn(string $id): array => $this->program(new EntityId($id)),
            $this->current($section)->programIds,
        );
    }

    public function choices(string $search, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $text = str_replace(["!", "%", "_"], ["!!", "!%", "!_"], mb_strtolower($search));
        return $this->sql->fetch(
            "SELECT " .
                implode(", ", array_map($q, ["id", "code", "title"])) .
                " FROM " .
                $q("sis_programs") .
                " WHERE " .
                $q("id") .
                " > ?" .
                " AND (LOWER(" .
                $q("code") .
                ") LIKE ? ESCAPE '!' OR LOWER(" .
                $q("title") .
                ") LIKE ? ESCAPE '!') ORDER BY " .
                $q("id"),
            [$after?->value ?? "", "%" . $text . "%", "%" . $text . "%"],
            21,
        );
    }

    public function lockReferences(EntityId $section, array $additional = []): void
    {
        $ids = array_unique([...$this->current($section)->programIds, ...$additional]);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $this->sql->lock("sis_programs", new EntityId($id)->value, "id");
        }
    }

    public function assign(EntityId $section, SectionProgramPolicy $policy): void
    {
        $values = ['require_program_campus' => (int) $policy->requireCampus];
        if ($this->sql->rows('sis_section_program_policies', ['id' => $section->value], 1) === []) {
            $this->sql->insert('sis_section_program_policies', ['id' => $section->value] + $values);
        } else {
            $this->sql->update('sis_section_program_policies', $values, ['id' => $section->value]);
        }
        $old = $this->current($section)->programIds;
        $q = $this->sql->quote(...);
        foreach (array_diff($old, $policy->programIds) as $id) {
            $this->sql->execute(
                "DELETE FROM " .
                    $q("sis_section_program_eligibility") .
                    " WHERE " .
                    $q("section_id") .
                    " = ? AND " .
                    $q("program_id") .
                    " = ?",
                [$section->value, $id],
            );
        }
        foreach (array_diff($policy->programIds, $old) as $id) {
            $this->sql->insert("sis_section_program_eligibility", [
                "id" => EntityId::generate()->value,
                "section_id" => $section->value,
                "program_id" => $id,
            ]);
        }
    }
}
