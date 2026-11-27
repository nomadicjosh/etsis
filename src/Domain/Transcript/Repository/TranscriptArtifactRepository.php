<?php

declare(strict_types=1);

namespace Domain\Transcript\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Bounded operator reads only; implementations must not acquire business write locks. */
interface TranscriptArtifactRepository
{
    public function inTransaction(): bool;
    /** @return array<string,mixed>|null */
    public function document(EntityId $id): ?array;
    /** @return array<string,mixed>|null */
    public function request(EntityId $id): ?array;
    /** @return array<string,mixed>|null */
    public function delivery(EntityId $document): ?array;
}
