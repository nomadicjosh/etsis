<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StandingPolicyTargetRepository;
use Domain\Student\ValueObject\StandingProposalRules;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlStandingPolicyTargetRepository implements StandingPolicyTargetRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    private function table(string $kind): string
    {
        return match ($kind) {
            'program' => 'sis_programs',
            'academic_level' => 'sis_catalog_classifications',
            default => throw new \InvalidArgumentException('Use a supported standing policy target.'),
        };
    }

    public function page(string $kind, string $search, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $table = $this->table($kind);
        $where = $q('id') . ' > ?';
        $values = [$after?->value ?? ''];
        if ($kind === 'academic_level') {
            $where .= ' AND ' . $q('kind') . ' = ?';
            $values[] = 'academic_level';
        }
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
        $where .= ' AND (LOWER(' . $q('code') . ") LIKE ? ESCAPE '!' OR LOWER("
        . $q('title') . ") LIKE ? ESCAPE '!')";
        array_push($values, '%' . $escaped . '%', '%' . $escaped . '%');
        $columns = ['id', 'code', 'title', ...$kind === 'academic_level' ? ['active'] : []];
        $rows = $this->sql->fetch('SELECT ' . implode(', ', array_map($q, $columns)) . ' FROM ' . $q($table)
            . ' WHERE ' . $where . ' ORDER BY ' . $q('id'), $values, 21);
        return array_map(fn(array $row): array => $this->normalize($kind, $row), $rows);
    }

    public function get(string $kind, EntityId $id): array
    {
        $row = $this->sql->rows($this->table($kind), ['id' => $id->value], 1)[0] ?? null;
        if ($row === null || ($kind === 'academic_level' && $row['kind'] !== 'academic_level')) {
            throw new RecordNotFoundException('Standing policy reference not found.');
        }
        return $this->normalize($kind, $row);
    }

    private function normalize(string $kind, array $row): array
    {
        $target = array_intersect_key($row, array_flip(['id', 'code', 'title']));
        StandingProposalRules::target($kind, $target);
        return $target + ['active' => $kind === 'program' || (bool) $row['active']];
    }

    public function lock(string $kind, EntityId $id): void
    {
        $this->sql->lock($this->table($kind), $id->value, 'id');
        $this->get($kind, $id);
    }
}
