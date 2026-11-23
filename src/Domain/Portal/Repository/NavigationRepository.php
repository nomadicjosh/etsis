<?php

declare(strict_types=1);

namespace Domain\Portal\Repository;

use Domain\Shared\ValueObject\EntityId;

interface NavigationRepository
{
    public function get(string $slot): array;
    public function lock(string $slot): void;
    public function save(string $slot, array $values): void;
    public function receipt(EntityId $operation): ?array;
    public function saveReceipt(EntityId $operation, array $receipt): void;
}
