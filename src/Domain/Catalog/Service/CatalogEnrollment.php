<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\StudentProgram;

interface CatalogEnrollment
{
    /** Called within the authorized matriculation/program-assignment transaction. */
    public function initialize(StudentProgram $assignment, EntityId $actor): void;
}
