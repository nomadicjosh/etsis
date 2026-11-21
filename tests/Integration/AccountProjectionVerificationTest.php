<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\Enum\EntryType;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Projection\SqlAccountProjectionVerifier;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AccountProjectionVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function account(SisFixture $s): StudentAccount
    {
        $a = StudentAccount::open(new AccountId(), $s->student()->id, $s->term, 'USD');
        $a->post(
            EntityId::generate(),
            EntryType::Charge,
            new Money(10000, 'USD'),
            $s->access->actor,
            staffRecorded: true
        );
        $s->saveAccount($a);
        $source = new EntityId($a->entries()[0]['operation_id']);
        $replacement = EntityId::generate();
        $a->correct(
            $source,
            $replacement,
            new Money(12000, 'USD'),
            $s->access->actor,
            str_repeat('a', 64),
            null
        );
        $a->creditCharge(
            $replacement,
            EntityId::generate(),
            new Money(1000, 'USD'),
            $s->access->actor,
            str_repeat('b', 64)
        );
        $s->saveAccount($a);
        return $a;
    }

    private function service(SisFixture $s, ?HistoricalEventStore $history = null): SqlAccountProjectionVerifier
    {
        return new SqlAccountProjectionVerifier($s->sql, $history ?? \EventStoreFixture::history(
            $s->connection->queryBuilder(),
        ));
    }

    private function digest(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_student_accounts',
            'sis_account_balances', 'sis_account_entries', 'sis_account_entry_heads', 'sis_audit',
        ]);
    }

    public function testAnEnclosingSnapshotCannotBeReportedAsVerified(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $result = $s->transactions->run(fn() => $this->service($s)->verify($account->aggregateId()));
        self::assertSame('unverified', $result->status);
        self::assertSame(['unavailable'], $result->issues);
    }

    public function testHealthyAccountIsVerifiedWithSqliteWritesProhibitedAndNoSensitiveOutput(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $before = $this->digest($s);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->service($s)->verify($account->aggregateId());
        self::assertSame('verified', $result->status);
        self::assertSame($account->playhead(), $result->events);
        self::assertSame($account->entryCount(), $result->postings);
        self::assertSame($account->playhead(), $result->position);
        self::assertSame($before, $this->digest($s));
        $text = json_encode($result->describe(), JSON_THROW_ON_ERROR);
        foreach (['Fictional attributed', 'sealed', 'reason', 'currency', 'amount_minor'] as $private) {
            self::assertStringNotContainsString($private, $text);
        }
    }

    public function testIndividualProjectionDifferencesAreReportedWithoutRepairOrJournalChanges(): void
    {
        foreach (['balance_minor', 'charges_minor', 'payments_minor', 'refunds_minor', 'position'] as $field) {
            $s = new SisFixture();
            $a = $this->account($s);
            $s->sql->update('sis_account_balances', [$field => 12345], ['id' => (string) $a->aggregateId()]);
            $before = $this->digest($s);
            $result = $this->service($s)->verify($a->aggregateId());
            self::assertSame('unverified', $result->status);
            self::assertSame(['balance'], $result->issues);
            self::assertSame($before, $this->digest($s));
        }
    }

    public function testPostingFieldsAndStableReferencesAndCheckpointAreComparedExactly(): void
    {
        foreach (
            ['amount_minor' => '10000oops', 'effect_minor' => 222, 'reversed' => 0,
            'id' => EntityId::generate()->value, 'origin' => 'unclassified', 'charge_source' => 'term_fee',
            'recorded_at' => '2000-01-01 00:00:00', 'corrected_by_operation_id' => null] as $field => $value
        ) {
            $s = new SisFixture();
            $a = $this->account($s);
            $row = $s->sql->rows('sis_account_entries', ['account_id' => (string) $a->aggregateId(),
                'operation_id' => $a->entries()[0]['operation_id']], 1)[0];
            $s->sql->update('sis_account_entries', [$field => $value], ['id' => $row['id']]);
            $before = $this->digest($s);
            self::assertSame(['postings'], $this->service($s)->verify($a->aggregateId())->issues, $field);
            self::assertSame($before, $this->digest($s));
        }
        $s = new SisFixture();
        $a = $this->account($s);
        $s->sql->update('sis_account_entry_heads', ['entry_count' => 999], ['id' => (string) $a->aggregateId()]);
        self::assertSame(['postings'], $this->service($s)->verify($a->aggregateId())->issues);
    }

    public function testMissingOrExtraPostingRowsAndMissingCheckpointAreNotSilentlyInitialized(): void
    {
        foreach (['row', 'head', 'extra'] as $damage) {
            $s = new SisFixture();
            $a = $this->account($s);
            $row = $s->sql->rows('sis_account_entries', ['account_id' => (string) $a->aggregateId(),
                'operation_id' => $a->entries()[0]['operation_id']], 1)[0];
            if ($damage === 'row') {
                $s->sql->execute('DELETE FROM sis_account_entries WHERE id = ?', [$row['id']]);
            } elseif ($damage === 'head') {
                $s->sql->execute('DELETE FROM sis_account_entry_heads WHERE id = ?', [(string) $a->aggregateId()]);
            } else {
                $row['id'] = EntityId::generate()->value;
                $row['operation_id'] = EntityId::generate()->value;
                $s->sql->insert('sis_account_entries', $row);
            }
            $before = $this->digest($s);
            self::assertSame(['postings'], $this->service($s)->verify($a->aggregateId())->issues);
            self::assertSame($before, $this->digest($s));
        }
    }

    public function testAccountIdentityAndDamagedJournalRemainDistinctFailuresWithoutPrivateExceptions(): void
    {
        $s = new SisFixture();
        $a = $this->account($s);
        $s->sql->update('sis_student_accounts', ['currency' => 'EUR'], ['id' => (string) $a->aggregateId()]);
        self::assertSame(['account_identity'], $this->service($s)->verify($a->aggregateId())->issues);
        $s->sql->update('sis_student_accounts', ['currency' => 'USD'], ['id' => (string) $a->aggregateId()]);
        $head = $s->sql->rows('sis_history_streams', ['aggregate_id' => (string) $a->aggregateId()], 1)[0];
        $event = $s->sql->rows('sis_history_events', ['stream_id' => $head['id']], 1)[0];
        $s->sql->update(
            'sis_history_events',
            ['sealed_event' => 'Fictional private ciphertext failure'],
            ['id' => $event['id']],
        );
        $before = $this->digest($s);
        $result = $this->service($s)->verify($a->aggregateId());
        self::assertSame(['journal'], $result->issues);
        self::assertSame($before, $this->digest($s));
        self::assertStringNotContainsString('ciphertext', json_encode($result->describe(), JSON_THROW_ON_ERROR));
    }

    public function testInsufficientBudgetDoesNotClaimSuccessOrReadTheWholeStream(): void
    {
        $s = new SisFixture();
        $a = $this->account($s);
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::never())->method('page');
        $before = $this->digest($s);
        $result = $this->service($s, $history)->verify($a->aggregateId(), 1);
        self::assertSame('limited', $result->status);
        self::assertSame(0, $result->events);
        self::assertSame($before, $this->digest($s));
        $this->expectException(\InvalidArgumentException::class);
        $this->service($s)->verify($a->aggregateId(), 2001);
    }

    public function testConcurrentPostingIsReportedAsChangedInsteadOfProjectionDamage(): void
    {
        $s = new SisFixture();
        $a = $this->account($s);
        $store = \EventStoreFixture::history($s->connection->queryBuilder());
        $history = $this->createStub(HistoricalEventStore::class);
        $history->method('page')->willReturnCallback(function ($stream, $cursor, $limit) use ($store, $s, $a) {
            $page = $store->page($stream, $cursor, $limit);
            $a->post(
                EntityId::generate(),
                EntryType::Payment,
                new Money(100, 'USD'),
                $s->access->actor
            );
            $s->saveAccount($a);
            return $page;
        });
        $result = $this->service($s, $history)->verify($a->aggregateId());
        self::assertSame('changed', $result->status);
        self::assertSame([], $result->issues);
        self::assertSame('verified', $this->service($s)->verify($a->aggregateId())->status);
    }

    public function testUnknownAccountNeverLooksLikeAnEmptyVerifiedLedger(): void
    {
        $s = new SisFixture();
        $result = $this->service($s)->verify(new AccountId());
        self::assertSame('unverified', $result->status);
        self::assertSame(['journal', 'account_identity'], $result->issues);
        self::assertSame(0, $result->events);
        self::assertNull($result->position);
        self::assertNull($result->postings);
    }

    public function testGenuinelyOpenedAccountWithoutPostingsHasVerifiedZeroRatherThanUnknown(): void
    {
        $s = new SisFixture();
        $account = StudentAccount::open(new AccountId(), $s->student()->id, $s->term, 'USD');
        $s->saveAccount($account);
        $result = $this->service($s)->verify($account->aggregateId());
        self::assertSame('verified', $result->status);
        self::assertSame(1, $result->events);
        self::assertSame(0, $result->postings);
    }

    public function testUnrelatedAccountActivityDoesNotInvalidateTheSelectedAccount(): void
    {
        $s = new SisFixture();
        $selected = $this->account($s);
        $other = $this->account($s);
        $store = \EventStoreFixture::history($s->connection->queryBuilder());
        $history = $this->createStub(HistoricalEventStore::class);
        $history->method('page')->willReturnCallback(function ($stream, $cursor, $limit) use ($store, $s, $other) {
            $page = $store->page($stream, $cursor, $limit);
            $other->post(
                EntityId::generate(),
                EntryType::Payment,
                new Money(100, 'USD'),
                $s->access->actor
            );
            $s->saveAccount($other);
            return $page;
        });
        self::assertSame('verified', $this->service($s, $history)->verify($selected->aggregateId())->status);
    }

    public function testOversizedReplayAndUpstreamFailuresReturnOnlySafeStatusMetadata(): void
    {
        $s = new SisFixture();
        $a = $this->account($s);
        $history = $this->createStub(HistoricalEventStore::class);
        $event = \Domain\Finance\Event\StudentAccountWasOpened::occur($a->aggregateId(), [], [
            'fictional_private_padding' => str_repeat('x', 4_194_305),
        ]);
        $history->method('page')->willReturn(new \Domain\Shared\EventSourcing\EventPage([
            new \Domain\Shared\EventSourcing\StoredEvent(
                EntityId::generate(),
                $event,
                new EventContext($s->access->actor, $s->clock->now(), EntityId::generate()),
                $s->clock->now(),
                str_repeat('0', 64)
            ),
        ], null, $a->playhead()));
        $before = $this->digest($s);
        self::assertSame('limited', $this->service($s, $history)->verify($a->aggregateId())->status);
        self::assertSame($before, $this->digest($s));
        $unavailable = $this->createStub(HistoricalEventStore::class);
        $unavailable->method('page')->willThrowException(new \RuntimeException('Fictional private upstream failure.'));
        $result = $this->service($s, $unavailable)->verify($a->aggregateId());
        self::assertSame(['unavailable'], $result->issues);
        self::assertStringNotContainsString('private upstream', json_encode($result->describe(), JSON_THROW_ON_ERROR));
    }
}
