<?php

declare(strict_types=1);

namespace Domain\Finance\Repository;

use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoryReadBudget;

interface AccountRepository
{
    public function get(AccountId $id, ?HistoryReadBudget $budget = null): StudentAccount;

    public function save(StudentAccount $account, EventContext $context): void;
}
