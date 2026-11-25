<?php

declare(strict_types=1);

namespace Domain\Finance\Repository;

use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\ValueObject\EntityId;

/** Deployment operators only. No browser/student command, business event or external effect. */
interface AccountProjectionRecovery
{
    public function stage(AccountId $account, int $eventLimit = 500): array;
    public function check(EntityId $stage, int $eventLimit = 500): array;
    public function apply(EntityId $stage, int $eventLimit = 500): array;
}
