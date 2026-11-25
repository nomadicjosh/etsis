<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StandingProposalTermRepository;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlStandingProposalTermRepository implements StandingProposalTermRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function page(string $search, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
        return $this->sql->fetch('SELECT ' . implode(', ', array_map($q, ['id', 'code', 'starts_on', 'ends_on']))
            . ' FROM ' . $q('sis_terms') . ' WHERE ' . $q('id') . ' > ? AND LOWER(' . $q('code')
            . ") LIKE ? ESCAPE '!' ORDER BY " . $q('id'), [$after?->value ?? '', '%' . $escaped . '%'], 21);
    }
}
