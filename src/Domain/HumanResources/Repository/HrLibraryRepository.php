<?php

declare(strict_types=1);

namespace Domain\HumanResources\Repository;

use Domain\HumanResources\Enum\HrLibraryKind;
use Domain\HumanResources\JobDefinition;
use Domain\HumanResources\PayGrade;
use Domain\Shared\ValueObject\EntityId;

interface HrLibraryRepository
{
    /** @return array{value:PayGrade|JobDefinition,version:int}|null */
    public function find(HrLibraryKind $kind, EntityId $id): ?array;
    /** @return list<array{value:PayGrade|JobDefinition,version:int}> */
    public function page(HrLibraryKind $kind, string $search, ?EntityId $after): array;
    public function lock(HrLibraryKind $kind, EntityId $id): void;
    /** Null is required explicitly for creation. Other values are the expected current revision. */
    public function save(PayGrade|JobDefinition $value, ?int $expectedVersion): void;
    public function conflicts(PayGrade $grade): bool;
}
