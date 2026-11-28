<?php

declare(strict_types=1);

namespace Domain\Finance\Exception;

use Domain\Finance\Enum\AccountingControlIssue;
use DomainException;

final class AccountingControlException extends DomainException
{
    public function __construct(public readonly AccountingControlIssue $issue)
    {
        parent::__construct('An institutional accounting control requires review.');
    }
}
