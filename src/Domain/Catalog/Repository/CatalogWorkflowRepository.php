<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Catalog\ValueObject\PrerequisiteGroups;

interface CatalogWorkflowRepository
{
    /** @return list<array{id: string, code: string, title: string, active: bool}> */
    public function prerequisites(EntityId $course): array;
    public function prerequisiteGroups(EntityId $course): ?PrerequisiteGroups;
    public function savePrerequisiteGroups(EntityId $course, PrerequisiteGroups $groups): void;
    /** @param list<string> $ids
     * @return list<array{id:string,code:string,title:string,active:bool}> */
    public function prerequisiteLabels(array $ids): array;
    /** @return array<string, mixed>|null */
    public function operation(EntityId $id): ?array;
    /** @return list<array<string, mixed>> */
    public function offeringPage(?EntityId $after): array;
    public function lockOperation(EntityId $id): void;
    public function record(EntityId $id, EntityId $actor, string $kind, string $encrypted, string $on): void;
    public function published(EntityId $id, string $on, string $encrypted): void;
    /** @return array{id: string, name: string, person_id: string} */
    public function faculty(EntityId $user): array;
    public function codeExists(string $kind, string $code, ?EntityId $term = null): bool;
}
