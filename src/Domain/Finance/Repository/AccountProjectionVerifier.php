<?php

declare(strict_types=1);

namespace Domain\Finance\Repository;

use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\AccountProjectionCheck;

/** Trusted operator read, not a browser or financial command. */
interface AccountProjectionVerifier
{
    public function verify(AccountId $account, int $eventLimit = 500): AccountProjectionCheck;
}
