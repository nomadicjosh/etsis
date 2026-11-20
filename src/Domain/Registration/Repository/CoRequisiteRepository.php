<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface CoRequisiteRepository
{
    public function policy(): array;
    public function savePolicy(bool $prior, string $mode, int $revision): void;
    /** Current candidates plus unclosed recorded roots, at most 101 identities. */
    public function activeBook(EntityId $student): array;
}
