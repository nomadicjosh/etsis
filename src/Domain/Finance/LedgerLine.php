<?php

declare(strict_types=1);

namespace Domain\Finance;

use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class LedgerLine
{
    /** Positive is a debit; negative is a credit. */
    public function __construct(public EntityId $accountId, public Money $amount)
    {
        if ($amount->minor === 0) {
            throw new InvalidArgumentException('A journal line must have a nonzero amount.');
        }
    }
}
