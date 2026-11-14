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
        $where = [$q('program_id') . ' = ?'];
        $params = [$program->value];
        if ($search !== '') {
            $where[] = '(' . $q('code') . " LIKE ? ESCAPE '!' OR " . $q('title') . " LIKE ? ESCAPE '!')";
            $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            array_push($params, $pattern, $pattern);
        }
        if ($after !== null) {
            $where[] = $q('id') . ' > ?';
            $params[] = $after->value;
        }
        $rows = $this->sql->fetch(
            'SELECT ' .
                implode(', ', array_map($q, ['id', 'code', 'title', 'active', 'revision'])) .
                ' FROM ' .
                $q('sis_program_specializations') .
                ' WHERE ' .
                implode(' AND ', $where) .
                ' ORDER BY ' .
                $q('id'),
            $params,
            21,
        );
        foreach ($rows as &$row) {
            $row['active'] = (bool) $row['active'];
            $row['revision'] = (int) $row['revision'];
        }
        unset($row);
        return $rows;
    }

    public function get(EntityId $program, EntityId $id): ProgramSpecialization
    {
        $row = $this->sql->one('sis_program_specializations', [
            'id' => $id->value,
            'program_id' => $program->value,
        ]);
        return new ProgramSpecialization(
            $id,
            $program,
            $row['code'],
            $row['title'],
            $row['description'],
            (bool) $row['active'],
            (int) $row['revision'],
        );
    }

    public function save(ProgramSpecialization $value, bool $create): void
    {
        $other =
        $this->sql->rows(
            'sis_program_specializations',
            ['program_id' => $value->programId->value, 'code' => $value->code],
            1,
        )[0] ?? null;
        if ($other !== null && $other['id'] !== $value->id->value) {
            throw new ValidationException([
                ['field' => 'code', 'message' => 'That specialization code is already used in this program.'],
            ]);
        }
        $row = $value->describe();
        $row['active'] = (int) $value->active;
        if ($create) {
            $this->sql->insert('sis_program_specializations', $row);
        } else {
            unset($row['id'], $row['program_id']);
            $this->sql->update('sis_program_specializations', $row, [
                'id' => $value->id->value,
                'program_id' => $value->programId->value,
            ]);
        }
    }
}
