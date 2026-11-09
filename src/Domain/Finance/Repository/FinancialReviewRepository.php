<?php

declare(strict_types=1);

namespace Domain\Finance\Repository;

use Domain\Finance\FinancialReview;
use Domain\Shared\ValueObject\EntityId;

interface FinancialReviewRepository
{
    public function lock(EntityId $id): void;
    public function get(EntityId $id): FinancialReview;
    public function forHiatus(EntityId $hiatus): ?FinancialReview;
    public function add(FinancialReview $review): void;
    public function save(FinancialReview $review, int $expectedVersion): void;
    /** @return list<array<string, mixed>> */
    public function page(?string $status, ?EntityId $after): array;
    /** @return array<string, mixed>|null */
    public function action(EntityId $operation): ?array;
    public function record(EntityId $operation, EntityId $review, EntityId $actor, string $encrypted, string $on): void;
    /** @return list<array<string, mixed>> */
    public function history(EntityId $review, ?EntityId $after): array;
}
