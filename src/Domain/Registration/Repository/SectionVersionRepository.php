<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Registration\ValueObject\PublishedSectionDefinition;
use Domain\Shared\ValueObject\EntityId;

/** Internal reconstructible reference cache; callers own authorization and the section lock. */
interface SectionVersionRepository
{
    public function selected(EntityId $section): ?PublishedSectionDefinition;
    public function selectionPosition(EntityId $section): int;
    public function amend(
        PublishedSectionDefinition $before,
        PublishedSectionDefinition $after,
        int $expectedPosition,
    ): void;
    public function select(PublishedSectionDefinition $definition): void;
    public function enrollmentVersion(EntityId $enrollment): ?EntityId;
    public function retainEnrollmentVersion(EntityId $enrollment, ?EntityId $publication): void;
    public function find(EntityId $publication): ?PublishedSectionDefinition;
    /** Projection-only display read; missing current projection never falls back to an older version.
     * @return array{status: string, latest: ?PublishedSectionDefinition}
     */
    public function latest(EntityId $section): array;
    /** Only accept definitions verified against actual protected publication history. */
    public function retain(PublishedSectionDefinition $definition): void;
}
