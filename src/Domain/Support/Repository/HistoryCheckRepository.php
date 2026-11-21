<?php

declare(strict_types=1);

namespace Domain\Support\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Support\HistoryCheckReceipt;
use Domain\Support\HistoryCheckScope;

interface HistoryCheckRepository
{
    /** Resolve authorization routing without replaying a financial account. */
    public function accountStudent(EntityId $account): EntityId;
    public function save(HistoryCheckReceipt $receipt): void;
    /** @return list<HistoryCheckReceipt> At most eleven rows. */
    public function page(HistoryCheckScope $scope, ?EntityId $after): array;
}
