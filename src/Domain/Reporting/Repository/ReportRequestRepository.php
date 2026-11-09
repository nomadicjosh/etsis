<?php

declare(strict_types=1);

namespace Domain\Reporting\Repository;

use Domain\Reporting\ReportRequest;
use Domain\Shared\ValueObject\EntityId;

interface ReportRequestRepository
{
    public function find(EntityId $id): ?ReportRequest;
    public function lock(EntityId $id): void;
    public function lockRequester(EntityId $actor): void;
    public function add(ReportRequest $request, string $encrypted, ?string $dueOn, string $at): void;
    public function save(ReportRequest $request, int $version, string $at): void;
    /** @return array<string, mixed> */
    public function content(EntityId $id): array;
    /** @return list<array<string, mixed>> */
    public function page(?EntityId $requester, ?string $status, ?EntityId $after, int $limit): array;
    /** @return list<array<string, mixed>> */
    public function history(EntityId $request, int $afterVersion, int $limit): array;
    /** @return array<string, mixed>|null */
    public function operation(EntityId $operation): ?array;
    public function record(
        EntityId $operation,
        EntityId $request,
        EntityId $actor,
        int $version,
        string $hash,
        string $encrypted,
        string $at,
    ): void;
}
