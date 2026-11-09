<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Catalog\ValueObject\CourseText;
use Domain\Shared\ValueObject\EntityId;

/** Rebuildable current-publication text; retained definitions remain authoritative in the journal. */
interface CourseTextProjection
{
    public function apply(EntityId $course, CourseText $text, EntityId $version, int $position): void;
}
