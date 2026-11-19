<?php

declare(strict_types=1);

namespace Domain\Reporting\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Operational, expiring exports. Jobs contain references only; result chunks remain encrypted. */
interface ReportExportRepository
{
    /** @return array<string,mixed>|null */
    public function get(EntityId $id): ?array;
    /** @return array<string,mixed>|null */
    public function latest(EntityId $report, EntityId $actor): ?array;
    /** @param array<string,scalar|null> $job */
    public function add(array $job): void;
    public function lock(EntityId $id): void;
    /** @param array<string,scalar|null> $values
     * @param array<string,scalar|null> $expected */
    public function update(EntityId $id, array $values, array $expected = []): bool;
    /** @return list<string> */
    public function pending(string $now, int $limit): array;
    /** @param list<string> $chunks */
    public function saveChunks(EntityId $id, #[\SensitiveParameter] array $chunks): void;
    public function chunk(EntityId $id, int $number): ?string;
    public function clear(EntityId $id): void;
    /** @return list<string> */
    public function expired(string $now, int $limit): array;
    public function remove(EntityId $id): void;
}
