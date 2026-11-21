<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Repository;

use Domain\AcademicRecord\ValueObject\AcademicProjectionCheck;
use Domain\Shared\ValueObject\EntityId;

/** Trusted maintenance only. No repairs, academic decisions or ordinary reporting exposure. */
interface AcademicProjectionVerifier
{
    public function verify(EntityId $student, int $eventLimit = 500): AcademicProjectionCheck;
}
