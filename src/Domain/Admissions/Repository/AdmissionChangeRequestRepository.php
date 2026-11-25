<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

use Domain\Admissions\AdmissionChangeRequest;
use Domain\Shared\ValueObject\EntityId;

interface AdmissionChangeRequestRepository
{
    public function find(EntityId $application, EntityId $id): ?AdmissionChangeRequest;
    public function count(EntityId $application): int;
    public function pending(EntityId $application, string $area): bool;
    /** @return list<AdmissionChangeRequest> At most eleven envelopes. */
    public function page(EntityId $application, ?EntityId $owner, bool $finished, ?EntityId $after): array;
    public function save(AdmissionChangeRequest $request, ?int $expectedVersion): void;
}
