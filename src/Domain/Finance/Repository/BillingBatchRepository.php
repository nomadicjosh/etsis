<?php

declare(strict_types=1);

namespace Domain\Finance\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Completed retry receipts only. Monetary authority remains in student-account events. */
interface BillingBatchRepository
{
    /** @return array{actor_id:string,request_hash:string,result:array<string,mixed>}|null */
    public function receipt(EntityId $id): ?array;

    /** @param array<string,mixed> $result */
    public function save(EntityId $id, EntityId $actor, EntityId $term, string $hash, array $result, string $on): void;
}
