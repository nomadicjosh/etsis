<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;

final readonly class SqlCatalogRevisionRepository implements CatalogRevisionRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    private function table(string $kind): string
    {
        return match ($kind) {
            'courses' => 'sis_courses', 'sections' => 'sis_sections',
            'programs' => 'sis_programs', 'terms' => 'sis_terms',
            default => throw new InvalidArgumentException('Unknown catalog record.'),
        };
    }

    public function get(string $kind, EntityId $id): array
    {
        return $this->sql->one($this->table($kind), ['id' => $id->value]);
    }

    public function lock(string $kind, EntityId $id): void
    {
        $this->sql->lock($this->table($kind), $id->value, 'id');
    }

    public function save(string $kind, EntityId $id, array $values): void
    {
        $this->sql->update($this->table($kind), $values, ['id' => $id->value]);
    }
}
