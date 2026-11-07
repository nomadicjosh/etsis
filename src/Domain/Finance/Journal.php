<?php

declare(strict_types=1);

namespace Domain\Finance;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\Metadata;
use DateTimeImmutable;
use Domain\Finance\Event\JournalWasPosted;
use Domain\Finance\Event\JournalWasReversed;
use Domain\Finance\ValueObject\JournalId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use DomainException;

final class Journal extends EventSourcedAggregate
{
    private string $reference;
    private string $currency;
    private string $postedOn;
    /** @var list<LedgerLine> */
    private array $lines = [];
    private bool $reversed = false;

    /** @param list<LedgerLine> $lines */
    public static function post(
        JournalId $id,
        string $reference,
        DateTimeImmutable $on,
        array $lines,
        EntityId $actor
    ): self {
        if (
            preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,79}$/D', $reference) !== 1 || count($lines) < 2
            || count($lines) > 500
        ) {
            throw new DomainException('A journal requires a reference and between two and 500 lines.');
        }
        $currency = $lines[0]->amount->currency;
        $debits = $credits = 0;
        $accounts = [];
        $payload = [];
        foreach ($lines as $line) {
            if ($currency !== $line->amount->currency) {
                throw new DomainException('All journal lines must use the same currency.');
            }
            $debits += max(0, $line->amount->minor);
            $credits += max(0, -$line->amount->minor);
            $accounts[$line->accountId->value] = true;
            $payload[] = ['account_id' => $line->accountId->value, 'minor' => $line->amount->minor];
        }
        if ($debits !== $credits || $debits > Money::MAX_MINOR || count($accounts) < 2) {
            throw new DomainException('A journal must balance across distinct accounts within the amount limit.');
        }
        $journal = self::root($id);
        $journal->recordThat(JournalWasPosted::occur(
            $id,
            ['reference' => $reference,
            'posted_on' => $on->format('Y-m-d'),
            'currency' => $currency, 'lines' => $payload, 'actor_id' => $actor->value],
            [Metadata::AGGREGATE_TYPE => 'journal']
        ));

        return $journal;
    }

    public function reverse(EntityId $actor): void
    {
        if (!$this->reversed) {
            $this->recordThat(JournalWasReversed::occur(
                $this->aggregateId(),
                ['actor_id' => $actor->value],
                [Metadata::AGGREGATE_TYPE => 'journal']
            ));
        }
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function postedOn(): string
    {
        return $this->postedOn;
    }

    public function isReversed(): bool
    {
        return $this->reversed;
    }

    /** @return list<LedgerLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    protected function whenJournalWasPosted(JournalWasPosted $event): void
    {
        $p = $event->payload();
        $this->reference = $p['reference'];
        $this->currency = $p['currency'];
        $this->postedOn = $p['posted_on'];
        $this->lines = array_map(fn (array $line): LedgerLine => new LedgerLine(
            new EntityId($line['account_id']),
            new Money($line['minor'], $this->currency)
        ), array_values($p['lines']));
    }

    protected function whenJournalWasReversed(JournalWasReversed $event): void
    {
        $this->reversed = true;
    }
}
