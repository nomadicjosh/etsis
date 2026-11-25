<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\Enum\EntryType;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AccountRecoveryFixture;

final class AccountProjectionRecoveryTest extends TestCase
{
    private AccountRecoveryFixture $f;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Support/AccountRecoveryFixture.php';
        $this->f = new AccountRecoveryFixture();
    }

    protected function tearDown(): void
    {
        $this->f->close();
    }

    private function rejected(callable $action, string $type = \Throwable::class): void
    {
        $error = null;
        try {
            $action();
        } catch (\Throwable $caught) {
            $error = $caught;
        }
        self::assertInstanceOf($type, $error);
    }

    public function testStageAndCheckAreDatabaseReadOnlyAndArtifactsArePrivateEncryptedAndRedacted(): void
    {
        $f = $this->f;
        $facts = $f->facts();
        $rows = $f->projections();
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $id = $f->stage();
        $result = $f->service()->check($id);
        self::assertSame('ready', $result['status']);
        self::assertFalse($result['requires_repair']);
        self::assertSame($facts, $f->facts());
        self::assertSame($rows, $f->projections());
        $path = $f->directory . '/' . $id->value . '.stage';
        self::assertSame(0600, fileperms($path) & 0777);
        $bytes = file_get_contents($path);
        foreach (['12000', 'amount_minor', $f->account->studentId()->value, 'Fictional attributed'] as $private) {
            self::assertStringNotContainsString($private, $bytes);
            self::assertStringNotContainsString($private, json_encode($result, JSON_THROW_ON_ERROR));
        }
        self::assertStringNotContainsString('amount_minor', json_encode($f->storage->read($id), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('amount_minor', print_r($f->storage->read($id), true));
    }

    public function testRepairRestoresExactStableRowsPreservesJournalAndAllowsOrdinaryLaterPosting(): void
    {
        $f = $this->f;
        $facts = $f->facts();
        $correct = $f->projections();
        $f->damage();
        $id = $f->stage();
        self::assertTrue($f->service()->check($id)['requires_repair']);
        $stage = $f->storage->read($id);
        self::assertSame('999', $stage->data['before']['sis_account_balances'][0]['balance_minor']);
        self::assertSame([], $stage->data['before']['sis_account_entries']);
        $result = $f->service()->apply($id);
        self::assertSame('applied', $result['status']);
        self::assertTrue($result['projections_changed']);
        self::assertSame($correct, $f->projections());
        self::assertSame($facts, $f->facts());
        self::assertCount(1, $f->sis->sql->rows('sis_account_projection_repairs'));
        $f->account->post(EntityId::generate(), EntryType::Payment, new Money(100, 'USD'), $f->sis->access->actor);
        $f->sis->saveAccount($f->account);
        self::assertSame('11900', (string) $f->projections()[0][0]['balance_minor']);
    }

    public function testOriginalRetrySurvivesStageDeletionExpiryAndLaterGenuinePostsWithoutRepeatingWork(): void
    {
        $f = $this->f;
        $f->damage();
        $id = $f->stage();
        $first = $f->service()->apply($id);
        $f->storage->discard($id);
        $f->account->post(EntityId::generate(), EntryType::Payment, new Money(100, 'USD'), $f->sis->access->actor);
        $f->sis->saveAccount($f->account);
        $f->sis->clock->time = $f->sis->clock->time->modify('+1 day');
        $f->mode = 'up';
        $facts = $f->facts();
        $rows = $f->projections();
        $again = $f->service()->apply($id, 1);
        self::assertSame('already_applied', $again['status']);
        self::assertSame($first['source_position'], $again['source_position']);
        self::assertFalse($again['projections_changed']);
        self::assertSame($facts, $f->facts());
        self::assertSame($rows, $f->projections());
        self::assertCount(1, $f->sis->sql->rows('sis_account_projection_repairs'));
    }

    public function testHealthyPromotionOnlyAddsProtectedReceiptAndDoesNotRewriteReadRows(): void
    {
        $f = $this->f;
        $facts = $f->facts();
        $rows = $f->projections();
        $id = $f->stage();
        self::assertFalse($f->service()->apply($id)['projections_changed']);
        self::assertSame($facts, $f->facts());
        self::assertSame($rows, $f->projections());
        $receipt = $f->sis->sql->rows('sis_account_projection_repairs')[0];
        self::assertStringNotContainsString('12000', $receipt['sealed_result']);
        $f->sis->sql->update('sis_account_projection_repairs', ['completed_at' => '2000-01-01'], ['id' => $id->value]);
        $this->rejected(fn() => $f->service()->apply($id), CorruptHistoryException::class);
    }

    public static function absentProjections(): array
    {
        return [['sis_account_balances'], ['sis_account_entry_heads'], ['all']];
    }

    #[DataProvider('absentProjections')]
    public function testMissingProjectionRowsCanBeRecoveredFromGenuineEvents(string $missing): void
    {
        $f = $this->f;
        $correct = $f->projections();
        foreach ($missing === 'all' ? ['sis_account_balances', 'sis_account_entry_heads'] : [$missing] as $table) {
            $f->sis->sql->execute('DELETE FROM ' . $table . ' WHERE id = ?', [(string) $f->account->aggregateId()]);
        }
        if ($missing === 'all') {
            $f->damage();
        }
        $id = $f->stage();
        self::assertTrue($f->service()->apply($id)['projections_changed']);
        self::assertSame($correct, $f->projections());
    }

    public static function failures(): array
    {
        return [['insertion'], ['receipt'], ['ciphertext'], ['head'], ['operation'], ['identity'], ['maintenance']];
    }

    #[DataProvider('failures')]
    public function testFinalWriteBoundaryFailuresRollBackProjectionsReceiptAndAnyCoupledSourceChange(
        string $failure,
    ): void {
        $f = $this->f;
        $f->damage();
        $id = $f->stage();
        $facts = $f->facts();
        $rows = $f->projections();
        $sql = $f->sis->connection->pdo;
        $sql->sqliteCreateFunction('resume_fixture', function () use ($f): int {
            $f->mode = 'up';
            return 1;
        });
        $statement = match ($failure) {
            'insertion', 'receipt' => "SELECT RAISE(ABORT, 'Fictional private failure');",
            'ciphertext' => "UPDATE sis_history_events SET sealed_event = 'Fictional damaged private event';",
            'head' => "UPDATE sis_history_streams SET sealed_anchor = 'Fictional damaged private anchor';",
            'operation' => "UPDATE sis_history_operations SET sealed_receipt = 'Fictional damaged private receipt';",
            'identity' => "UPDATE sis_student_accounts SET currency = 'EUR';",
            'maintenance' => 'SELECT resume_fixture();',
        };
        $table = $failure === 'insertion' ? 'sis_account_entries' : 'sis_account_projection_repairs';
        $sql->exec('CREATE TRIGGER fail_recovery AFTER INSERT ON ' . $table . ' BEGIN ' . $statement . ' END');
        $this->rejected(fn() => $f->service()->apply($id));
        self::assertSame($facts, $f->facts());
        self::assertSame($rows, $f->projections());
        self::assertSame([], $f->sis->sql->rows('sis_account_projection_repairs'));
        self::assertFalse($sql->inTransaction());
    }

    public static function staleStages(): array
    {
        return [['projection'], ['journal'], ['scope'], ['expired'], ['future'], ['identity']];
    }

    #[DataProvider('staleStages')]
    public function testStaleReviewOrChangedSourcesCannotBePromoted(string $change): void
    {
        $f = $this->f;
        $f->damage();
        $id = $f->stage();
        if ($change === 'projection') {
            $f->sis->sql->update(
                'sis_account_balances',
                ['balance_minor' => 888],
                ['id' => (string) $f->account->aggregateId()]
            );
        } elseif ($change === 'journal') {
            $f->sis->sql->execute("UPDATE sis_history_events SET sealed_event = 'Fictional damaged source'");
        } elseif ($change === 'identity') {
            $f->sis->sql->update(
                'sis_student_accounts',
                ['currency' => 'EUR'],
                ['id' => (string) $f->account->aggregateId()]
            );
        } elseif ($change === 'scope') {
            $data = $f->storage->read($id)->data;
            $data['scope'] = str_repeat('a', 64);
            $f->storage->discard($id);
            $f->storage->write(new \Domain\Finance\ValueObject\AccountProjectionStage($data));
        } else {
            $f->sis->clock->time = $f->sis->clock->time->modify($change === 'expired' ? '+20 minutes' : '-1 second');
        }
        $facts = $f->facts();
        $rows = $f->projections();
        $this->rejected(fn() => $f->service()->check($id));
        $this->rejected(fn() => $f->service()->apply($id));
        self::assertSame($facts, $f->facts());
        self::assertSame($rows, $f->projections());
        self::assertSame([], $f->sis->sql->rows('sis_account_projection_repairs'));
    }

    public function testAnUnauthenticatedJournalOrExtraColumnsNeverProduceAnArtifactOrRepair(): void
    {
        $f = $this->f;
        $f->sis->sql->execute('ALTER TABLE sis_account_entries ADD COLUMN unmodelled_fact TEXT');
        $facts = $f->facts();
        $rows = $f->projections();
        $this->rejected(fn() => $f->stage(), CorruptHistoryException::class);
        self::assertSame([], glob($f->directory . '/*'));
        self::assertSame($facts, $f->facts());
        self::assertSame($rows, $f->projections());
    }

    public function testConcurrentGenuinePostingDuringStagingIsPreservedAndCreatesNoStage(): void
    {
        $f = $this->f;
        $store = \EventStoreFixture::history($f->sis->connection->queryBuilder());
        $history = $this->createStub(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $history->method('page')->willReturnCallback(function ($stream, $cursor, $limit) use ($f, $store) {
            $page = $store->page($stream, $cursor, $limit);
            $f->account->post(EntityId::generate(), EntryType::Payment, new Money(100, 'USD'), $f->sis->access->actor);
            $f->sis->saveAccount($f->account);
            return $page;
        });
        $before = count($f->facts()[0]);
        $this->rejected(
            fn() => $f->service($history)->stage($f->account->aggregateId()),
            ConcurrentModificationException::class
        );
        self::assertSame($before + 1, count($f->facts()[0]));
        self::assertSame('11900', (string) $f->projections()[0][0]['balance_minor']);
        self::assertSame([], glob($f->directory . '/*'));
    }

    public function testRecoveryPreservesCreditRefundAndReversalAmountsAndTemporalLineage(): void
    {
        $f = $this->f;
        $s = $f->sis;
        $a = $f->account;
        $charge = new EntityId($a->entries()[1]['operation_id']);
        $a->post(EntityId::generate(), EntryType::Payment, new Money(12000, 'USD'), $s->access->actor);
        $a->creditCharge($charge, EntityId::generate(), new Money(1000, 'USD'), $s->access->actor, str_repeat('b', 64));
        $a->post(EntityId::generate(), EntryType::Refund, new Money(1000, 'USD'), $s->access->actor);
        $reversed = EntityId::generate();
        $a->post($reversed, EntryType::Payment, new Money(100, 'USD'), $s->access->actor);
        $a->reverse($reversed, $s->access->actor);
        $s->saveAccount($a);
        $correct = $f->projections();
        $facts = $f->facts();
        $f->damage();
        $f->service()->apply($f->stage());
        self::assertSame($correct, $f->projections());
        self::assertSame($facts, $f->facts());
        self::assertSame('0', (string) $f->projections()[0][0]['balance_minor']);
    }

    public function testReplayCapsAndNestedTransactionsFailWithoutSideEffects(): void
    {
        $f = $this->f;
        $history = $this->createMock(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $history->expects(self::never())->method('page');
        $this->rejected(
            fn() => $f->service($history)->stage($f->account->aggregateId(), 1),
            HistoryReadLimitException::class
        );
        $this->rejected(
            fn() => $f->service()->stage($f->account->aggregateId(), 2001),
            \InvalidArgumentException::class
        );
        $this->rejected(fn() => $f->sis->transactions->run(fn() => $f->stage()), \LogicException::class);
        self::assertSame([], glob($f->directory . '/*'));
    }

    public function testMultiplePagesReconstructWithOneAllowanceAndOtherAccountsStayUntouched(): void
    {
        $f = $this->f;
        $s = $f->sis;
        for ($i = 0; $i < 51; $i++) {
            $f->account->post(EntityId::generate(), EntryType::Payment, new Money(1, 'USD'), $s->access->actor);
        }
        $s->saveAccount($f->account);
        $other = \Domain\Finance\StudentAccount::open(
            new \Domain\Finance\ValueObject\AccountId(),
            $s->student()->id,
            $s->term,
            'EUR'
        );
        $s->saveAccount($other);
        $otherRows = $s->sql->rows('sis_account_balances', ['id' => (string) $other->aggregateId()]);
        $correct = $f->projections();
        $f->damage();
        $result = $f->service()->stage($f->account->aggregateId(), $f->account->playhead());
        self::assertSame($f->account->playhead(), $result['events_checked']);
        $f->service()->apply(new EntityId($result['stage_reference']), $f->account->playhead());
        self::assertSame($correct, $f->projections());
        self::assertSame($otherRows, $s->sql->rows('sis_account_balances', ['id' => (string) $other->aggregateId()]));
    }

    public function testPromotionRequiresAvailableMaintenanceWhileStageAndCheckRemainAvailable(): void
    {
        $f = $this->f;
        $id = $f->stage();
        foreach (['up', 'unavailable'] as $mode) {
            $f->mode = $mode;
            self::assertSame('ready', $f->service()->check($id)['status']);
            $this->rejected(fn() => $f->service()->apply($id), \RuntimeException::class);
            self::assertSame([], $f->sis->sql->rows('sis_account_projection_repairs'));
        }
    }

    public function testArtifactsStayPrivateAndAuthenticatedWithExplicitIdempotentDiscard(): void
    {
        $f = $this->f;
        $id = $f->stage();
        $stage = $f->storage->read($id);
        $this->rejected(fn() => $f->storage->write($stage));
        $path = $f->directory . '/' . $id->value . '.stage';
        $copy = EntityId::generate();
        copy($path, $f->directory . '/' . $copy->value . '.stage');
        chmod($f->directory . '/' . $copy->value . '.stage', 0600);
        $this->rejected(fn() => $f->storage->read($copy));
        chmod($path, 0644);
        clearstatcache();
        $this->rejected(fn() => $f->storage->read($id));
        chmod($path, 0600);
        clearstatcache();
        file_put_contents($path, 'Fictional tampered artifact');
        $this->rejected(fn() => $f->storage->read($id));
        $f->storage->discard($id);
        $f->storage->discard($id);
        self::assertFileDoesNotExist($path);
        symlink($f->directory . '/' . $copy->value . '.stage', $path);
        $this->rejected(fn() => $f->storage->read($id));
        $this->rejected(fn() => $f->storage->discard($id));
    }
}
