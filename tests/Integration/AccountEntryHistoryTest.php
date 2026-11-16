<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\ActorDirectory;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Service\AccountEntryHistory;
use Domain\Finance\Service\AccountEntryReviewService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\EventPage;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Persistence\SqlActorDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class AccountEntryHistoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function query(
        SisFixture $s,
        ?AuditTrail $audit = null,
        ?ActorDirectory $actors = null,
        ?HistoricalEventStore $history = null,
    ): AccountEntryHistory {
        return new AccountEntryHistory(
            $history ?? EventStoreFixture::history($s->connection->queryBuilder()),
            $s->access,
            $s->transactions,
            $actors ?? new SqlActorDirectory($s->sql),
            $audit ?? $s->audit,
        );
    }

    private function account(SisFixture $s): AccountId
    {
        return $s->finance()->open($s->student()->id, $s->term, "USD")->aggregateId();
    }

    private function post(SisFixture $s, AccountId $id, string $reason = "Fictional private receipt."): EntityId
    {
        $operation = EntityId::generate();
        $s->finance()->post(
            $id,
            $operation,
            EntryType::Payment,
            new Money(1000, "USD"),
            $s->accounts->get($id)->playhead(),
            $reason,
        );
        return $operation;
    }

    private function correct(SisFixture $s, AccountId $id, EntityId $source, int $minor): EntityId
    {
        $service = new AccountEntryReviewService(
            $s->accounts,
            $s->access,
            $s->transactions,
            $s->financialCommands(),
            $s->audit,
            $s->dropCipher,
            $s->clock,
        );
        $proof = $service->reviewCorrection(
            $id,
            $source,
            new Money($minor, "USD"),
            $s->accounts->get($id)->playhead(),
            "Fictional correction " . $minor,
        );
        return new EntityId($service->commitCorrection($id, $source, $proof["review_token"])["operation_id"]);
    }

    private function monetaryFacts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_student_accounts",
            "sis_account_balances",
        ]);
    }

    public function testHistoryShowsOnlyTheSelectedFamilyWithActualReasonsAttributionAndTimes(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, "Fictional source reason.\n<script>literal only</script>");
        $unrelated = $this->post($s, $id, "Unrelated private receipt.");
        $replacement = $this->correct($s, $id, $source, 1250);
        $s->clock->time = new DateTimeImmutable("2026-11-01T01:30:00-07:00");
        $s->finance()->reverse($id, $replacement, 4, "Fictional reversal reason.");
        $s->sql->update(
            "users",
            ["first_name" => "Fictional", "last_name" => "Finance Reviewer"],
            ["user_id" => $s->access->actor->value],
        );
        $facts = $this->monetaryFacts($s);
        $page = $this->query($s)->page($id, $source);
        self::assertSame($facts, $this->monetaryFacts($s));
        self::assertSame(["reversed", "corrected", "posted"], array_column($page["items"], "action"));
        self::assertSame([5, 4, 2], array_column($page["items"], "sequence"));
        self::assertSame("Fictional Finance Reviewer", $page["items"][0]["recorded_by"]);
        self::assertSame("command", $page["items"][0]["actor_source"]);
        self::assertSame("Fictional reversal reason.", $page["items"][0]["reason"]);
        self::assertSame(1250, $page["items"][1]["amount_minor"]);
        self::assertSame(1000, $page["items"][1]["previous_minor"]);
        self::assertSame($source->value, $page["items"][1]["source_operation_id"]);
        self::assertSame("2026-11-01T08:30:00.000000Z", $page["items"][0]["effective_at"]);
        self::assertNotEmpty($page["items"][0]["recorded_at"]);
        self::assertTrue($page["entry"]["reversed"]);
        self::assertFalse($page["earlier_details_unknown"]);
        self::assertNull($page["next_after"]);
        $encoded = json_encode($page);
        $excludedValues = [
            "Unrelated private",
            $unrelated->value,
            "sealed_event",
            "review_hash",
            "event_hash",
            "review_token",
            "source_transaction_id",
            "legacy_record",
            "__aggregate",
            "actor_id",
        ];
        foreach ($excludedValues as $excluded) {
            self::assertStringNotContainsString($excluded, $encoded);
        }
        self::assertCount(1, $s->sql->rows("sis_audit", ["action" => "account.entry_history_read"]));
        self::assertSame(
            (string) $id,
            $s->sql->rows('sis_audit', ['action' => 'account.entry_history_read'])[0]['subject_id'],
        );
        self::assertStringNotContainsString("literal only", json_encode($s->sql->rows("sis_audit")));
    }

    public function testPagesFreezeTheCapturedHistoryAndStatusUntilExplicitRefresh(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id);
        for ($i = 1; $i <= 23; $i++) {
            $source = $this->correct($s, $id, $source, 1000 + $i);
        }
        $directory = $this->createMock(ActorDirectory::class);
        $directory
            ->expects(self::exactly(3))
            ->method("names")
            ->willReturnCallback(static function (array $actors): array {
                self::assertLessThanOrEqual(20, count($actors));
                return array_fill_keys(
                    array_map(static fn(EntityId $id): string => $id->value, $actors),
                    "Fictional Reviewer",
                );
            });
        $query = $this->query($s, actors: $directory);
        $first = $query->page($id, $source);
        self::assertCount(20, $first["items"]);
        self::assertFalse($first["entry"]["reversed"]);
        self::assertSame(20, $first["next_after"]);
        $this->correct($s, $id, $source, 1500);
        $next = $query->page($id, $source, $first["next_after"], $first["through_position"]);
        self::assertCount(4, $next["items"]);
        self::assertFalse($next["entry"]["reversed"]);
        self::assertSame($first["through_position"], $next["through_position"]);
        self::assertNull($next["next_after"]);
        self::assertSame(
            [],
            array_intersect(array_column($first["items"], "sequence"), array_column($next["items"], "sequence")),
        );
        $fresh = $query->page($id, $source);
        self::assertTrue($fresh["entry"]["reversed"]);
        self::assertSame($first["through_position"] + 1, $fresh["through_position"]);
        self::assertSame("Fictional correction 1500", $fresh["items"][0]["reason"]);
    }

    public function testEarlierFrameworkAttributionIsLabeledAndMissingReasonsAndEffectiveTimesStayUnknown(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $root = $s->accounts->get($id);
        $source = EntityId::generate();
        $root->post($source, EntryType::Payment, new Money(500, "USD"), $s->access->actor);
        EventStoreFixture::framework($s->connection->queryBuilder())->commit(
            ...iterator_to_array($root->getRecordedEvents()),
        );
        $page = $this->query($s)->page($id, $source);
        self::assertTrue($page["earlier_details_unknown"]);
        self::assertSame("earlier_event", $page["items"][0]["actor_source"]);
        self::assertNull($page["items"][0]["reason"]);
        self::assertNull($page["items"][0]["effective_at"]);
        self::assertNotSame("Not recorded", $page["items"][0]["recorded_by"]);
        self::assertArrayNotHasKey("legacy_record", $page["items"][0]);
    }

    public function testOwnersRegistrarsAndReadOnlyOrPostOnlyUsersCannotReadPrivateHistory(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id);
        $s->access->staff = false;
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::never())->method("page");
        $facts = $this->monetaryFacts($s);
        $audits = $s->sql->rows("sis_audit");
        $permissionSets = [
            [],
            ["sis:registration:manage"],
            ["sis:finance:read"],
            ["sis:finance:post"],
            ["sis:audit:read"],
        ];
        foreach ($permissionSets as $grants) {
            $s->access->grants = $grants;
            try {
                $this->query($s, history: $history)->page($id, $source);
                self::fail("A private financial history was exposed.");
            } catch (AccessDeniedException) {
                self::assertSame($facts, $this->monetaryFacts($s));
                self::assertSame($audits, $s->sql->rows("sis_audit"));
            }
        }
    }

    public function testWrongAccountEntriesAndInvalidPagingReturnNoPartialHistoryOrAudit(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id);
        $other = $this->account($s);
        $foreign = $this->post($s, $other);
        $facts = $this->monetaryFacts($s);
        $audits = $s->sql->rows("sis_audit");
        $invalidPages = ["account", "entry", "after", "negative", "missing_capture", "future_capture", "empty_page"];
        foreach ($invalidPages as $failure) {
            try {
                $this->query($s)->page(
                    $failure === "account" ? new AccountId() : $id,
                    $failure === "entry" ? $foreign : $source,
                    match ($failure) {
                        "after" => 1,
                        "negative" => -20,
                        "missing_capture", "empty_page" => 20,
                        default => 0,
                    },
                    match ($failure) {
                        "future_capture" => 3,
                        "empty_page" => 2,
                        default => null,
                    },
                );
                self::fail("Invalid paging or an unrelated account returned financial history.");
            } catch (ValidationException | RecordNotFoundException) {
                self::assertSame($facts, $this->monetaryFacts($s));
                self::assertSame($audits, $s->sql->rows("sis_audit"));
            }
        }
    }

    public function testLostAuthorityActorOrRequiredAuditRollsBackTheReadAuditAndReturnsNoPrivateResult(): void
    {
        foreach (["read", "post", "actor", "audit", "directory", "replay", "replay_actor"] as $failure) {
            $s = new SisFixture();
            $id = $this->account($s);
            $source = $this->post($s, $id);
            $other = $s->user();
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function ($actor, $action, $subject) use ($s, $failure, $other): void {
                    $s->audit->record($actor, $action, $subject);
                    if ($failure === "audit") {
                        throw new RuntimeException("Fictional history audit failure.");
                    }
                    if ($failure === "actor") {
                        $s->access->actor = $other;
                    }
                    if (in_array($failure, ["read", "post"], true)) {
                        $s->access->staff = false;
                        $s->access->grants = $failure === "read" ? ["sis:finance:post"] : ["sis:finance:read"];
                    }
                });
            $directory = null;
            if ($failure === "directory") {
                $directory = $this->createStub(ActorDirectory::class);
                $directory->method("names")->willReturnCallback(static function () use ($s): array {
                    $s->access->staff = false;
                    $s->access->grants = [];
                    return [];
                });
            }
            $history = null;
            if (in_array($failure, ['replay', 'replay_actor'], true)) {
                $inner = EventStoreFixture::history($s->connection->queryBuilder());
                $history = $this->createStub(HistoricalEventStore::class);
                $history->method('page')->willReturnCallback(static function (
                    $stream,
                    $cursor,
                    $limit
                ) use (
                    $s,
                    $inner,
                    $failure,
                    $other,
                ): EventPage {
                    $page = $inner->page($stream, $cursor, $limit);
                    if ($failure === 'replay_actor') {
                        $s->access->actor = $other;
                    } else {
                        $s->access->staff = false;
                        $s->access->grants = [];
                    }
                    return $page;
                });
                $directory = $this->createMock(ActorDirectory::class);
                $directory->expects(self::never())->method('names');
            }
            $facts = $this->monetaryFacts($s);
            $audits = $s->sql->rows("sis_audit");
            try {
                $this->query($s, $audit, $directory, $history)->page($id, $source);
                self::fail("A history response survived lost authority or audit failure.");
            } catch (AccessDeniedException | RuntimeException) {
                self::assertSame($facts, $this->monetaryFacts($s));
                self::assertSame($audits, $s->sql->rows("sis_audit"));
            }
        }
    }

    public function testReplayAndRetainedTextBoundsStopWithoutReturningAnIncompleteHistory(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id);
        $history = $this->createStub(HistoricalEventStore::class);
        $history->method("page")->willReturn(new EventPage([], null, 2001));
        try {
            $this->query($s, history: $history)->page($id, $source);
            self::fail("An excessive history head was accepted.");
        } catch (HistoryReadLimitException) {
            self::assertSame([], $s->sql->rows("sis_audit", ["action" => "account.entry_history_read"]));
        }
        for ($i = 0; $i < 55; $i++) {
            $this->post($s, $id);
        }
        $inner = EventStoreFixture::history($s->connection->queryBuilder());
        $bounded = $this->createStub(HistoricalEventStore::class);
        $bounded
            ->method("page")
            ->willReturnCallback(static function ($stream, $cursor, $limit) use ($inner): EventPage {
                $page = $inner->page($stream, $cursor, $limit);
                $events = array_map(
                    static fn(StoredEvent $e): StoredEvent => new StoredEvent(
                        $e->id,
                        $e->event,
                        new EventContext(
                            $e->context->actor,
                            $e->context->effectiveAt,
                            $e->context->correlation,
                            reason: str_repeat("🎓", 10000),
                        ),
                        $e->recordedAt,
                        $e->hash,
                    ),
                    $page->events,
                );
                return new EventPage($events, $page->nextCursor, $page->throughVersion);
            });
        $facts = $this->monetaryFacts($s);
        $this->expectException(HistoryReadLimitException::class);
        try {
            $this->query($s, history: $bounded)->page($id, $source);
        } finally {
            self::assertSame($facts, $this->monetaryFacts($s));
            self::assertSame([], $s->sql->rows("sis_audit", ["action" => "account.entry_history_read"]));
        }
    }

    public function testCorruptProtectedHistoryIsNotRepairedOrReturned(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id);
        $stream = $s->sql->one("sis_history_streams", [
            "aggregate_id" => (string) $id,
            "stream_type" => "student_account",
        ]);
        $row = $s->sql->rows("sis_history_events", ["stream_id" => $stream["id"]], 1)[0];
        $s->sql->update(
            "sis_history_events",
            ["sealed_event" => "invalid-fictional-ciphertext"],
            ["id" => $row["id"]],
        );
        $facts = $this->monetaryFacts($s);
        $this->expectException(CorruptHistoryException::class);
        try {
            $this->query($s)->page($id, $source);
        } finally {
            self::assertSame($facts, $this->monetaryFacts($s));
            self::assertSame([], $s->sql->rows("sis_audit", ["action" => "account.entry_history_read"]));
        }
    }
}
