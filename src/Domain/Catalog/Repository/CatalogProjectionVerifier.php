<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Catalog\ValueObject\CatalogProjectionCheck;
use Domain\Shared\ValueObject\EntityId;

/** Trusted operators; browser callers separately authorize catalog and support duties. */
interface CatalogProjectionVerifier
{
    public function course(EntityId $course, int $eventLimit = 500): CatalogProjectionCheck;
    public function section(EntityId $section, int $eventLimit = 500): CatalogProjectionCheck;
    public function curriculum(string $kind, EntityId $id, int $eventLimit = 500): CatalogProjectionCheck;
    /** Current non-personal routing only, without drafts or protected publication payloads. */
    public function curriculumContext(string $kind, EntityId $id): array;
}
