<?php

declare(strict_types=1);

namespace Domain\Lms\Repository;

use Domain\Shared\ValueObject\EntityId;

interface MoodleProvisioningQueue
{
    /** @return array{id:string,request_id:string,version:int,attempts:int}|null */
    public function claim(int $now): ?array;
    /** @return array<string,mixed> Private encrypted approval; never include it in queue/output. */
    public function approval(EntityId $request): array;
    public function finish(EntityId $job, int $version, string $state, string $issue): void;
}
