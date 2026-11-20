<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use Domain\Shared\ValueObject\EntityId;

/** Assignment-scoped degree/program reading only; never a broad StudentAccess permission. */
interface AdvisorProgressAccess
{
    public function requireAdvisor(EntityId $student, EntityId $pursuit): EntityId;
}
