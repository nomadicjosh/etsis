<?php

declare(strict_types=1);

namespace Domain\Reporting\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Protected report artifacts are ordinary retained data, never another academic event store. */
interface ReportLifecycleRepository
{
    /** @return array<string,mixed>|null */
    public function get(EntityId $report): ?array;
    /** Caller holds the report lock and verifies its expected definition version.
     * @param array<string,scalar|null> $state
     */
    public function save(EntityId $report, array $state): void;
    /** @param list<string> $encryptedPages */
    public function savePages(EntityId $report, #[\SensitiveParameter] array $encryptedPages): void;
    public function page(EntityId $report, int $page): ?string;
    /** @return list<string> */
    public function expired(string $cutoff, int $limit): array;
    /** Erase report contents while retaining a minimal parent reference for linked office cases. */
    public function remove(EntityId $report, string $on): void;
}
