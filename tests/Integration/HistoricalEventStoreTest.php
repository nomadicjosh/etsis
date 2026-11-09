<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Codefy\Domain\EventSourcing\AggregateChanged;
use Codefy\Domain\EventSourcing\EventStream;
use Codefy\Domain\Metadata;
use Defuse\Crypto\Key;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Event\AccountEntryWasPosted;
use Domain\Finance\Event\StudentAccountWasOpened;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class HistoricalEventStoreTest extends TestCase
{
    private SisFixture $f;
    private Collection $config;
    private ProtectedEventCodec $codec;
    private SqlHistoricalEventStore $store;
    private StreamReference $stream;
    private EventContext $context;

    protected function setUp(): void
    {
        $this->f = new SisFixture();
        $this->config = EventStoreFixture::config($this->f->connection->queryBuilder());
        $this->config->setConfigKey("event_history", [
            "active_key_id" => "v1",
            "keys" => ["v1" => Key::createNewRandomKey()->saveToAsciiSafeString()],
        ]);
        $this->codec = new ProtectedEventCodec($this->config);
        $this->store = $this->store();
        $this->stream = new StreamReference(new AccountId(), "student_account");
        $this->context = new EventContext(
            $this->f->access->actor,
            new DateTimeImmutable("2026-08-24 00:00:00.000001", new DateTimeZone("America/Los_Angeles")),
            EntityId::generate(),
            EntityId::generate(),
            "Fictional confidential approval reason",
            EntityId::generate(),
        );
        $this->f->clock->time = new DateTimeImmutable("2026-11-08T17:00:01.123456+00:00");
    }

    private function store(): SqlHistoricalEventStore
    {
        return new SqlHistoricalEventStore(
            $this->f->sql,
            $this->f->transactions,
            $this->f->clock,
            $this->codec,
            new HistoricalSchemaRegistry(),
        );
    }

    private function opened(?StreamReference $stream = null): StudentAccountWasOpened
    {
        $stream ??= $this->stream;
        return StudentAccountWasOpened::occur(
            $stream->aggregateId,
            ["student_id" => EntityId::generate()->value, "term_id" => $this->f->term->value, "currency" => "USD"],
            [Metadata::AGGREGATE_TYPE => $stream->type, "policy_version_id" => $this->context->approval->value],
        );
    }

    private function posted(int $position): AccountEntryWasPosted
    {
        return AccountEntryWasPosted::occur(
            $this->stream->aggregateId,
            [
                "operation_id" => EntityId::generate()->value,
                "type" => "charge",
                "minor" => 1250,
                "currency" => "USD",
                "actor_id" => $this->context->actor->value,
            ],
            [Metadata::AGGREGATE_TYPE => "student_account"],
        )->withPlayhead($position);
    }

    public function testProtectedAndFrameworkEventsShareOneTableAndReplayTheirSeparateStreams(): void
    {
        $legacy = StudentAccount::open(new AccountId(), $this->f->student()->id, $this->f->term, "USD");
        $this->f->accounts->save($legacy);
        $legacyRows = $this->f->sql->rows("sis_history_events");
        $events = [$this->opened(), $this->posted(2)];
        $receipt = $this->store->append($this->stream, EntityId::generate(), 0, $this->context, $events);
        self::assertSame(1, $receipt["first_version"]);
        self::assertSame(2, $receipt["last_version"]);
        $page = $this->store->page($this->stream);
        self::assertCount(2, $page->events);
        self::assertNull($page->nextCursor);
        $first = $page->events[0];
        self::assertSame($this->context->describe(), $first->context->describe());
        self::assertSame("2026-11-08T17:00:01.123456+00:00", $first->recordedAt->format("Y-m-d\TH:i:s.uP"));
        self::assertSame($events[0]->recordedAt()->format("U.u"), $first->event->recordedAt()->format("U.u"));
        self::assertSame($this->context->approval->value, $first->event->metaParam("policy_version_id"));
        $restored = StudentAccount::reconstituteFromEventStream(
            new EventStream($this->stream->aggregateId, array_map(static fn($stored) => $stored->event, $page->events)),
        );
        self::assertSame(1250, $restored->balance()->minor);
        self::assertCount(count($legacyRows) + 2, $this->f->sql->rows("sis_history_events"));
        self::assertSame($legacyRows, $this->f->sql->rows("sis_history_events", ["stream_id" => $legacyRows[0]["stream_id"]]));
        self::assertFalse($this->f->sql->tableExists("event_store"));
        foreach (["sis_history_events", "sis_history_operations", "sis_history_streams"] as $table) {
            $rows = json_encode($this->f->sql->rows($table), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString("Fictional confidential", $rows);
            self::assertStringNotContainsString("policy_version_id", $rows);
            self::assertStringNotContainsString("student_id", $rows);
        }
        self::assertStringNotContainsString(
            "Fictional confidential",
            json_encode([$first, $this->context], JSON_THROW_ON_ERROR),
        );
    }

    public function testUnchangedOperationReturnsItsOriginalReceiptEvenAfterLaterEventsAndTechnicalIdRegeneration(): void
    {
        $event = $this->opened();
        $op = EntityId::generate();
        $receipt = $this->store->append($this->stream, $op, 0, $this->context, [$event]);
        $this->store->append($this->stream, EntityId::generate(), 1, $this->context, [$this->posted(2)]);
        $again = StudentAccountWasOpened::occur($this->stream->aggregateId, $event->payload(), [
            Metadata::AGGREGATE_TYPE => "student_account",
            "policy_version_id" => $event->metaParam("policy_version_id"),
        ]);
        self::assertNotSame((string) $event->eventId(), (string) $again->eventId());
        self::assertSame($receipt, $this->store->append($this->stream, $op, 0, $this->context, [$again]));
        self::assertCount(2, $this->f->sql->rows("sis_history_events"));
        $this->expectException(ConcurrentModificationException::class);
        $this->store->append($this->stream, $op, 0, $this->context, [$this->opened()]);
    }

    public function testReceiptCannotBeReusedByAnotherActorOrAnotherStream(): void
    {
        $event = $this->opened();
        $op = EntityId::generate();
        $this->store->append($this->stream, $op, 0, $this->context, [$event]);
        $other = new EventContext($this->f->user(), $this->context->effectiveAt, $this->context->correlation);
        try {
            $this->store->append($this->stream, $op, 0, $other, [$event]);
            self::fail("Another actor reused a receipt.");
        } catch (AccessDeniedException) {
            self::assertCount(1, $this->f->sql->rows("sis_history_events"));
        }
        $stream = new StreamReference(new AccountId(), "student_account");
        try {
            $this->store->append($stream, $op, 0, $this->context, [$this->opened($stream)]);
            self::fail("Another stream reused a receipt.");
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $this->f->sql->rows("sis_history_streams"));
        }
    }

    public function testTwoWritersCannotOverwriteACommittedVersionAndGapsAreRejected(): void
    {
        $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [$this->opened()]);
        try {
            $this->store()->append($this->stream, EntityId::generate(), 0, $this->context, [$this->opened()]);
            self::fail("A stale writer committed.");
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $this->f->sql->rows("sis_history_events"));
        }
        $this->expectException(InvalidArgumentException::class);
        $this->store->append($this->stream, EntityId::generate(), 1, $this->context, [$this->posted(3)]);
    }

    public function testOuterFailureRollsBackEventsHeadsReceiptsAndFinancialChangesTogether(): void
    {
        try {
            $this->f->transactions->run(function (): void {
                $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [$this->opened()]);
                $legacy = StudentAccount::open(new AccountId(), $this->f->student()->id, $this->f->term, "USD");
                $this->f->accounts->save($legacy);
                throw new RuntimeException("Fictional failure after append");
            });
            self::fail("An incomplete academic/financial operation committed.");
        } catch (RuntimeException $error) {
            self::assertSame("Fictional failure after append", $error->getMessage());
        }
        foreach (
            [
                "sis_history_streams",
                "sis_history_events",
                "sis_history_operations",
                "sis_history_events",
                "sis_student_accounts",
            ]
            as $table
        ) {
            self::assertSame([], $this->f->sql->rows($table), $table);
        }
    }

    public function testMidBatchUniqueConflictDoesNotLeavePartialHistory(): void
    {
        $first = $this->opened();
        $second = $this->posted(2)->withAddedMetadata(Metadata::EVENT_ID, $first->eventId());
        try {
            $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [$first, $second]);
            self::fail("A partial batch committed.");
        } catch (ConcurrentModificationException) {
            foreach (["sis_history_streams", "sis_history_events", "sis_history_operations"] as $table) {
                self::assertSame([], $this->f->sql->rows($table));
            }
        }
    }

    public function testPayloadColumnAndHeadTamperingFailClosedWithoutSensitiveExceptionText(): void
    {
        $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [$this->opened()]);
        $row = $this->f->sql->rows("sis_history_events")[0];
        foreach (
            [
                "sealed_event" => '{"payload":"Fictional confidential plaintext fallback"}',
                "event_type" => "grade-amended",
                "recorded_at" => "2000-01-01T00:00:00.000000Z",
                "previous_hash" => str_repeat("1", 64),
            ]
            as $key => $bad
        ) {
            $this->f->sql->update("sis_history_events", [$key => $bad], ["id" => $row["id"]]);
            try {
                $this->store->page($this->stream);
                self::fail("Tampering was accepted.");
            } catch (CorruptHistoryException $error) {
                self::assertStringNotContainsString("confidential", $error->getMessage());
                self::assertNull($error->getPrevious());
            }
            $this->f->sql->update("sis_history_events", [$key => $row[$key]], ["id" => $row["id"]]);
        }
        $head = $this->f->sql->rows("sis_history_streams")[0];
        $this->f->sql->update("sis_history_streams", ["version" => 0], ["id" => $head["id"]]);
        $this->expectException(CorruptHistoryException::class);
        $this->store->page($this->stream);
    }

    public function testRemovingTailOrAnInteriorEventCannotReturnAnApparentlyCompleteReplay(): void
    {
        $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [
            $this->opened(),
            $this->posted(2),
            $this->posted(3),
        ]);
        $head = $this->f->sql->rows("sis_history_streams")[0];
        $q = $this->f->sql->quote(...);
        $this->f->sql->execute(
            "DELETE FROM " .
                $q("sis_history_events") .
                " WHERE " .
                $q("stream_id") .
                " = ? AND " .
                $q("position") .
                " = ?",
            [$head["id"], 3],
        );
        $this->expectException(CorruptHistoryException::class);
        $this->store->page($this->stream);
    }

    public function testPagedReplayHasAFixedHeadAndCursorsCannotCrossStreamsOrPurposes(): void
    {
        $events = [$this->opened()];
        for ($n = 2; $n <= 5; $n++) {
            $events[] = $this->posted($n);
        }
        $this->store->append($this->stream, EntityId::generate(), 0, $this->context, $events);
        $page = $this->store->page($this->stream, limit: 2);
        self::assertSame(5, $page->throughVersion);
        $this->store->append($this->stream, EntityId::generate(), 5, $this->context, [$this->posted(6)]);
        $all = $page->events;
        while ($page->nextCursor !== null) {
            $page = $this->store->page($this->stream, $page->nextCursor, 2);
            array_push($all, ...$page->events);
        }
        self::assertCount(5, $all);
        self::assertSame(6, $this->store->page($this->stream)->throughVersion);
        $other = new StreamReference(new AccountId(), "student_account");
        $this->store->append($other, EntityId::generate(), 0, $this->context, [$this->opened($other)]);
        $cursor = $this->store->page($this->stream, limit: 2)->nextCursor;
        try {
            $this->store->page($other, $cursor);
            self::fail("A cursor crossed streams.");
        } catch (CorruptHistoryException) {
            self::assertCount(2, $this->f->sql->rows("sis_history_streams"));
        }
        $head = $this->f->sql->rows("sis_history_streams")[0];
        $this->expectException(CorruptHistoryException::class);
        $this->codec->open("history.cursor.v1", $head["sealed_anchor"]);
    }

    public function testKeyRotationRetainsOldReadersAndMissingKeysNeverFallBackToPlaintextOrAnotherKey(): void
    {
        $key1 = $this->config->getConfigKey("event_history.keys")["v1"];
        $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [$this->opened()]);
        $this->config->setConfigKey("event_history", [
            "active_key_id" => "v2",
            "keys" => ["v1" => $key1, "v2" => Key::createNewRandomKey()->saveToAsciiSafeString()],
        ]);
        $this->store->append($this->stream, EntityId::generate(), 1, $this->context, [$this->posted(2)]);
        self::assertCount(2, $this->store->page($this->stream)->events);
        $rows = $this->f->sql->rows("sis_history_events");
        self::assertSame(
            ["v1", "v2"],
            array_map(static fn(array $r) => json_decode($r["sealed_event"], true)["key_id"], $rows),
        );
        $key2 = $this->config->getConfigKey("event_history.keys")["v2"];
        $this->config->setConfigKey("event_history", ["active_key_id" => "v2", "keys" => ["v2" => $key2]]);
        $this->expectException(CorruptHistoryException::class);
        $this->store->page($this->stream);
    }

    public function testMissingWriteKeyUnknownSchemasAndUnregisteredClassesDoNotCreateStreams(): void
    {
        $event = $this->opened()->withAddedMetadata("schema_version", 2);
        try {
            $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [$event]);
            self::fail("An unknown schema was accepted.");
        } catch (CorruptHistoryException) {
            self::assertSame([], $this->f->sql->rows("sis_history_streams"));
        }
        $event = UnregisteredHistoricalTestEvent::occur(
            $this->stream->aggregateId,
            [],
            [Metadata::AGGREGATE_TYPE => "student_account"],
        );
        try {
            $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [$event]);
            self::fail("An unregistered class was accepted.");
        } catch (CorruptHistoryException) {
            self::assertSame([], $this->f->sql->rows("sis_history_streams"));
        }
        $this->config->setConfigKey("event_history", ["active_key_id" => "absent", "keys" => []]);
        $this->expectException(CorruptHistoryException::class);
        try {
            $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [$this->opened()]);
        } finally {
            self::assertSame([], $this->f->sql->rows("sis_history_streams"));
        }
    }

    public function testDefaultApplicationKeyConfigurationAndReadLimits(): void
    {
        $this->config->setConfigKey("event_history", ["active_key_id" => "app-v1", "keys" => []]);
        $this->config->setConfigKey("app", ["crypto_key" => Key::createNewRandomKey()->saveToAsciiSafeString()]);
        self::assertSame([], $this->store->page($this->stream)->events);
        $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [$this->opened()]);
        self::assertCount(1, $this->store->page($this->stream, limit: 1)->events);
        foreach ([0, 101] as $limit) {
            try {
                $this->store->page($this->stream, limit: $limit);
                self::fail("An unbounded page was accepted.");
            } catch (InvalidArgumentException) {
                self::assertCount(1, $this->f->sql->rows("sis_history_events"));
            }
        }
        $this->expectException(CorruptHistoryException::class);
        $this->store->append($this->stream, EntityId::generate(), 1, $this->context, [
            $this->posted(2)->withAddedMetadata("oversized", str_repeat("x", 70000)),
        ]);
    }
    public function testAReviewedEventCannotBeUsedInAnotherAggregateOrCarryUnknownFields(): void
    {
        $wrong = \Domain\Finance\Event\JournalWasReversed::occur(
            $this->stream->aggregateId,
            ["actor_id" => $this->context->actor->value],
            [Metadata::AGGREGATE_TYPE => "student_account"],
        );
        try {
            $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [$wrong]);
            self::fail("A journal event entered an account stream.");
        } catch (CorruptHistoryException) {
            self::assertSame([], $this->f->sql->rows("sis_history_events"));
        }
        foreach (
            [
                ["student_id" => EntityId::generate()->value, "term_id" => $this->f->term->value, "currency" => null],
                [
                    "student_id" => EntityId::generate()->value,
                    "term_id" => $this->f->term->value,
                    "currency" => "USD",
                    "unreviewed" => "private",
                ],
            ]
            as $payload
        ) {
            $event = StudentAccountWasOpened::occur($this->stream->aggregateId, $payload, [
                Metadata::AGGREGATE_TYPE => "student_account",
            ]);
            try {
                $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [$event]);
                self::fail("An invalid payload was recorded.");
            } catch (CorruptHistoryException) {
                self::assertSame([], $this->f->sql->rows("sis_history_streams"));
            }
        }
    }

    public function testAReceiptRetryCannotHideADeletedCommittedTail(): void
    {
        $op = EntityId::generate();
        $event = $this->opened();
        $this->store->append($this->stream, $op, 0, $this->context, [$event]);
        $q = $this->f->sql->quote(...);
        $this->f->sql->execute("DELETE FROM " . $q("sis_history_events"));
        $this->expectException(CorruptHistoryException::class);
        $this->store->append($this->stream, $op, 0, $this->context, [$event]);
    }

    public function testAValidEarlierAnchorRollbackIsDetectedByAnExistingReplayCursor(): void
    {
        $this->store->append($this->stream, EntityId::generate(), 0, $this->context, [$this->opened()]);
        $oldHead = $this->f->sql->rows("sis_history_streams")[0];
        $this->store->append($this->stream, EntityId::generate(), 1, $this->context, [
            $this->posted(2),
            $this->posted(3),
        ]);
        $cursor = $this->store->page($this->stream, limit: 1)->nextCursor;
        unset($oldHead["id"]);
        $this->f->sql->update("sis_history_streams", $oldHead, ["stream_type" => "student_account"]);
        $this->expectException(CorruptHistoryException::class);
        $this->store->page($this->stream, $cursor);
    }

    public function testReviewedBillingRuleJournalAndAccountSchemasRoundTripThroughCodefy(): void
    {
        $actor = $this->context->actor;
        $account = StudentAccount::open(new AccountId(), EntityId::generate(), $this->f->term, "USD");
        $op = EntityId::generate();
        $account->post($op, EntryType::Charge, new Money(1250, "USD"), $actor);
        $account->reverse($op, $actor);
        $rule = \Domain\Finance\BillingRule::define(
            new \Domain\Finance\ValueObject\BillingRuleId(),
            $this->f->term,
            null,
            "Fictional base fee",
            "fee",
            new Money(1000, "USD"),
            $actor,
        );
        $rule->retire($actor);
        $journal = \Domain\Finance\Journal::post(
            new \Domain\Finance\ValueObject\JournalId(),
            "FICTIONAL.001",
            new DateTimeImmutable("2026-11-08"),
            [
                new \Domain\Finance\LedgerLine(EntityId::generate(), new Money(100, "USD")),
                new \Domain\Finance\LedgerLine(EntityId::generate(), new Money(-100, "USD")),
            ],
            $actor,
        );
        $journal->reverse($actor);
        foreach (
            [[$account, "student_account"], [$rule, "billing_rule"], [$journal, "journal"]]
            as [$aggregate, $type]
        ) {
            $stream = new StreamReference($aggregate->aggregateId(), $type);
            $events = iterator_to_array($aggregate->getRecordedEvents());
            $this->store->append($stream, EntityId::generate(), 0, $this->context, $events);
            $read = $this->store->page($stream);
            self::assertSame(
                ProtectedEventCodec::canonical(["events" => array_map(static fn($e) => $e->payload(), $events)]),
                ProtectedEventCodec::canonical([
                    "events" => array_map(static fn($e) => $e->event->payload(), $read->events),
                ]),
            );
            self::assertCount(count($events), $read->events);
        }
        self::assertCount(7, $this->f->sql->rows("sis_history_events"));
    }

    public function testCaughtNestedValidationFailureStillAbortsTheEnclosingFinancialTransaction(): void
    {
        try {
            $this->f->transactions->run(function (): void {
                $legacy = StudentAccount::open(new AccountId(), $this->f->student()->id, $this->f->term, "USD");
                $this->f->accounts->save($legacy);
                try {
                    $this->store->append($this->stream, EntityId::generate(), 0, $this->context, []);
                } catch (InvalidArgumentException) {
                    /* A caller cannot swallow failure and commit partial work. */
                }
            });
            self::fail("A caught nested failure committed partial financial work.");
        } catch (\Domain\Shared\Exception\TransactionAbortedException) {
            self::assertSame([], $this->f->sql->rows("sis_history_events"));
            self::assertSame([], $this->f->sql->rows("sis_history_events"));
        }
    }
}

final class UnregisteredHistoricalTestEvent extends AggregateChanged {}
