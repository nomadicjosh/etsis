<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Bounded routing rows only. Ledgers verify the academic facts before use. */
interface AcademicWorkRepository
{
    /** @return list<array{id:string, credit_type:string, source_id:string, term_code:?string}> */
    public function page(EntityId $student, ?EntityId $after): array;
}
