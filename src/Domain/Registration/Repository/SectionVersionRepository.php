<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Registration\ValueObject\PublishedSectionDefinition;
use Domain\Shared\ValueObject\EntityId;

/** Internal reconstructible reference cache; callers own authorization and the section lock. */
interface SectionVersionRepository
{
    public function find(EntityId $publication): ?PublishedSectionDefinition;
    /** Only accept definitions verified against actual protected publication history. */
    public function retain(PublishedSectionDefinition $definition): void;
}
