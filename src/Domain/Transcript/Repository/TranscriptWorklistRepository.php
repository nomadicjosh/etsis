<?php

declare(strict_types=1);

namespace Domain\Transcript\Repository;

use Domain\Shared\ValueObject\EntityId;

interface TranscriptWorklistRepository
{
    /** Ordinary minimized routing read, including inside a caller-owned transaction. */
    public function request(EntityId $request): array;
    public function assignment(EntityId $request): ?array;
    public function save(
        EntityId $request,
        int $version,
        ?EntityId $assignee,
        ?EntityId $person,
        string $at,
    ): void;
    public function page(
        EntityId $actor,
        EntityId $person,
        string $scope,
        string $status,
        string $search,
        ?EntityId $after = null,
    ): array;
}
