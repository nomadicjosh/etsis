<?php

declare(strict_types=1);

namespace Domain\User\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\User\ValueObject\LoginAccountState;

interface LoginAccountStateReader
{
    /** @phpstan-impure */
    public function current(EntityId $user): ?LoginAccountState;
}
