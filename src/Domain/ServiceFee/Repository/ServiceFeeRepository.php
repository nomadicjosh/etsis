<?php

declare(strict_types=1);

namespace Domain\ServiceFee\Repository;

use Domain\ServiceFee\ServiceFee;
use Domain\ServiceFee\ValueObject\FeePolicy;
use Domain\Shared\ValueObject\EntityId;

interface ServiceFeeRepository
{
    public function policy(string $purpose): FeePolicy;
    public function savePolicy(
        string $purpose,
        FeePolicy $policy,
        int $expected,
    ): void;
    public function find(EntityId $id): ?array;
    public function active(
        EntityId $person,
        EntityId $owner,
        string $purpose,
        array $target,
    ): ?array;
    public function save(ServiceFee $root, int $expected): void;
    public function page(
        EntityId $person,
        ?EntityId $owner,
        ?EntityId $after = null,
    ): array;
}
