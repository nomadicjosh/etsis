<?php

declare(strict_types=1);

namespace Domain\User\Repository;

use Domain\User\LoginSession;
use Domain\Shared\ValueObject\EntityId;

interface LoginSessionRepository
{
    /** @phpstan-impure */
    public function forCookie(#[\SensitiveParameter] string $hash): ?LoginSession;
    /** @phpstan-impure */
    public function byId(EntityId $id): ?LoginSession;
    public function add(LoginSession $session): void;
    /** Retain the first revocation time; never reactivate or extend the original expiry. */
    public function revoke(EntityId $id, int $at): void;
}
