<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use DateTimeImmutable;
use DomainException;
use Domain\Finance\Journal;
use Domain\Finance\LedgerLine;
use Domain\Finance\Repository\JournalRepository;
use Domain\Finance\ValueObject\JournalId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class LedgerService
{
    public function __construct(private JournalRepository $repository, private StudentAccess $access)
    {
    }

    public function addAccount(string $code, string $title, string $currency): EntityId
    {
        $this->access->requirePermission('sis:finance:ledger');
        new Money(0, $currency);
        if (trim($code) === '' || strlen($code) > 80 || trim($title) === '' || mb_strlen($title) > 191) {
            throw new InvalidArgumentException('A ledger account requires a code and title.');
        }
        $id = EntityId::generate();
        $this->repository->addLedgerAccount($id, $code, $title, $currency);

        return $id;
    }

    /** @param list<LedgerLine> $lines */
    public function post(string $reference, DateTimeImmutable $on, array $lines): Journal
    {
        $actor = $this->access->requirePermission('sis:finance:ledger');
        foreach ($lines as $line) {
            if ($this->repository->ledgerCurrency($line->accountId) !== $line->amount->currency) {
                throw new DomainException('The ledger account currency does not match.');
            }
        }
        $journal = Journal::post(new JournalId(), $reference, $on, $lines, $actor);
        $this->repository->save($journal);

        return $journal;
    }

    public function reverse(JournalId $id): void
    {
        $actor = $this->access->requirePermission('sis:finance:ledger');
        $journal = $this->repository->get($id);
        $journal->reverse($actor);
        $this->repository->save($journal);
    }

    public function get(JournalId $id): Journal
    {
        $this->access->requirePermission('sis:finance:ledger');

        return $this->repository->get($id);
    }
}
