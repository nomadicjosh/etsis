<?php

declare(strict_types=1);

namespace Domain\Finance\Repository;

interface LedgerControlRepository
{
    public function lock(): void;
    public function book(string $currency): ?array;
    public function period(string $id): ?array;
    public function account(string $id): array;
    public function renameReceivable(array $account): void;
    public function hasEarlierPostings(string $currency): bool;
    public function saveBook(
        string $id,
        string $currency,
        int $position,
        int $expected,
    ): void;
    public function savePeriod(
        string $id,
        array $definition,
        int $position,
        int $expected,
    ): void;
    public function addMovement(array $row): void;
    public function movements(string $period, int $after, int $through): array;
    public function sourceJournal(string $account, string $operation): ?string;
}
