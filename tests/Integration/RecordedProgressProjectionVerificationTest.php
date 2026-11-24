<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordedProgressVerificationFixture;

final class RecordedProgressProjectionVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/RecordedProgressVerificationFixture.php';
    }

    public function testRecordedObservationVerifiesWithoutWritesOrCurrentPolicyRecalculation(): void
    {
        $f = new RecordedProgressVerificationFixture();
        $s = $f->sis;
        $s->sql->update('sis_students', ['active' => false], ['id' => $f->curriculum->student->value]);
        $s->sql->update('sis_student_programs', ['status' => 'closed'], ['id' => $f->curriculum->assignment->value]);
        $before = $f->facts();
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $check = $s->studentDecisionVerifier()->progressRecord($f->record, 1);
        self::assertSame('verified', $check->status);
        self::assertSame(1, $check->position);
        self::assertSame(1, $check->records);
        self::assertSame(1, $check->events);
        self::assertSame($f->record->value, $check->describe()['progress_record_reference']);
        $json = json_encode($check->describe(), JSON_THROW_ON_ERROR);
        foreach (
            ['Fictional', 'inputs', 'student_id', 'pursuit_id', 'source_hash', 'source_references',
            'components', 'snapshot', 'reason', 'GPA', 'course'] as $private
        ) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertSame($before, $f->facts());
    }

    public static function corruption(): array
    {
        return array_map(static fn (string $field): array => [$field], [
            'source_hash', 'recorded_on', 'recorded_at', 'pursuit_id', 'student_id', 'missing', 'envelope', 'head',
        ]);
    }

    #[DataProvider('corruption')]
    public function testDriftMissingRecordsAndProtectedCorruptionFailWithoutRepair(string $field): void
    {
        $f = new RecordedProgressVerificationFixture();
        $s = $f->sis;
        $criteria = ['id' => $f->record->value];
        $s->connection->pdo->exec('PRAGMA foreign_keys = OFF');
        if ($field === 'missing') {
            $s->sql->execute('DELETE FROM sis_degree_progress_records WHERE id = ?', [$f->record->value]);
        } elseif (in_array($field, ['envelope', 'head'], true)) {
            $head = $s->sql->rows('sis_history_streams', ['aggregate_id' => $f->record->value])[0];
            $s->sql->update(
                $field === 'head' ? 'sis_history_streams' : 'sis_history_events',
                $field === 'head' ? ['head_hash' => str_repeat('0', 64)] : ['sealed_event' => 'PRIVATE corrupt value'],
                $field === 'head' ? ['id' => $head['id']] : ['stream_id' => $head['id']]
            );
        } else {
            $value = match ($field) {
                'source_hash' => str_repeat('a', 64),
                'recorded_on' => '2026-02-01',
                'recorded_at' => '2026-02-01 00:00:00',
                default => EntityId::generate()->value,
            };
            $s->sql->update('sis_degree_progress_records', [$field => $value], $criteria);
        }
        $before = $f->facts();
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $s->studentDecisionVerifier()->progressRecord($f->record);
        self::assertSame('unverified', $result->status);
        self::assertSame(['progress_record'], $result->issues);
        self::assertNull($result->records);
        self::assertSame($before, $f->facts());
    }

    public function testAbsentRecordsAreNotVerifiedEmptyHistoryAndSnapshotTransactionsAreRefused(): void
    {
        $f = new RecordedProgressVerificationFixture();
        $s = $f->sis;
        $before = $f->facts();
        self::assertSame(
            ['progress_record'],
            $s->studentDecisionVerifier()->progressRecord(EntityId::generate())->issues
        );
        $s->transactions->run(function () use ($s, $f): void {
            $result = $s->studentDecisionVerifier()->progressRecord($f->record);
            self::assertSame('unverified', $result->status);
            self::assertSame(['unavailable'], $result->issues);
            self::assertNull($result->records);
        });
        self::assertSame($before, $f->facts());
    }

    public function testLimitedAndUnavailableReadsReturnNoPartialCountOrPrivateProviderError(): void
    {
        $f = new RecordedProgressVerificationFixture();
        foreach ([new HistoryReadLimitException(), new \RuntimeException('PRIVATE upstream failure')] as $error) {
            $history = $this->createStub(HistoricalEventStore::class);
            $history->method('page')->willThrowException($error);
            $result = $f->sis->studentDecisionVerifier($history)->progressRecord($f->record);
            self::assertSame($error instanceof HistoryReadLimitException ? 'limited' : 'unverified', $result->status);
            self::assertNull($result->records);
            self::assertStringNotContainsString('PRIVATE', json_encode($result->describe(), JSON_THROW_ON_ERROR));
        }
    }

    public function testConcurrentProjectionChangesDiscardAnOtherwiseMatchingObservation(): void
    {
        $f = new RecordedProgressVerificationFixture();
        $history = $this->createStub(HistoricalEventStore::class);
        $history->method('page')->willReturnCallback(function (...$args) use ($f) {
            $page = $f->curriculum->r->history->page(...$args);
            $f->sis->sql->update(
                'sis_degree_progress_records',
                ['source_hash' => str_repeat('a', 64)],
                ['id' => $f->record->value]
            );
            return $page;
        });
        $result = $f->sis->studentDecisionVerifier($history)->progressRecord($f->record);
        self::assertSame('changed', $result->status);
        self::assertNull($result->records);
        self::assertSame([], $result->issues);
    }

    public function testInvalidAllowancesFailBeforeReading(): void
    {
        $f = new RecordedProgressVerificationFixture();
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::never())->method('page');
        foreach ([0, 2001] as $limit) {
            try {
                $f->sis->studentDecisionVerifier($history)->progressRecord($f->record, $limit);
                self::fail('Invalid verification allowance accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
