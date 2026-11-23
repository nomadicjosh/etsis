<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

use Domain\Shared\ValueObject\EntityId;

interface AdmissionDocumentHoldRepository
{
    /** @return array<string, mixed>|null */
    public function latest(EntityId $person, ?EntityId $application): ?array;

    /** @return array<string, mixed>|null */
    public function receipt(EntityId $operation): ?array;

    /** @return list<array<string, mixed>> */
    public function history(EntityId $person, EntityId $application, ?EntityId $before = null): array;

    /** @param array<string, mixed> $row */
    public function add(array $row): void;

    /** @param array<string, mixed> $values */
    public function release(EntityId $id, array $values): void;
}
