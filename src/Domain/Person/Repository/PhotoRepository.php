<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Shared\ValueObject\EntityId;

interface PhotoRepository
{
    public function add(array $row): void;
    public function file(EntityId $id): ?array;
    public function update(EntityId $id, array $changes): void;
    public function remove(EntityId $id): void;
    public function due(string $now): array;
    public function active(EntityId $person): array;
    public function pending(EntityId $actor, string $now): array;
    public function email(EntityId $owner): string;
    /** Exact matches only; return at most two current canonical identities. */
    public function matches(string $stem, string $mode): array;
}
