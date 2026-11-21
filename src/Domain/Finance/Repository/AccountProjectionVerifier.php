<?php

declare(strict_types=1);

namespace Domain\Finance\Repository;

use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\AccountProjectionCheck;

/** Trusted operator read; browser callers must use the separately authorized support facade. */
interface AccountProjectionVerifier
{
    public function verify(AccountId $account, int $eventLimit = 500): AccountProjectionCheck;
}
