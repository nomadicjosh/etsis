<?php

declare(strict_types=1);

namespace Domain\Transfer\Repository;

use Domain\Shared\ValueObject\EntityId;

interface TransferCreditRepository
{
    /** @return array<string,mixed>|null */ public function existing(
        EntityId $student,
        EntityId $equivalency,
        string $completed,
    ): ?array;
    /** @return array<string,mixed> */ public function award(EntityId $award): array;
    /** @return array<string,mixed>|null */ public function mapping(EntityId $award): ?array;
    /** @return array<string,mixed> */ public function credit(EntityId $credit): array;
    /** @return array<string,mixed> */ public function course(EntityId $course): array;
    /** @return array<string,mixed> */ public function term(EntityId $term): array;
    /** @param array<string,mixed> $source
     * @param array<string,mixed> $credit */ public function create(array $source, array $credit): void;
    /** @param array<string,mixed> $credit */ public function project(
        array $credit,
        int $expectedVersion,
    ): void;
    /** @param array<string,mixed> $source
     * @param array<string,mixed> $credit */ public function track(array $source, array $credit): void;
}
