<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Repository;

use Domain\DegreeAudit\ValueObject\AcademicInputInventory;
use Domain\Shared\ValueObject\EntityId;

interface AcademicInputRepository
{
    public function inventory(EntityId $student): AcademicInputInventory;
}
