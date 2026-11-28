<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use Brick\Math\BigInteger;
use Codefy\Domain\EventSourcing\DomainEvent;
use Codefy\Domain\Metadata;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Enum\AccountingControlIssue;
use Domain\Finance\Exception\AccountingControlException;
use Domain\Finance\Event\AccountChargeWasCredited;
use Domain\Finance\Event\AccountEntryWasCorrected;
use Domain\Finance\Event\AccountEntryWasPosted;
use Domain\Finance\Event\AccountEntryWasReversed;
use Domain\Finance\Event\JournalWasPosted;
use Domain\Finance\Event\JournalWasReversed;
use Domain\Finance\Event\LedgerBookWasPublished;
use Domain\Finance\Event\LedgerPeriodWasRecorded;
use Domain\Finance\Journal;
use Domain\Finance\StudentAccount;
use Domain\Finance\LedgerLine;
use Domain\Finance\Repository\LedgerControlRepository;
use Domain\Finance\ValueObject\JournalId;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\LedgerBookId;
use Domain\Finance\ValueObject\LedgerPeriodId;
use Domain\Finance\ValueObject\LedgerSchema;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventEvidence;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use DomainException;

/** Internal finance coordinator. Callers authorize commands and own the shared transaction. */
final readonly class LedgerControl
{
    public function __construct(
        public LedgerControlRepository $records,
        private HistoricalEventStore $history,
        private HistoricalEventEvidence $evidence,
    ) {
    }

    public function book(string $currency): ?array
    {
        $id = LedgerBookId::forCurrency($currency);
        $row = $this->records->book($currency);
        $stored = $this->history->latest(
            new StreamReference($id, "ledger_book"),
        );
        if ($stored === null && $row === null) {
            return null;
        }
        if (
            $stored === null ||
            $row === null ||
            $row["id"] !== (string) $id ||
            (int) $row["position"] !== $stored->event->playhead() ||
            !($stored->event instanceof LedgerBookWasPublished)
        ) {
            throw new CorruptHistoryException();
        }
        $this->validateDecision($stored);
        $definition = $stored->event->payload()["definition"];
        if ($definition["currency"] !== $currency) {
            throw new CorruptHistoryException();
        }
        return [
            "id" => (string) $id,
            "position" => $stored->event->playhead(),
            "event_id" => (string) $stored->event->eventId(),
            "definition" => $definition,
        ];
    }

    public function period(string $id): array
    {
        $stored = $this->history->latest(
            new StreamReference(new LedgerPeriodId($id), "ledger_period"),
        );
        $row = $this->records->period($id);
        if (
            $stored === null ||
            $row === null ||
            !($stored->event instanceof LedgerPeriodWasRecorded) ||
            (int) $row["position"] !== $stored->event->playhead()
        ) {
            throw new CorruptHistoryException();
        }
        $this->validateDecision($stored);
        $definition = $stored->event->payload()["definition"];
        $book =
        $this->book($definition["currency"]) ??
        throw new CorruptHistoryException();
        $reference = array_find(
            $book["definition"]["periods"],
            static fn(array $p): bool => $p["id"] === $id,
        );
        if (
            $reference === null ||
            $definition["book_id"] !== $book["id"] ||
            $row["book_id"] !== $definition["book_id"] ||
            $row["starts_on"] !== $definition["starts_on"] ||
            $row["ends_on"] !== $definition["ends_on"] ||
            $reference["starts_on"] !== $definition["starts_on"] ||
            $reference["ends_on"] !== $definition["ends_on"]
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "id" => $id,
            "position" => $stored->event->playhead(),
            "definition" => $definition,
        ];
    }

    public function saveBook(
        string $id,
        array $definition,
        int $expected,
        EventContext $context,
        EntityId $operation,
    ): void {
        LedgerSchema::book($definition);
        $stream = new StreamReference(new LedgerBookId($id), "ledger_book");
        $event = LedgerBookWasPublished::occur(
            $stream->aggregateId,
            [
                "definition" => $definition,
                "operation_id" => $operation->value,
                "intent" => LedgerSchema::hash([$definition, $context->reason]),
            ],
            [Metadata::AGGREGATE_TYPE => $stream->type],
        )->withPlayhead($expected + 1);
        $this->history->append($stream, $operation, $expected, $context, [
            $event,
        ]);
        $this->records->saveBook(
            $id,
            $definition["currency"],
            $expected + 1,
            $expected,
        );
    }

    public function savePeriod(
        string $id,
        array $definition,
        int $expected,
        string $action,
        ?string $movement,
        EventContext $context,
        EntityId $operation,
    ): void {
        LedgerSchema::period($definition);
        $stream = new StreamReference(new LedgerPeriodId($id), "ledger_period");
        $event = LedgerPeriodWasRecorded::occur(
            $stream->aggregateId,
            [
                "definition" => $definition,
                "operation_id" => $operation->value,
                "intent" => LedgerSchema::hash([
                    $definition,
                    $action,
                    $movement,
                    $context->reason,
                ]),
                "action" => $action,
                "movement_event_id" => $movement,
            ],
            [Metadata::AGGREGATE_TYPE => $stream->type],
        )->withPlayhead($expected + 1);
        $this->history->append($stream, $operation, $expected, $context, [
            $event,
        ]);
        $this->records->savePeriod($id, $definition, $expected + 1, $expected);
    }

    public function validateDecision(StoredEvent $stored): void
    {
        $p = $stored->event->payload();
        $expected =
        $stored->event instanceof LedgerBookWasPublished
        ? [$p["definition"], $stored->context->reason]
        : [
                    $p["definition"],
                    $p["action"],
                    $p["movement_event_id"],
                    $stored->context->reason,
                ];
        if (
            $stored->context->origin !== "command" ||
            $stored->context->actor === null ||
            $stored->context->reason === null ||
            !hash_equals($p["intent"], LedgerSchema::hash($expected))
        ) {
            throw new CorruptHistoryException();
        }
    }

    public function accounting(
        string $currency,
        string $date,
        ?array $source = null,
    ): ?array {
        $this->records->lock();
        $book = $this->book($currency);
        if ($book === null) {
            return null;
        }
        $match = array_find(
            $book["definition"]["periods"],
            static fn(array $p): bool => $p["starts_on"] <= $date &&
                $date <= $p["ends_on"],
        );
        if ($match === null) {
            throw new AccountingControlException(AccountingControlIssue::MissingPeriod);
        }
        $period = $this->period($match["id"]);
        if ($period["definition"]["closed"]) {
            throw new AccountingControlException(AccountingControlIssue::ClosedPeriod);
        }
        return [
            "book_id" => $book["id"],
            "period_id" => $period["id"],
            "configuration_event_id" => $book["event_id"],
            "source" => $source,
        ];
    }

    /** A current entry's original accepted counterpart, never today's replacement mapping. */
    public function sourceBasis(
        string $account,
        string $operation,
        string $currency,
    ): array {
        $journal =
        $this->records->sourceJournal($account, $operation) ??
        throw new CorruptHistoryException();
        $stored = $this->history->latest(
            new StreamReference(new JournalId($journal), "journal"),
        );
        $source =
        $stored?->event->metadata()["ledger_accounting"]["source"] ?? null;
        if (
            $stored === null ||
            !($stored->event instanceof JournalWasPosted) ||
            $stored->event->payload()["currency"] !== $currency ||
            $source === null ||
            $source["account_id"] !== $account ||
            $source["entry_operation"] !== $operation ||
            $source["entry_basis"] === null
        ) {
            throw new CorruptHistoryException();
        }
        return $source["entry_basis"];
    }

    /** @return array{lines:list<LedgerLine>,entry_operation:?string,entry_basis:?array}|null */
    public function sourceLines(
        DomainEvent $event,
        array $definition,
        ?int $started = null,
    ): ?array {
        $p = $event->payload();
        $account = (string) $event->aggregateId();
        $currency = $definition["currency"];
        $receivable = $definition["receivable"]["id"];
        $parts = [];
        $basis = null;
        $operation = null;
        if ($event instanceof AccountEntryWasPosted) {
            if ($p["currency"] !== $currency) {
                throw new CorruptHistoryException();
            }
            $target = match ($p["type"]) {
                "charge" => $definition["charge_sources"][
                $event->metadata()["charge_source"] ?? ""
                ] ?? $definition["charge"],
                "payment" => $definition["payment_methods"][
                $event->metadata()["payment_details"]["method"] ?? ""
                ] ?? $definition["payment"],
                "refund" => $definition["refund"],
                default => throw new CorruptHistoryException(),
            };
            $basis = [
                "type" => $p["type"],
                "minor" => $p["minor"],
                "counterpart_id" => $target["id"],
            ];
            $parts[] = [$basis, 1];
            $operation = $p["operation_id"];
        } elseif (
            $event instanceof AccountChargeWasCredited ||
            $event instanceof AccountEntryWasCorrected
        ) {
            $old = $this->sourceBasis(
                $account,
                $p["source_operation_id"],
                $currency,
            );
            if (
                $p["currency"] !== $currency ||
                ($event instanceof AccountChargeWasCredited &&
                    $old["type"] !== "charge")
            ) {
                throw new CorruptHistoryException();
            }
            $basis = [
                "type" =>
                    $event instanceof AccountChargeWasCredited
                        ? "charge_credit"
                        : $old["type"],
                "minor" => $p["minor"],
                "counterpart_id" => $old["counterpart_id"],
            ];
            if ($event instanceof AccountEntryWasCorrected) {
                $parts[] = [$old, -1];
            }
            $parts[] = [$basis, 1];
            $operation = $p["operation_id"];
        } elseif ($event instanceof AccountEntryWasReversed) {
            $ids = [
                $p["operation_id"],
                ...$event->metadata()["reversed_credit_ids"] ?? [],
            ];
            if (
                count($ids) > 2000 ||
                count(array_unique($ids)) !== count($ids)
            ) {
                throw new CorruptHistoryException();
            }
            $original = $this->sourceBasis(
                $account,
                $p["operation_id"],
                $currency,
            );
            $parts[] = [$original, -1];
            if (count($ids) > 1) {
                $started ??= hrtime(true);
                $remaining = max(
                    1,
                    3000 - (int) ((hrtime(true) - $started) / 1_000_000),
                );
                $budget = new HistoryReadBudget(
                    maximumEvents: 2000,
                    maximumBytes: 8388608,
                    maximumMilliseconds: $remaining,
                );
                $events = [];
                $cursor = null;
                do {
                    $page = $budget->page(
                        $this->history,
                        new StreamReference(
                            new AccountId($account),
                            "student_account",
                        ),
                        $cursor,
                    );
                    foreach ($page->events as $stored) {
                        if ($stored->event->playhead() > $event->playhead()) {
                            break;
                        }
                        $events[] = $stored->event;
                    }
                    $cursor = $page->nextCursor;
                } while (
                    $cursor !== null &&
                    count($events) < $event->playhead()
                );
                if (
                    count($events) !== $event->playhead() ||
                    (string) $events[array_key_last($events)]->eventId() !==
                        (string) $event->eventId()
                ) {
                    throw new CorruptHistoryException();
                }
                $state = StudentAccount::reconstituteFromEventStream(
                    new \Codefy\Domain\EventSourcing\EventStream(
                        new AccountId($account),
                        $events,
                    ),
                );
                $credit = 0;
                foreach (array_slice($ids, 1) as $id) {
                    $entry =
                    $state->entry(new EntityId($id)) ??
                    throw new CorruptHistoryException();
                    if (
                        $entry["type"] !== "charge_credit" ||
                        $entry["source_operation_id"] !== $p["operation_id"]
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $credit += $entry["minor"];
                }
                $parts[] = [
                    [
                        "type" => "charge_credit",
                        "minor" => $credit,
                        "counterpart_id" => $original["counterpart_id"],
                    ],
                    -1,
                ];
            }
        } else {
            return null;
        }
        $lines = [];
        foreach ($parts as [$part, $direction]) {
            $amount =
            EntryType::from($part["type"])->balanceSign() *
            $part["minor"] *
            $direction;
            $lines[] = new LedgerLine(
                new EntityId($receivable),
                new Money($amount, $currency),
            );
            $lines[] = new LedgerLine(
                new EntityId($part["counterpart_id"]),
                new Money(-$amount, $currency),
            );
        }
        return [
            "lines" => $lines,
            "entry_operation" => $operation,
            "entry_basis" => $basis,
        ];
    }

    public function journalMovement(
        Journal $journal,
        DomainEvent $event,
        EventContext $context,
    ): void {
        $this->records->lock();
        $book = $this->book($journal->currency());
        if ($book === null) {
            return;
        }
        $accounting =
        $journal->accounting() ?? throw new CorruptHistoryException();
        $period = $this->period($accounting["period_id"]);
        if (
            $period["definition"]["closed"] ||
            $accounting["book_id"] !== $book["id"] ||
            $journal->postedOn() < $period["definition"]["starts_on"] ||
            $journal->postedOn() > $period["definition"]["ends_on"]
        ) {
            throw new DomainException(
                "This journal cannot change the selected accounting period.",
            );
        }
        $stored = $this->evidence->recordedEvent(
            new EntityId((string) $event->eventId()),
        );
        $net = $this->verifyMovement(
            $journal,
            $stored,
            $book["definition"]["receivable"]["id"],
        );
        $d = $period["definition"];
        $d["movement_count"]++;
        $d["digest"] = LedgerSchema::movement(
            $d["digest"],
            $d["movement_count"],
            (string) $event->eventId(),
        );
        $d["ar_net"] = (string) BigInteger::of($d["ar_net"])->plus($net);
        $this->savePeriod(
            $period["id"],
            $d,
            $period["position"],
            "movement",
            (string) $event->eventId(),
            $context,
            EntityId::generate(),
        );
        $source =
        $event instanceof JournalWasPosted ? $accounting["source"] : null;
        $this->records->addMovement([
            "id" => EntityId::generate()->value,
            "period_id" => $period["id"],
            "journal_id" => (string) $journal->aggregateId(),
            "event_id" => (string) $event->eventId(),
            "ordinal" => $d["movement_count"],
            "account_id" => $source["account_id"] ?? null,
            "entry_operation" => $source["entry_operation"] ?? null,
        ]);
    }

    public function verifyMovement(
        Journal $journal,
        StoredEvent $stored,
        string $receivable,
        ?int $started = null,
    ): int {
        $event = $stored->event;
        $accounting =
        $journal->accounting() ?? throw new CorruptHistoryException();
        if (
            (string) $event->aggregateId() !==
                (string) $journal->aggregateId() ||
            !(
                $event instanceof JournalWasPosted ||
                $event instanceof JournalWasReversed
            )
        ) {
            throw new CorruptHistoryException();
        }
        $net = 0;
        foreach ($journal->lines() as $line) {
            if ($line->accountId->value === $receivable) {
                $net += $line->amount->minor;
            }
        }
        $source = $accounting["source"];
        if ($source === null) {
            if (
                $net !== 0 ||
                array_any(
                    $journal->lines(),
                    static fn(LedgerLine $l): bool => $l->accountId->value ===
                        $receivable,
                )
            ) {
                throw new AccountingControlException(AccountingControlIssue::ManualReceivable);
            }
            return 0;
        }
        if (!($event instanceof JournalWasPosted)) {
            throw new DomainException(
                "Correct the student account instead of reversing its automatic journal.",
            );
        }
        $configuration = $this->evidence->recordedEvent(
            new EntityId($accounting["configuration_event_id"]),
        );
        $sourceEvent = $this->evidence->recordedEvent(
            new EntityId($source["event_id"]),
        );
        $definition = $configuration->event->payload()["definition"] ?? null;
        if (
            !($configuration->event instanceof LedgerBookWasPublished) ||
            (string) $configuration->event->aggregateId() !==
                $accounting["book_id"] ||
            (string) $sourceEvent->event->aggregateId() !==
                $source["account_id"] ||
            $stored->context->describe() !==
                $sourceEvent->context->describe() ||
            $sourceEvent->context->effectiveAt === null
        ) {
            throw new CorruptHistoryException();
        }
        $this->validateDecision($configuration);
        $periodReference = array_find($definition['periods'], static fn (array $p): bool =>
            $p['id'] === $accounting['period_id']);
        if (
            $periodReference === null || $journal->currency() !== $definition['currency'] ||
            $journal->postedOn() < $periodReference['starts_on'] ||
            $journal->postedOn() > $periodReference['ends_on']
        ) {
            throw new CorruptHistoryException();
        }
        $expected =
        $this->sourceLines($sourceEvent->event, $definition, $started) ??
        throw new CorruptHistoryException();
        $facts = static fn(array $lines): array => array_map(
            static fn(LedgerLine $l): array => [
                $l->accountId->value,
                $l->amount->minor,
                $l->amount->currency,
            ],
            $lines,
        );
        if (
            LedgerSchema::hash($expected["entry_basis"]) !==
                LedgerSchema::hash($source["entry_basis"]) ||
            $expected["entry_operation"] !== $source["entry_operation"] ||
            $facts($expected["lines"]) !== $facts($journal->lines())
        ) {
            throw new CorruptHistoryException();
        }
        return $net;
    }
}
