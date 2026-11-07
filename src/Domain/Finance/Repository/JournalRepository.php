<?php

declare(strict_types=1);

namespace Domain\Finance\Repository;

use Domain\Finance\Journal;
use Domain\Finance\ValueObject\JournalId;
use Domain\Shared\ValueObject\EntityId;

interface JournalRepository
{
    public function addLedgerAccount(EntityId $id, string $code, string $title, string $currency): void;
    public function ledgerCurrency(EntityId $id): string;
    public function get(JournalId $id): Journal;
    public function save(Journal $journal): void;
}
