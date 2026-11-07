<?php

declare(strict_types=1);

namespace Domain\Portal\Repository;

use Domain\Shared\ValueObject\EntityId;

interface PaymentRepository
{
    /** @return array<string, mixed>|null */
    public function find(EntityId $id): ?array;

    /** @return array<string, mixed>|null */
    public function active(EntityId $account, int $now): ?array;

    /** @param array<string, scalar|null> $record */
    public function add(EntityId $id, array $record): void;

    public function lock(EntityId $id): void;

    public function attach(EntityId $id, string $session, string $encryptedUrl): void;

    public function paid(EntityId $id): void;

    public function expire(EntityId $id): void;
}
