<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Portal\Repository\ProgramDiscoveryRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlProgramDiscoveryRepository implements ProgramDiscoveryRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function state(EntityId $program): ?array
    {
        return $this->sql->rows('sis_program_discovery', ['id' => $program->value], 1)[0] ?? null;
    }

    public function save(EntityId $program, array $values): void
    {
        if ($this->state($program) === null) {
            $this->sql->insert('sis_program_discovery', ['id' => $program->value] + $values);
        } else {
            $this->sql->update('sis_program_discovery', $values, ['id' => $program->value]);
        }
    }

    public function page(string $search, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $where = ['d.' . $q('visible') . ' = 1', 'c.' . $q('published') . ' = 1'];
        $values = [];
        if ($search !== '') {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
            $where[] = '(LOWER(c.' . $q('program_code') . ") LIKE ? ESCAPE '!' OR LOWER(c."
            . $q('program_title') . ") LIKE ? ESCAPE '!')";
            array_push($values, $pattern, $pattern);
        }
        if ($after !== null) {
            $where[] = 'd.' . $q('id') . ' > ?';
            $values[] = $after->value;
        }
        return $this->sql->fetch(
            'SELECT d.' . $q('id') . ', c.' . $q('program_code') . ' AS ' . $q('code')
                . ', c.' . $q('program_title') . ' AS ' . $q('title')
                . ', x.' . $q('academic_year_code') . ' AS ' . $q('academic_year_code')
                . ' FROM ' . $q('sis_program_discovery') . ' d JOIN ' . $q('sis_program_catalogs')
                . ' c ON c.' . $q('id') . ' = d.' . $q('catalog_id')
                . ' AND c.' . $q('program_id') . ' = d.' . $q('id')
                . ' JOIN ' . $q('sis_program_catalog_details') . ' x ON x.' . $q('id') . ' = c.' . $q('id')
                . ' AND x.' . $q('program_id') . ' = d.' . $q('id')
                . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY d.' . $q('id'),
            $values,
            13,
        );
    }

    public function choices(EntityId $program, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT c.' . $q('id') . ', c.' . $q('revision') . ', c.' . $q('program_title')
                . ', x.' . $q('academic_year_code') . ' AS ' . $q('year_code')
                . ' FROM ' . $q('sis_program_catalogs') . ' c JOIN ' . $q('sis_program_catalog_details')
                . ' x ON x.' . $q('id') . ' = c.' . $q('id') . ' AND x.' . $q('program_id')
                . ' = c.' . $q('program_id') . ' WHERE c.' . $q('program_id') . ' = ?'
                . ' AND c.' . $q('published') . ' = 1'
                . ($after === null ? '' : ' AND c.' . $q('id') . ' < ?') . ' ORDER BY c.' . $q('id') . ' DESC',
            $after === null ? [$program->value] : [$program->value, $after->value],
            51,
        );
    }
}
