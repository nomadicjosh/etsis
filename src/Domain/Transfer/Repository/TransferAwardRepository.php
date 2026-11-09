<?php

declare(strict_types=1);

namespace Domain\Transfer\Repository;

use Domain\Shared\ValueObject\EntityId;

interface TransferAwardRepository
{
    /** @return array<string, mixed> */
    public function get(EntityId $student, EntityId $award): array;
    public function lock(EntityId $award, EntityId $credit): void;
    public function lockGrade(EntityId $grade): void;
    /** @return array<string, mixed> */
    public function grade(string $code): array;
    /** @param array<string, scalar|null> $values */
    public function apply(EntityId $credit, int $version, array $values): void;
    /** @return array<string, mixed>|null */
    public function action(EntityId $operation): ?array;
    public function record(
        EntityId $operation,
        EntityId $award,
        EntityId $credit,
        EntityId $actor,
        int $version,
        string $kind,
        string $encryptedContent,
        string $createdAt,
    ): void;
    /** @return list<array<string, mixed>> */
    public function history(EntityId $award, ?EntityId $after): array;
}
