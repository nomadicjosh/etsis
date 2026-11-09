<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/EventStoreFixture.php";
require_once dirname(__DIR__, 2) .
    "/database/migrations/20230901025725_CreateEventStoreTable.php";
require_once dirname(__DIR__, 2) .
    "/database/migrations/20261108000002_CreateSisHistoricalEventTables.php";

use Codefy\Domain\EventSourcing\DomainEvent;
use Codefy\Domain\EventSourcing\TransactionId;
use Defuse\Crypto\Key;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\User;
use Domain\User\ValueObject\UserId;
use Domain\User\ValueObject\Username;
use Domain\User\ValueObject\UserRole;
use Domain\User\ValueObject\UserToken;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\DatabaseUnitOfWork;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\SystemClock;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\ValueObjects\Person\Name;
use Qubus\ValueObjects\StringLiteral\StringLiteral;
use Qubus\ValueObjects\Web\EmailAddress;

final class SingleEventStoreMigrationTest extends TestCase
{
    private Sqlite $connection;
    private SqlRecords $sql;
    private ArrayObject $map;
    private ProtectedEventCodec $codec;
    private HistoricalSchemaRegistry $schemas;

    protected function setUp(): void
    {
        $this->connection = new Sqlite([
            "driver" => "pdo_sqlite",
            "dsn" => "sqlite::memory:",
        ]);
        $this->sql = new SqlRecords($this->connection->queryBuilder());
        $this->codec = new ProtectedEventCodec(
            EventStoreFixture::config($this->connection->queryBuilder()),
        );
        $this->schemas = new HistoricalSchemaRegistry();
        $this->map = new ArrayObject([
            "phpmig.adapter" => new DbalMigrationAdapter(
                $this->connection,
                "test_migrations",
            ),
            "sis.history_codec" => $this->codec,
        ]);
        $old = new CreateEventStoreTable("old");
        $old->setObjectMap($this->map);
        $old->up();
    }

    private function migrate(): void
    {
        $migration = new CreateSisHistoricalEventTables("unified");
        $migration->setObjectMap($this->map);
        $migration->up();
    }

    private function history(): SqlHistoricalEventStore
    {
        $db = $this->connection->queryBuilder();
        return new SqlHistoricalEventStore(
            $this->sql,
            new DatabaseUnitOfWork($db),
            new SystemClock(),
            $this->codec,
            $this->schemas,
        );
    }

    /** @return list<DomainEvent> */
    private function accountEvents(int $entries = 2): array
    {
        $account = StudentAccount::open(
            new AccountId(),
            EntityId::generate(),
            EntityId::generate(),
            "USD",
        );
        for ($i = 0; $i < $entries; $i++) {
            $account->post(
                EntityId::generate(),
                EntryType::Charge,
                new Money(100, "USD"),
                EntityId::generate(),
            );
        }
        return iterator_to_array($account->getRecordedEvents());
    }

    /** @param list<DomainEvent> $events
     * @return list<array<string, mixed>>
     */
    private function seed(array $events): array
    {
        $rows = [];
        $transaction = (string) new TransactionId();
        foreach ($events as $event) {
            $metadata = [
                "__aggregate_type" => $event->metaParam("__aggregate_type"),
                "__aggregate_id" => (string) $event->aggregateId(),
                "__aggregate_playhead" => $event->playhead(),
                "__event_id" => (string) $event->eventId(),
                "__event_type" => $event->eventType(),
                "__recorded_at" => "2026-01-02 03:04:05",
            ];
            $row = [
                "event_id" => (string) $event->eventId(),
                "transaction_id" => $transaction,
                "event_type" => $event->eventType(),
                "event_classname" => $event::class,
                "payload" => json_encode(
                    $event->payload(),
                    JSON_THROW_ON_ERROR,
                ),
                "metadata" => json_encode($metadata, JSON_THROW_ON_ERROR),
                "aggregate_id" => (string) $event->aggregateId(),
                "aggregate_type" => $metadata["__aggregate_type"],
                "aggregate_playhead" => $event->playhead(),
                "recorded_at" => "2026-01-02 03:04:06",
            ];
            $this->sql->insert("event_store", $row);
            $rows[] = $row;
        }
        return $rows;
    }

    public function testPopulatedStoreMovesIntoOneEncryptedTablePreservingIdentityPayloadMetadataAndClocks(): void
    {
        $user = User::createUser(
            new UserId(),
            new Username("fictional"),
            new UserToken(),
            Name::fromNative("Alex", "", "Example"),
            new EmailAddress("alex@example.invalid"),
            new UserRole("student"),
            new StringLiteral("fictional-password-hash"),
            new DateTimeImmutable("2022-08-24T12:00:00-07:00"),
        );
        $user->changeEmailAddress(new EmailAddress("updated@example.invalid"));
        $user->changeName(Name::fromNative("Robin", "", "Example"));
        $user->changePassword(
            new StringLiteral("fictional-changed-password-hash"),
            new UserToken(),
        );
        $user->changeRole(new UserRole("staff"));
        $user->deleteUser($user->userId());
        $actor = EntityId::generate();
        $rule = \Domain\Finance\BillingRule::define(
            new \Domain\Finance\ValueObject\BillingRuleId(),
            EntityId::generate(),
            null,
            "Fictional fee",
            "fee",
            new Money(1000, "USD"),
            $actor,
        );
        $rule->retire($actor);
        $journal = \Domain\Finance\Journal::post(
            new \Domain\Finance\ValueObject\JournalId(),
            "FICTIONAL.001",
            new DateTimeImmutable("2026-01-02"),
            [
                new \Domain\Finance\LedgerLine(
                    EntityId::generate(),
                    new Money(100, "USD"),
                ),
                new \Domain\Finance\LedgerLine(
                    EntityId::generate(),
                    new Money(-100, "USD"),
                ),
            ],
            $actor,
        );
        $journal->reverse($actor);
        $events = [
            ...iterator_to_array($user->getRecordedEvents()),
            ...$this->accountEvents(),
            ...iterator_to_array($rule->getRecordedEvents()),
            ...iterator_to_array($journal->getRecordedEvents()),
        ];
        $original = $this->seed($events);
        // The earliest recorded User schema did not have a person link. Preserve it without adding one.
        $payload = json_decode(
            $original[0]["payload"],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        unset($payload["person_id"]);
        $original[0]["payload"] = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->sql->update(
            "event_store",
            ["payload" => $original[0]["payload"]],
            ["event_id" => $original[0]["event_id"]],
        );
        $this->migrate();
        self::assertFalse($this->sql->tableExists("event_store"));
        self::assertCount(
            count($original),
            $this->sql->rows("sis_history_events"),
        );
        $framework = EventStoreFixture::framework(
            $this->connection->queryBuilder(),
        );
        foreach (
            [
                $user->userId(),
                $events[6]->aggregateId(),
                $rule->aggregateId(),
                $journal->aggregateId(),
            ]
            as $id
        ) {
            $stream = $this->schemas->streamFor($id);
            $page = $this->history()->page($stream);
            foreach ($page->events as $stored) {
                $index = array_search(
                    (string) $stored->event->eventId(),
                    array_column($original, "event_id"),
                    true,
                );
                self::assertNotFalse($index);
                self::assertSame(
                    ProtectedEventCodec::canonical($original[$index]),
                    ProtectedEventCodec::canonical(
                        $stored->context->legacyRecord,
                    ),
                );
                self::assertSame("legacy", $stored->context->origin);
                self::assertNull($stored->context->effectiveAt);
                self::assertNull($stored->context->correlation);
                self::assertSame(
                    "2026-01-02 03:04:05",
                    $stored->event->recordedAt()->format("Y-m-d H:i:s"),
                );
                self::assertSame(
                    ProtectedEventCodec::canonical(
                        json_decode($original[$index]["payload"], true),
                    ),
                    ProtectedEventCodec::canonical($stored->event->payload()),
                );
                self::assertSame(
                    $original[$index]["transaction_id"],
                    $stored->context->sourceTransactionId,
                );
            }
            self::assertSame(
                array_map(
                    static fn($e) => (string) $e->event->eventId(),
                    $page->events,
                ),
                array_map(
                    static fn($e) => (string) $e->eventId(),
                    iterator_to_array($framework->getAggregateHistoryFor($id)),
                ),
            );
        }
        $restored = User::reconstituteFromEventStream(
            $framework->getAggregateHistoryFor($user->userId()),
        );
        self::assertSame(
            "updated@example.invalid",
            (string) $restored->emailAddress(),
        );
        self::assertSame(
            "fictional-changed-password-hash",
            (string) $restored->password(),
        );
        $created = iterator_to_array(
            $framework->getAggregateHistoryFor($user->userId()),
        )[0];
        self::assertSame(
            "2022-08-24T12:00:00-07:00",
            $created->createdOn()->format("c"),
        );
        $text = json_encode(
            $this->sql->rows("sis_history_events"),
            JSON_THROW_ON_ERROR,
        );
        foreach (
            [
                "alex@example.invalid",
                "fictional-password-hash",
                "fictional-changed-password-hash",
                "first_name",
                "legacy_record",
            ]
            as $secret
        ) {
            self::assertStringNotContainsString($secret, $text);
        }
        $before = $this->sql->rows("sis_history_events");
        $this->migrate();
        self::assertSame($before, $this->sql->rows("sis_history_events"));
    }

    public function testInterruptedCopyRetainsSourceBlocksRuntimeAndResumesVerifiedPrefixWithoutDuplicates(): void
    {
        $events = $this->accountEvents();
        $original = $this->seed($events);
        $this->sql->update(
            "event_store",
            ["event_classname" => "UnregisteredSecretClass"],
            ["event_id" => $original[1]["event_id"]],
        );
        try {
            $this->migrate();
            self::fail("An unknown legacy schema migrated.");
        } catch (CorruptHistoryException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString(
                "UnregisteredSecretClass",
                $error->getMessage(),
            );
        }
        self::assertTrue($this->sql->tableExists("event_store"));
        self::assertCount(3, $this->sql->rows("event_store"));
        self::assertCount(1, $this->sql->rows("sis_history_events"));
        $prefix = $this->sql->rows("sis_history_events");
        try {
            $this->history()->page(
                $this->schemas->streamFor($events[0]->aggregateId()),
            );
            self::fail(
                "An incomplete migration was readable by a normal consumer.",
            );
        } catch (LogicException) {
            self::assertTrue($this->sql->tableExists("event_store"));
        }
        $this->sql->update(
            "event_store",
            ["event_classname" => $original[1]["event_classname"]],
            ["event_id" => $original[1]["event_id"]],
        );
        $this->migrate();
        self::assertFalse($this->sql->tableExists("event_store"));
        self::assertCount(3, $this->sql->rows("sis_history_events"));
        self::assertSame(
            $prefix[0],
            $this->sql->rows("sis_history_events", [
                "id" => $prefix[0]["id"],
            ])[0],
        );
    }

    public function testResumeRefusesAChangedSourcePrefixInsteadOfOverwritingAlreadyCopiedHistory(): void
    {
        $original = $this->seed($this->accountEvents());
        $this->sql->update(
            "event_store",
            ["event_classname" => "Unregistered"],
            ["event_id" => $original[1]["event_id"]],
        );
        try {
            $this->migrate();
            self::fail("Invalid source migrated.");
        } catch (CorruptHistoryException) {
            self::assertCount(1, $this->sql->rows("sis_history_events"));
        }
        $prefix = $this->sql->rows("sis_history_events");
        $payload = json_decode(
            $original[0]["payload"],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $payload["student_id"] = EntityId::generate()->value;
        $this->sql->update(
            "event_store",
            ["payload" => json_encode($payload, JSON_THROW_ON_ERROR)],
            ["event_id" => $original[0]["event_id"]],
        );
        $this->sql->update(
            "event_store",
            ["event_classname" => $original[1]["event_classname"]],
            ["event_id" => $original[1]["event_id"]],
        );
        try {
            $this->migrate();
            self::fail("A changed source prefix replaced verified events.");
        } catch (CorruptHistoryException) {
            self::assertTrue($this->sql->tableExists("event_store"));
            self::assertSame($prefix, $this->sql->rows("sis_history_events"));
        }
    }

    public function testMissingKeyOrMalformedSourceNeverDropsOriginalEventsAndRollbackIsRefused(): void
    {
        $original = $this->seed($this->accountEvents(0));
        unset($this->map["sis.history_codec"]);
        try {
            $this->migrate();
            self::fail(
                "Missing encryption configuration migrated existing events.",
            );
        } catch (LogicException) {
            self::assertSame($original, $this->sql->rows("event_store"));
        }
        $this->map["sis.history_codec"] = $this->codec;
        $this->sql->update(
            "event_store",
            ["metadata" => "{broken-secret"],
            ["event_id" => $original[0]["event_id"]],
        );
        try {
            $this->migrate();
            self::fail("Malformed source metadata was accepted.");
        } catch (CorruptHistoryException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString(
                "broken-secret",
                $error->getMessage(),
            );
            self::assertTrue($this->sql->tableExists("event_store"));
        }
        $migration = new CreateSisHistoricalEventTables("unified");
        $migration->setObjectMap($this->map);
        $this->expectException(LogicException::class);
        $migration->down();
    }

    public function testFrameworkAndHistoricalPortsUseSameStreamAndRejectStaleFrameworkWrites(): void
    {
        $this->migrate();
        $events = $this->accountEvents();
        $framework = EventStoreFixture::framework(
            $this->connection->queryBuilder(),
        );
        $framework->commit(...$events);
        $stream = $this->schemas->streamFor($events[0]->aggregateId());
        $page = $this->history()->page($stream);
        self::assertCount(3, $page->events);
        self::assertSame("framework", $page->events[0]->context->origin);
        self::assertNull($page->events[0]->context->actor);
        self::assertNull($page->events[0]->context->effectiveAt);
        self::assertSame(
            (string) $events[1]->aggregateId(),
            (string) $page->events[1]->event->aggregateId(),
        );
        self::assertCount(
            2,
            iterator_to_array(
                $framework->loadFromPlayhead($events[0]->aggregateId(), 2),
            ),
        );
        $replacement = \Domain\Finance\Event\AccountEntryWasPosted::occur(
            $events[0]->aggregateId(),
            $events[2]->payload(),
            ["__aggregate_type" => "student_account"],
        )->withPlayhead(3);
        $this->expectException(ConcurrentModificationException::class);
        $framework->commit($replacement);
    }

    public function testCopyAndReplayStayBoundedAcrossMoreThanOnePage(): void
    {
        $events = $this->accountEvents(109);
        $this->seed($events);
        $this->migrate();
        self::assertFalse($this->sql->tableExists("event_store"));
        self::assertCount(110, $this->sql->rows("sis_history_events"));
        $framework = EventStoreFixture::framework(
            $this->connection->queryBuilder(),
        );
        $restored = StudentAccount::reconstituteFromEventStream(
            $framework->getAggregateHistoryFor($events[0]->aggregateId()),
        );
        self::assertSame(10900, $restored->balance()->minor);
    }
}
