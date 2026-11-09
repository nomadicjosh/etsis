<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;

interface RestrictionRepository
{
    public function lockPerson(EntityId $person): void;
    public function lock(EntityId $restriction): void;
    public function find(EntityId $restriction): ?Restriction;
    public function add(Restriction $restriction): void;
    public function setEnd(EntityId $restriction, string $on): void;
    /** @return list<array<string, mixed>> */
    public function page(EntityId $person, ?EntityId $after, int $limit): array;
    /** @return array<string, mixed>|null */
    public function note(EntityId $id): ?array;
    public function addNote(
        EntityId $id,
        EntityId $restriction,
        EntityId $actor,
        #[\SensitiveParameter] string $encrypted,
        string $createdAt
    ): void;
    /** @return list<array<string, mixed>> */
    public function notes(EntityId $restriction, ?EntityId $after, int $limit): array;
}
