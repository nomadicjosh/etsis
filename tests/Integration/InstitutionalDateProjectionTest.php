<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\AcademicRecord\Repository\AcademicDateReconciliation;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\Repository\SqlAcademicCreditRepository;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Projection\SqlAcademicDateProjection;
use Infrastructure\Projection\SqlAcademicDateReconciliation;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class InstitutionalDateProjectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function fixture(): array
    {
        $s = new SisFixture();
        $s->gradeScale([['code' => 'A', 'min_score_milli' => 0, 'max_score_milli' => 100000,
            'points_milli' => 4000, 'passes' => true, 'counts_gpa' => true]]);
        $student = $s->student()->id;
        $section = $s->section(fee: 0, faculty: $s->access->actor)->id;
        $enrollment = $s->service->register($student, $section)->id;
        $grade = $s->academicCredits()->choices($student, $section, $enrollment)['items'][0]['id'];
        $review = $s->academicCredits()->review($student, $section, $enrollment, new EntityId($grade), 0);
        $s->academicCredits()->commit($student, $section, $review['review_token']);
        $credit = new EntityId($s->sql->one('sis_academic_credits', ['source_id' => $enrollment->value])['id']);
        return [$s, $student, $section, $enrollment, $credit];
    }

    private function projection(SisFixture $s): SqlAcademicDateProjection
    {
        return new SqlAcademicDateProjection($s->sql, new ProtectedEventCodec(\EventStoreFixture::config(
            $s->connection->queryBuilder()
        )));
    }

    private function reconciler(
        SisFixture $s,
        ?\Domain\Shared\EventSourcing\HistoricalEventStore $history = null,
    ): AcademicDateReconciliation {
        return new SqlAcademicDateReconciliation(
            $s->sql,
            $history ?? \EventStoreFixture::history($s->connection->queryBuilder()),
            new SqlAcademicCreditRepository($s->sql),
            $this->projection($s),
            $s->transactions
        );
    }

    private function report(SisFixture $s): RelationalReportService
    {
        return new RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            new SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles()
        );
    }

    private function definition(): array
    {
        return ['dataset' => 'credits', 'columns' => [
            ['field' => 'credit_id', 'label' => 'Credit', 'aggregate' => 'none'],
            ['field' => 'institutional_completed_on', 'label' => 'Completion', 'aggregate' => 'none'],
            ['field' => 'institutional_academic_on', 'label' => 'Source start', 'aggregate' => 'none'],
        ]];
    }

    private function completion(array $fixture, ?string $date = '2026-07-15', int $version = 1): array
    {
        [$s, $student, $section, $enrollment] = $fixture;
        $review = $s->academicDates()->review(
            $student,
            $section,
            $enrollment,
            $date,
            $version,
            'Fictional verified academic completion.'
        );
        return $s->academicDates()->commit($student, $section, $review['review_token']);
    }

    public function testRealDateEventsAdvanceTheSealedProjectionWhileGradesDoNotInventCompletion(): void
    {
        $f = $this->fixture();
        [$s, , , , $credit] = $f;
        $row = $this->projection($s)->verified($this->projection($s)->current($credit));
        self::assertNull($row['academic_on']);
        self::assertNull($row['completed_on']);
        self::assertSame(2, $row['position']);
        $finance = $s->financialEvents();
        $this->completion($f);
        $row = $this->projection($s)->verified($this->projection($s)->current($credit));
        self::assertSame('2026-07-15', $row['completed_on']);
        self::assertSame(3, $row['position']);
        self::assertSame('2026-07-15', $this->report($s)->preview($this->definition(), 0)['items'][0]['c1']);
        $this->completion($f, null, 2);
        self::assertNull($this->report($s)->preview($this->definition(), 0)['items'][0]['c1']);
        self::assertSame($finance, $s->financialEvents());
    }

    public function testMissingIndexBlocksDependentReportsAndReconciliationPreservesBusinessFacts(): void
    {
        $f = $this->fixture();
        [$s, , , , $credit] = $f;
        $this->completion($f);
        $s->sql->execute('DELETE FROM sis_academic_date_index WHERE id = ?', [$credit->value]);
        $events = $s->sql->rows('sis_history_events');
        $facts = $s->sql->rows('sis_academic_credits');
        $service = $this->report($s);
        try {
            $service->preview($this->definition(), 0);
            self::fail('A missing known date index returned a partial report.');
        } catch (ValidationException $e) {
            self::assertSame('report', $e->errors[0]['field']);
        }
        self::assertCount(1, $service->preview(['dataset' => 'credits', 'columns' => [
            ['field' => 'credit_id', 'label' => 'Credit', 'aggregate' => 'none'],
        ]], 0)['items']);
        self::assertSame('needs_reconciliation', $this->reconciler($s)->run($credit, null, false)['status']);
        self::assertNull($this->projection($s)->current($credit));
        self::assertSame('applied', $this->reconciler($s)->run($credit, null, true)['status']);
        self::assertSame('ready', $this->reconciler($s)->run($credit, null, true)['status']);
        self::assertSame('2026-07-15', $service->preview($this->definition(), 0)['items'][0]['c1']);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertSame($facts, $s->sql->rows('sis_academic_credits'));
    }

    public function testTamperedCheckpointBlocksNewWritesAndDeliberateRepairUsesVerifiedHistory(): void
    {
        $f = $this->fixture();
        [$s, , , , $credit] = $f;
        $s->sql->update('sis_academic_date_index', ['completed_on' => '2026-01-01'], ['id' => $credit->value]);
        $before = count($s->sql->rows('sis_history_events'));
        try {
            $this->completion($f);
            self::fail('A changed date checkpoint advanced.');
        } catch (CorruptHistoryException) {
            self::assertCount($before, $s->sql->rows('sis_history_events'));
        }
        self::assertSame('applied', $this->reconciler($s)->run($credit, null, true)['status']);
        self::assertNull($this->projection($s)->verified($this->projection($s)->current($credit))['completed_on']);
        $this->completion($f);
        self::assertSame('ready', $this->reconciler($s)->run($credit, null, false)['status']);
    }

    public function testLimitedReplayCannotInstallPartialDatesOrRepairAnUnrelatedDamagedAcademicProjection(): void
    {
        [$s, , , , $credit] = $this->fixture();
        $s->sql->execute('DELETE FROM sis_academic_date_index WHERE id = ?', [$credit->value]);
        try {
            $this->reconciler($s)->run($credit, null, true, 1);
            self::fail('A partial replay installed a date index.');
        } catch (HistoryReadLimitException) {
            self::assertNull($this->projection($s)->current($credit));
        }
        $s->sql->update('sis_academic_credits', ['attempted_milli' => 1000], ['id' => $credit->value]);
        try {
            $this->reconciler($s)->run($credit, null, true);
            self::fail('Unrelated damaged academic records were silently repaired.');
        } catch (CorruptHistoryException) {
            self::assertNull($this->projection($s)->current($credit));
            self::assertSame(
                1000,
                (int) $s->sql->one('sis_academic_credits', ['id' => $credit->value])['attempted_milli'],
            );
        }
    }
    public function testCombinedDatesAdvanceTogetherAndRequiredAuditFailureRollsBackTheIndex(): void
    {
        [$s, $student, $section, $enrollment, $credit] = $this->fixture();
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new \RuntimeException('Fictional required audit failure.');
            }

            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $service = $s->academicDates($audit);
        $review = $service->reviewChronology(
            $student,
            $section,
            $enrollment,
            '2026-01-12',
            '2026-06-12',
            1,
            'Fictional chronology evidence.'
        );
        $tables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations',
            'sis_academic_credits', 'sis_academic_date_index'];
        $before = array_map($s->sql->rows(...), $tables);
        try {
            $service->commitChronology($student, $section, $review['review_token']);
            self::fail('A required audit failure partially installed dates.');
        } catch (\RuntimeException) {
            self::assertSame($before, array_map($s->sql->rows(...), $tables));
        }
        $service = $s->academicDates();
        $receipt = $service->commitChronology($student, $section, $review['review_token']);
        $row = $this->projection($s)->verified($this->projection($s)->current($credit));
        self::assertSame('2026-01-12', $row['academic_on']);
        self::assertSame('2026-06-12', $row['completed_on']);
        self::assertSame(4, $row['position']);
        self::assertSame('ready', $this->reconciler($s)->run($credit, null, false)['status']);
        $s->sql->execute('DELETE FROM sis_academic_date_index WHERE id = ?', [$credit->value]);
        self::assertSame($receipt, $service->commitChronology($student, $section, $review['review_token']));
        self::assertNull($this->projection($s)->current($credit), 'An original retry must never repair projections.');
    }

    public function testUntrackedOlderWorkStaysUnknownAndCursorInspectsOnlyOneStream(): void
    {
        [$s, $student, , , $credit] = $this->fixture();
        $older = array_replace(
            $s->sql->one('sis_academic_credits', ['id' => $credit->value]),
            ['id' => EntityId::generate()->value, 'source_id' => EntityId::generate()->value]
        );
        $s->sql->insert('sis_academic_credits', $older);
        $events = $s->sql->rows('sis_history_events');
        self::assertCount(2, $this->report($s)->preview($this->definition(), 0)['items']);
        self::assertSame([null, null], array_column($this->report($s)->preview($this->definition(), 0)['items'], 'c1'));
        self::assertSame('not_tracked', $this->reconciler($s)->run(new EntityId($older['id']), null, true)['status']);
        self::assertNull($this->projection($s)->current(new EntityId($older['id'])));
        $enrollment = $s->service->register($student, $s->section(fee: 0)->id)->id;
        $second = $s->sql->one('sis_academic_credits', ['source_id' => $enrollment->value])['id'];
        $first = $this->reconciler($s)->run(null, null, false);
        $next = $this->reconciler($s)->run(null, new EntityId($first['next_after']), false);
        self::assertSame([$credit->value, $second], [$first['credit_reference'], $next['credit_reference']]);
        self::assertNull($next['next_after']);
        self::assertSame('empty', $this->reconciler($s)->run(null, new EntityId($second), true)['status']);
        foreach ($events as $event) {
            self::assertSame($event, $s->sql->one('sis_history_events', ['id' => $event['id']]));
        }
    }

    public function testStaticResultsRetainAcceptedDatesAfterCorrectionAndMissingLiveProjection(): void
    {
        $f = $this->fixture();
        [$s, , , , $credit] = $f;
        $this->completion($f);
        $report = $this->report($s);
        $id = EntityId::generate();
        $report->save($id, $id, -1, 'Fictional academic chronology', $this->definition());
        $report->configureReport($id, EntityId::generate(), 0, 'static', null);
        $this->completion($f, '2026-08-01', 2);
        self::assertSame('2026-08-01', $report->preview($this->definition(), 0)['items'][0]['c1']);
        $s->sql->execute('DELETE FROM sis_academic_date_index WHERE id = ?', [$credit->value]);
        self::assertSame('2026-07-15', $report->run($id, 0)['items'][0]['c1']);
        $capture = new \Infrastructure\Reporting\SqlReportExportCapture(
            new \Infrastructure\Reporting\SqlReportResultReader(
                $s->connection->queryBuilder(),
                new SqlRelationalReportSource($s->sql),
            ),
            new \Domain\Reporting\Service\ReportSnapshotReader(
                new \Infrastructure\Persistence\Repository\SqlReportLifecycleRepository($s->sql),
                $s->dropCipher,
            ),
            $s->dropCipher,
        );
        $query = new \Domain\Reporting\ValueObject\ReportQuery(
            $this->definition(),
            \Infrastructure\Reporting\AcademicReportSchema::datasets()['credits']
        );
        $artifact = $capture->capture(
            EntityId::generate(),
            $id,
            $query,
            new \Infrastructure\Persistence\Repository\SqlReportLifecycleRepository($s->sql)->get($id)
        );
        $packet = json_decode($s->dropCipher->open($artifact->chunks[1]), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('2026-07-15', $packet['content']);
        self::assertStringNotContainsString('2026-08-01', $packet['content']);
        try {
            $capture->capture(EntityId::generate(), $id, $query, null);
            self::fail('A complete live export bypassed missing date coverage.');
        } catch (ValidationException) {
            self::assertFalse($s->connection->pdo->inTransaction());
        }
        $recipient = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $recipient);
        $s->access->actor = $recipient;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:student:read'];
        self::assertSame('2026-07-15', $report->run($id, 0)['items'][0]['c1']);
        $s->access->grants = ['sis:reporting:run'];
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $report->run($id, 0);
    }

    public function testLatestWholeWorkAndMonthSummariesUseTheSameRecordedDate(): void
    {
        $f = $this->fixture();
        [$s, $student, , , $credit] = $f;
        $this->completion($f);
        $s->service->register($student, $s->section(fee: 0)->id);
        $unmatched = $s->student()->id;
        $definition = ['dataset' => 'students', 'columns' => [
            ['field' => 'student_id', 'label' => 'Student', 'aggregate' => 'none'],
            ['field' => 'academic_credit_id', 'label' => 'Credit', 'aggregate' => 'none'],
        ], 'record_selection' => ['academic_credit' => [
            'date_field' => 'recorded_institutional_completed_on', 'direction' => 'latest', 'ties' => 'one',
        ]]];
        $rows = $this->report($s)->preview($definition, 0)['items'];
        self::assertCount(2, $rows);
        self::assertSame($credit->value, array_column($rows, 'c1', 'c0')[$student->value]);
        self::assertNull(array_column($rows, 'c1', 'c0')[$unmatched->value]);
        $summary = ['dataset' => 'credits', 'columns' => [
            ['field' => 'institutional_completed_on', 'label' => 'Month', 'aggregate' => 'none',
                'date_bucket' => 'month'],
            ['field' => 'credit_id', 'label' => 'Credits', 'aggregate' => 'count_distinct'],
        ], 'filters' => ['condition' => 'AND', 'rules' => [
            ['id' => 'institutional_completed_on', 'operator' => 'greater_or_equal', 'value' => '2026-07-01'],
        ]]];
        self::assertSame([['c0' => '2026-07', 'c1' => 1]], $this->report($s)->preview($summary, 0)['items']);
        $s->sql->execute('DELETE FROM sis_academic_date_index WHERE id = ?', [$credit->value]);
        $this->expectException(ValidationException::class);
        $this->report($s)->preview($definition, 0);
    }

    public function testRemovedEarlyAttemptRetainsOnlyItsDateCheckpointAndCanBeReconciled(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $section = $s->section(fee: 0)->id;
        $enrollment = $s->service->register($student, $section)->id;
        $credit = new EntityId($s->sql->one('sis_academic_credits', ['source_id' => $enrollment->value])['id']);
        $s->service->drop($student, $section);
        self::assertSame([], $s->sql->rows('sis_academic_credits', ['id' => $credit->value]));
        $row = $this->projection($s)->verified($this->projection($s)->current($credit));
        self::assertSame(1, $row['removed']);
        self::assertNull($row['completed_on']);
        self::assertSame([], $this->report($s)->preview($this->definition(), 0)['items']);
        $s->sql->execute('DELETE FROM sis_academic_date_index WHERE id = ?', [$credit->value]);
        self::assertSame('applied', $this->reconciler($s)->run($credit, null, true)['status']);
        self::assertSame(1, $this->projection($s)->verified($this->projection($s)->current($credit))['removed']);
        self::assertSame([], $s->sql->rows('sis_academic_credits', ['id' => $credit->value]));
    }

    public function testMigrationRerunsPreserveDatesAndProtectedSourceRows(): void
    {
        $f = $this->fixture();
        [$s] = $f;
        $this->completion($f);
        $tables = ['sis_academic_date_index', 'sis_academic_credits', 'sis_history_events',
            'sis_history_streams', 'sis_history_operations'];
        $before = array_map($s->sql->rows(...), $tables);
        $migration = new \CreateSisAcademicDateIndex('20261126000005');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Infrastructure\Persistence\DatabaseMigrationAdapter($s->connection, 'migration')]));
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
        $this->expectException(\LogicException::class);
        $migration->down();
    }
    public function testKnownIdentityAndHeadConflictsNeverReturnPartialDates(): void
    {
        foreach (
            [['student_id' => EntityId::generate()->value],
            ['enrollment_id' => EntityId::generate()->value], ['position' => 1],
            ['head_hash' => str_repeat('0', 64)], ['removed' => 1]] as $damage
        ) {
            [$s, , , , $credit] = $this->fixture();
            $s->sql->update('sis_academic_date_index', $damage, ['id' => $credit->value]);
            try {
                $this->report($s)->preview($this->definition(), 0);
                self::fail('Conflicting known source dates returned a partial report.');
            } catch (ValidationException) {
                self::assertSame('applied', $this->reconciler($s)->run($credit, null, true)['status']);
                self::assertCount(1, $this->report($s)->preview($this->definition(), 0)['items']);
            }
        }
    }

    public function testFinalSourceChangeRollsBackThePromotedIndex(): void
    {
        [$s, , , , $credit] = $this->fixture();
        $s->sql->execute('DELETE FROM sis_academic_date_index WHERE id = ?', [$credit->value]);
        $before = $s->sql->rows('sis_academic_credits');
        $history = new class ($s, $credit) implements \Domain\Shared\EventSourcing\HistoricalEventStore {
            private int $calls = 0;
            private \Domain\Shared\EventSourcing\HistoricalEventStore $inner;
            public function __construct(private SisFixture $s, private EntityId $credit)
            {
                $this->inner = \EventStoreFixture::history($s->connection->queryBuilder());
            }
            public function append(
                \Domain\Shared\EventSourcing\StreamReference $stream,
                EntityId $operation,
                int $expectedVersion,
                #[\SensitiveParameter] \Domain\Shared\EventSourcing\EventContext $context,
                #[\SensitiveParameter] array $events,
            ): array {
                throw new \LogicException('Reconciliation must not append.');
            }
            public function page(
                \Domain\Shared\EventSourcing\StreamReference $stream,
                ?string $cursor = null,
                int $limit = 50,
            ): \Domain\Shared\EventSourcing\EventPage {
                return $this->inner->page($stream, $cursor, $limit);
            }
            public function latest(
                \Domain\Shared\EventSourcing\StreamReference $stream,
            ): ?\Domain\Shared\EventSourcing\StoredEvent {
                if (++$this->calls === 2) {
                    $this->s->sql->update('sis_academic_credits', ['version' => 999], ['id' => $this->credit->value]);
                }
                return $this->inner->latest($stream);
            }
            public function verifyOperation(EntityId $operation): int
            {
                return $this->inner->verifyOperation($operation);
            }
        };
        try {
            $this->reconciler($s, $history)->run($credit, null, true);
            self::fail('A final changed source left a promoted index.');
        } catch (CorruptHistoryException) {
            self::assertNull($this->projection($s)->current($credit));
            self::assertSame($before, $s->sql->rows('sis_academic_credits'));
        }
    }
}
