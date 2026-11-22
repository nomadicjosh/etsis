<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use EventStoreFixture;
use SisFixture;
use Codefy\Domain\EventSourcing\EventStream;
use Codefy\Domain\Metadata;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Projection\SqlAccountBalanceProjection;
use Infrastructure\Projection\SqlAccountEntryProjection;
use Infrastructure\Reporting\SqlRelationalReportSource;

/** Fictional event-time provider: actual commands, protected journal and normal projections. */
final readonly class HistoricalFinancialFixture
{
    public SisFixture $sis;
    public SqlHistoricalEventStore $history;

    public function __construct()
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->sis = new SisFixture();
        $this->history = new SqlHistoricalEventStore(
            $this->sis->sql,
            $this->sis->transactions,
            $this->sis->clock,
            new ProtectedEventCodec(
                EventStoreFixture::config($this->sis->connection->queryBuilder()),
            ),
            new HistoricalSchemaRegistry(),
        );
    }
    public function reports(): RelationalReportService
    {
        $s = $this->sis;
        return new RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            new SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles(),
        );
    }
    public function account(string $currency = "USD"): StudentAccount
    {
        return StudentAccount::open(
            new AccountId(),
            $this->sis->student()->id,
            $this->sis->term,
            $currency,
        );
    }
    public function save(StudentAccount &$account, string $utc): void
    {
        $this->sis->clock->time = new DateTimeImmutable($utc);
        $stream = new StreamReference($account->aggregateId(), "student_account");
        $prior = $this->history->page($stream);
        $events = [];
        foreach ($account->getRecordedEvents() as $event) {
            $events[] = $event
            ::occur(
                $event->aggregateId(),
                $event->payload(),
                array_replace($event->metadata(), [
                        Metadata::RECORDED_AT => $this->sis->clock->now(),
                    ]),
            )
                ->withPlayhead($event->playhead());
        }
        $all = [...array_map(static fn($stored) => $stored->event, $prior->events), ...$events];
        $next = StudentAccount::reconstituteFromEventStream(
            new EventStream($account->aggregateId(), $all),
        );
        $s = $this->sis;
        $s->transactions->run(function () use ($s, $next, $stream, $events, $prior): void {
            if ($prior->throughVersion === 0) {
                $s->sql->insert("sis_student_accounts", [
                    "id" => (string) $next->aggregateId(),
                    "student_id" => $next->studentId()->value,
                    "term_id" => $next->termId()->value,
                    "currency" => $next->balance()->currency,
                ]);
            }
            foreach ($events as $event) {
                $this->history->append(
                    $stream,
                    new EntityId((string) $event->eventId()),
                    $event->playhead() - 1,
                    new EventContext(
                        $s->access->actor,
                        $s->clock->now(),
                        EntityId::generate(),
                        reason: "Fictional account command",
                    ),
                    [$event],
                );
            }
            new SqlAccountBalanceProjection($s->sql)->advance($next, $events);
            new SqlAccountEntryProjection($s->sql)->advance($next, $events);
        });
        $account = $next;
    }
    public function definition(
        string $source = "historical_accounts",
        string $cutoff = "2026-08-03T10:00:00Z",
    ): array {
        return [
            "dataset" => $source,
            "financial_as_of" => $cutoff,
            "columns" => array_map(
                static fn(string $field): array => [
                    "field" => $field,
                    "label" => $field,
                    "aggregate" => "none",
                ],
                $source === "historical_accounts"
                    ? [
                        "account_id",
                        "currency",
                        "balance_minor",
                        "charges_minor",
                        "payments_minor",
                        "refunds_minor",
                    ]
                    : [
                        "posting_operation",
                        "currency",
                        "posting_type",
                        "posting_amount_minor",
                        "posting_effect_minor",
                    ],
            ),
        ];
    }
}
