<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Shared\ValueObject\EntityId;

interface SpecializationPolicyRepository
{
    public const string INSTITUTION_ID = '00000000000000000000000001';
    public function lockInstitution(): void;
    /** Null means the institutional boundary, not a program fallback.
     * @return array{revision:int,minimum:int,maximum:?int,default_maximum:?int,component_sharing?:string} */
    public function get(?EntityId $program): array;
    /** Caller holds institution then program locks, as applicable.
     * @param array{revision:int,minimum:int,maximum:?int,default_maximum:?int,component_sharing?:string} $values */
    public function save(?EntityId $program, array $values): void;
}
