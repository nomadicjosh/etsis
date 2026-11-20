<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface RegistrationWindowRepository
{
    public function term(EntityId $term): array;
    public function current(EntityId $term): ?array;
    public function lock(EntityId $term): void;
    public function publish(
        EntityId $term,
        array $definition,
        array $reference,
    ): void;
}
