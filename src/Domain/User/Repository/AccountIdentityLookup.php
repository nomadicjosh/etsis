<?php

declare(strict_types=1);

namespace Domain\User\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Narrow canonical-account lookup for deployment bootstrap; never expose as a public search. */
interface AccountIdentityLookup
{
    public function forEmail(#[\SensitiveParameter] string $email): ?EntityId;
}
