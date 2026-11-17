<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

use Domain\Shared\ValueObject\EntityId;

interface AdmissionDocumentRepository
{
    /** @return array<string,mixed>|null */
    public function find(EntityId $id): ?array;
    /** @return list<array<string,mixed>> */
    public function forApplication(EntityId $application): array;
    public function retainedCount(EntityId $person): int;
    /** @param array<string,mixed> $row */
    public function add(array $row): void;
    public function lock(EntityId $id): void;
    /** @param array<string,mixed> $values */
    public function change(EntityId $id, int $version, array $values): void;
    /** @return list<array<string,mixed>> */
    public function scanCandidates(string $at, string $stale, int $limit, bool $scan = true): array;
}
