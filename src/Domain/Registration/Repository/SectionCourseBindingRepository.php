<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\Shared\ValueObject\EntityId;

interface SectionCourseBindingRepository
{
    public function bound(EntityId $section): ?PublishedCourseDefinition;
    public function hasStudentRecords(EntityId $section): bool;
    /** Project a verified definition and binding within the section's enclosing transaction/lock. */
    public function retain(EntityId $section, PublishedCourseDefinition $definition): void;
}
