<?php

declare(strict_types=1);

namespace Domain\Transcript\Service;

use Domain\Shared\ValueObject\EntityId;

/** Internal verified source. The transcript coordinator authorizes the request and owns its locks. */
interface TranscriptStandingSource
{
    /** @param list<string>|null $definitions Filter retained identities before minimizing their display facts. */
    public function snapshot(EntityId $student, EntityId $pursuit, string $through, ?array $definitions = null): array;
}
