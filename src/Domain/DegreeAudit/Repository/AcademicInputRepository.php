<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Repository;

use Domain\DegreeAudit\ValueObject\AcademicInputInventory;
use Domain\Shared\ValueObject\EntityId;

interface AcademicInputRepository
{
    public function inventory(EntityId $student): AcademicInputInventory;
    /** Internal selected routing/source and bounded projection boundary; never serialize this data.
     * @return array{student_id:string,source:array<string,mixed>,boundary:array<string,mixed>}|null
     */
    public function selected(EntityId $credit): ?array;
}
