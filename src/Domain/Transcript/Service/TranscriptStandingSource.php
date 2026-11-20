<?php

declare(strict_types=1);

namespace Domain\Transcript\Service;

use Domain\Shared\ValueObject\EntityId;

/** Internal verified source. The transcript coordinator authorizes the request and owns its locks. */
interface TranscriptStandingSource
{
    public function snapshot(EntityId $student, EntityId $pursuit, string $through): array;
}
