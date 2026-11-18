<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Operational correction requests; completed answer artifacts are immutable and journal-bound. */
interface ApplicationRevisionRepository
{
    public function revision(EntityId $application): int;
    public function pending(EntityId $application): ?array;
    public function find(EntityId $id): ?array;
    public function save(
        #[\SensitiveParameter] array $record,
        int $expected,
        bool $new,
    ): void;
}
