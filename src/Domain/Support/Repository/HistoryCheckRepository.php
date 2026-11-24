<?php

declare(strict_types=1);

namespace Domain\Support\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Support\HistoryCheckReceipt;
use Domain\Support\HistoryCheckScope;
use Domain\Support\Enum\HistoryCheckSubject;

interface HistoryCheckRepository
{
    /** Resolve authorization routing without replaying a financial account. */
    public function accountStudent(EntityId $account): EntityId;
    /** Indexed current authorization routing; no encrypted HR details or event replay. */
    public function employmentSubject(HistoryCheckSubject $kind, EntityId $id): EntityId;
    public function save(HistoryCheckReceipt $receipt): void;
    /** @return list<HistoryCheckReceipt> At most eleven rows. */
    public function page(HistoryCheckScope $scope, ?EntityId $after): array;
}
