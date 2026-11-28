<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Finance\Journal;
use Domain\Finance\Repository\JournalRepository;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\JournalId;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\Service\Clock;

/** Future accepted student-account events and GL journals commit in the same unit of work. */
final readonly class StudentLedgerPosting
{
    public function __construct(
        private LedgerControl $control,
        private JournalRepository $journals,
        private Clock $clock,
    ) {
    }

    public function lock(): void
    {
        $this->control->records->lock();
    }

    public function advance(
        StudentAccount $account,
        array $events,
        EventContext $context,
    ): void {
        $this->control->records->lock();
        $book = $this->control->book($account->balance()->currency);
        if ($book === null) {
            return;
        }
        $date = $this->clock->now()
            ->setTimezone(new DateTimeZone($book['definition']['timezone']))
            ->format('Y-m-d');
        foreach ($events as $event) {
            $mapped = $this->control->sourceLines($event, $book["definition"]);
            if ($mapped === null) {
                continue;
            }
            $source = [
                "account_id" => (string) $account->aggregateId(),
                "event_id" => (string) $event->eventId(),
                "entry_operation" => $mapped["entry_operation"],
                "entry_basis" => $mapped["entry_basis"],
            ];
            $accounting = $this->control->accounting(
                $account->balance()->currency,
                $date,
                $source,
            );
            $journal = Journal::post(
                new JournalId(),
                "SA." . $event->eventId(),
                new DateTimeImmutable($date),
                $mapped["lines"],
                $context->actor,
                $accounting,
            );
            $this->journals->save($journal, $context);
        }
    }
}
