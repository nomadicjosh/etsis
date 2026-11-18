<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Registration\ValueObject\SectionClassifications;
use Domain\Shared\ValueObject\EntityId;

interface SectionClassificationRepository
{
    /** Caller holds the section lock before locking reference definitions in identity order.
     * @param list<EntityId> $additional */
    public function lockReferences(
        EntityId $section,
        array $additional = [],
    ): void;
    public function current(EntityId $section): SectionClassifications;
    public function assign(
        EntityId $section,
        SectionClassifications $values,
    ): void;
}
