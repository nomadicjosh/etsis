<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface RegistrationBatchRepository
{
    /** @return array{actor_id: string, request_hash: string, result: array<string, mixed>}|null */
    public function receipt(EntityId $id): ?array;
    /** @param array<string, mixed> $result */
    public function save(
        EntityId $id,
        EntityId $actor,
        EntityId $section,
        string $hash,
        array $result,
        string $on
    ): void;
}
