<?php

declare(strict_types=1);

namespace Domain\Lms\Repository;

use Domain\Lms\ValueObject\MoodleAccountRequest;
use Domain\Shared\ValueObject\EntityId;

interface MoodleAccountRequestRepository
{
    /** @phpstan-impure */
    public function forPerson(EntityId $binding, EntityId $person): ?MoodleAccountRequest;
    public function add(
        MoodleAccountRequest $request,
        #[\SensitiveParameter] string $encryptedApproval,
    ): void;
    /** Atomically add a reference-only outbox item in the caller's transaction. */
    public function enqueue(EntityId $request, string $createdAt): void;
    public function state(EntityId $request): string;
}
