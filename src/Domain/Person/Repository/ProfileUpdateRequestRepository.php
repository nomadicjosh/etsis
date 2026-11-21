<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Person\ProfileUpdateRequest;
use Domain\Shared\ValueObject\EntityId;

interface ProfileUpdateRequestRepository
{
    public function find(EntityId $person, EntityId $id): ?ProfileUpdateRequest;
    public function pending(EntityId $person, string $area): bool;
    /** @return list<ProfileUpdateRequest> */
    public function page(EntityId $person, ?EntityId $owner, bool $finished, ?EntityId $after): array;
    public function save(ProfileUpdateRequest $request, ?int $expectedVersion): void;
}
