<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use DomainException;
use Domain\Finance\Journal;
use Domain\Finance\LedgerLine;
use Domain\Finance\Repository\JournalRepository;
use Domain\Finance\ValueObject\JournalId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class LedgerService
{
    public function __construct(
        private JournalRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private FinancialCommandPolicy $commands,
        private LedgerControl $control,
    ) {
    }

    public function addAccount(string $code, string $title, string $currency): EntityId
    {
        $actor = $this->access->requirePermission('sis:finance:ledger');
        new Money(0, $currency);
        if (trim($code) === '' || strlen($code) > 80 || trim($title) === '' || mb_strlen($title) > 191) {
            throw new InvalidArgumentException('A ledger account requires a code and title.');
        }
        $id = EntityId::generate();
        $this->transactions->run(function () use ($id, $code, $title, $currency, $actor): void {
            $this->commands->assertActor($actor, 'sis:finance:ledger');
            $this->repository->addLedgerAccount($id, $code, $title, $currency);
            $this->audit->record($actor, 'ledger.account_added', $id);
            $this->commands->assertActor($actor, 'sis:finance:ledger');
        });

        return $id;
    }

    /** @param list<LedgerLine> $lines */
    public function post(
        EntityId $operation,
        string $reference,
        DateTimeImmutable $on,
        array $lines,
        #[\SensitiveParameter] string $reason,
    ): Journal {
        $actor = $this->access->requirePermission('sis:finance:ledger');
        $reason = $this->commands->reason($reason);
        $id = new JournalId($operation->value);
        $candidate = Journal::post($id, $reference, $on, $lines, $actor);
        return $this->transactions->run(function () use ($id, $operation, $candidate, $reason, $actor): Journal {
            $this->control->records->lock();
            try {
                $existing = $this->repository->get($id);
            } catch (RecordNotFoundException) {
                $existing = null;
            }
            $this->commands->assertActor($actor, 'sis:finance:ledger');
            if ($existing !== null) {
                if (
                    $existing->reference() !== $candidate->reference()
                    || $existing->postedOn() !== $candidate->postedOn()
                    || $existing->currency() !== $candidate->currency()
                    || $this->lineFacts($existing) !== $this->lineFacts($candidate)
                ) {
                    throw new ConcurrentModificationException(
                        'This operation already posted a different journal.'
                    );
                }
                return $existing;
            }
            $candidate = Journal::post(
                $id,
                $candidate->reference(),
                new DateTimeImmutable($candidate->postedOn()),
                $candidate->lines(),
                $actor,
                $this->control->accounting($candidate->currency(), $candidate->postedOn())
            );
            foreach ($candidate->lines() as $line) {
                if ($this->repository->ledgerCurrency($line->accountId) !== $line->amount->currency) {
                    throw new DomainException('The ledger account currency does not match.');
                }
            }
            $this->commands->assertActor($actor, 'sis:finance:ledger');
            $this->repository->save($candidate, $this->commands->context($actor, $operation, $reason));
            $this->audit->record($actor, 'ledger.journal_posted', $operation);
            $this->commands->assertActor($actor, 'sis:finance:ledger');
            return $candidate;
        });
    }

    /** @return array{status:string} */
    public function reverse(JournalId $id, int $position, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->access->requirePermission('sis:finance:ledger');
        $reason = $this->commands->reason($reason);
        return $this->transactions->run(function () use ($id, $position, $reason, $actor): array {
            $this->control->records->lock();
            $journal = $this->repository->get($id);
            if (($journal->accounting()['source'] ?? null) !== null) {
                throw new DomainException('Correct the student account instead of reversing its automatic journal.');
            }
            $this->commands->assertActor($actor, 'sis:finance:ledger');
            if ($journal->isReversed()) {
                return ['status' => 'already_recorded'];
            }
            $this->commands->assertPosition($journal->playhead(), $position);
            $journal->reverse($actor);
            $operation = new EntityId((string) $id);
            $this->repository->save($journal, $this->commands->context($actor, $operation, $reason));
            $this->audit->record($actor, 'ledger.journal_reversed', $operation);
            $this->commands->assertActor($actor, 'sis:finance:ledger');
            return ['status' => 'reversed'];
        });
    }

    public function get(JournalId $id): Journal
    {
        $actor = $this->access->requirePermission('sis:finance:ledger');
        $journal = $this->repository->get($id);
        if (($journal->accounting()['source'] ?? null) !== null) {
            $this->commands->assertActor($actor, 'sis:finance:read');
        }
        $this->commands->assertActor($actor, 'sis:finance:ledger');
        return $journal;
    }

    /** @return list<array{account:string, minor:int, currency:string}> */
    private function lineFacts(Journal $journal): array
    {
        return array_map(static fn (LedgerLine $line): array => [
            'account' => $line->accountId->value,
            'minor' => $line->amount->minor,
            'currency' => $line->amount->currency,
        ], $journal->lines());
    }

    /** Current chart labels are reference data; retained journal amounts and identities remain authoritative. */
    public function describeLines(Journal $journal): array
    {
        $actor = $this->access->requirePermission('sis:finance:ledger');
        if (($journal->accounting()['source'] ?? null) !== null) {
            $this->commands->assertActor($actor, 'sis:finance:read');
        }
        $accounts = [];
        $lines = [];
        foreach ($journal->lines() as $line) {
            $accounts[$line->accountId->value] ??= $this->control->records->account($line->accountId->value);
            $account = $accounts[$line->accountId->value];
            if ($account['currency'] !== $line->amount->currency) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            $lines[] = ['account_id' => $line->accountId->value, 'account_code' => $account['code'],
                'account_title' => $account['title'], 'amount_minor' => $line->amount->minor];
        }
        $this->commands->assertActor($actor, 'sis:finance:ledger');
        if (($journal->accounting()['source'] ?? null) !== null) {
            $this->commands->assertActor($actor, 'sis:finance:read');
        }
        return $lines;
    }
}
