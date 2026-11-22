<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Reference labels only; no external courses, equivalencies or student awards. */
interface TransferInstitutionDirectory
{
    /** @return list<array{id:string,code:string,name:string}> */
    public function page(string $search, ?EntityId $after = null): array;
    /** @return array{id:string,code:string,name:string} */
    public function get(EntityId $id): array;
    public function lock(EntityId $id): void;
}
