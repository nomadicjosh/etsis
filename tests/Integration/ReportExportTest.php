<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Reporting\Repository\ReportExportCapture;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\Service\ReportExportRetention;
use Domain\Reporting\Service\ReportExportService;
use Domain\Reporting\Service\ReportSnapshotReader;
use Domain\Reporting\ValueObject\ReportExportArtifact;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportExportRepository;
use Infrastructure\Persistence\Repository\SqlReportLifecycleRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\SqlRelationalReportSource;
use Infrastructure\Reporting\SqlReportExportCapture;
use Infrastructure\Reporting\SqlReportResultReader;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ReportExportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function builder(SisFixture $s): SqlReportExportCapture
    {
        return new SqlReportExportCapture(
            new SqlReportResultReader($s->connection->queryBuilder(), new SqlRelationalReportSource($s->sql)),
            new ReportSnapshotReader(new SqlReportLifecycleRepository($s->sql), $s->dropCipher),
            $s->dropCipher,
        );
    }

    private function service(
        SisFixture $s,
        ?ReportExportCapture $capture = null,
        ?AuditTrail $audit = null,
        ?AccessRepository $permissions = null,
    ): ReportExportService {
        if ($permissions === null) {
            $permissions = $this->createStub(AccessRepository::class);
            $permissions->method('allows')->willReturnCallback(static fn(string $actor, string $permission): bool =>
                $permission !== 'sis:reporting:admin' &&
                ($s->access->staff || in_array($permission, $s->access->grants)));
        }
        return new ReportExportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            new SqlRelationalReportSource($s->sql),
            new SqlReportLifecycleRepository($s->sql),
            new SqlReportExportRepository($s->sql),
            $capture ?? $this->builder($s),
            $s->access,
            $permissions,
            $s->transactions,
            $audit ?? $s->audit,
            $s->dropCipher,
            $s->clock
        );
    }

    private function query(): array
    {
        return ['dataset' => 'students', 'columns' => [
            ['field' => 'institutional_id', 'label' => 'Student ID', 'aggregate' => 'none'],
            ['field' => 'full_name', 'label' => '=Name', 'aggregate' => 'none'],
        ], 'joins' => [], 'filters' => null, 'having' => null, 'sort' => [], 'distinct' => false];
    }

    private function report(SisFixture $s): EntityId
    {
        $id = EntityId::generate();
        new RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            new SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles()
        )->save($id, EntityId::generate(), -1, 'Fictional export', $this->query());
        return $id;
    }

    private function history(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), ['sis_history_events', 'sis_history_streams', 'sis_history_operations']);
    }

    public function testCompleteMultiPageExportRunsOnlyInWorkerAndKeepsResultsEncrypted(): void
    {
        $s = new SisFixture();
        for ($i = 0; $i < 53; $i++) {
            $s->student();
        }
        $report = $this->report($s);
        $service = $this->service($s);
        $person = $s->sql->rows('sis_persons', [], 1)[0];
        $s->sql->update('sis_persons', ['given_name' => '=1+1'], ['id' => $person['id']]);
        $history = $this->history($s);
        $operation = EntityId::generate();
        $job = $service->request($report, $operation, 0);
        self::assertSame('queued', $job['status']);
        self::assertCount(0, $s->sql->rows('sis_report_export_chunks'));
        self::assertSame($job, $service->request($report, $operation, 0));
        self::assertSame($job, $service->request($report, EntityId::generate(), 0));
        self::assertSame(['examined' => 1, 'ready' => 0, 'failed' => 0, 'apply' => false], $service->work());
        self::assertSame('queued', $service->latest($report)['status']);
        self::assertSame(1, $service->work(true)['ready']);
        self::assertSame(53, $service->latest($report)['rows']);
        self::assertSame('ready', $service->latest($report)['status']);
        $download = $service->download($report, $operation);
        $csv = implode('', $download->chunks);
        self::assertSame(54, substr_count($csv, "\n"));
        self::assertStringContainsString("'=Name", $csv);
        self::assertStringContainsString("'=1+1 Example", $csv);
        self::assertStringContainsString('Alex Example', $csv);
        self::assertSame(strlen($csv), $download->bytes);
        self::assertStringNotContainsString('Alex Example', json_encode($s->sql->rows('sis_report_export_chunks')));
        self::assertStringNotContainsString('Fictional export', json_encode($s->sql->rows('sis_report_exports')));
        self::assertStringNotContainsString('Alex Example', json_encode($download));
        self::assertSame($history, $this->history($s));
    }

    public function testStaticExportUsesRetainedResultsAfterLiveRecordsChange(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $report = $this->report($s);
        $s->reportLifecycles()->configure($report, EntityId::generate(), 0, 'static', null);
        $s->sql->update('sis_persons', ['given_name' => 'Changed current name'], ['id' => $student->personId->value]);
        $s->student();
        $service = $this->service($s);
        $job = EntityId::generate();
        $service->request($report, $job, 1);
        self::assertSame(1, $service->work(true)['ready']);
        $csv = implode('', $service->download($report, $job)->chunks);
        self::assertStringContainsString('Alex Example', $csv);
        self::assertStringNotContainsString('Changed current name', $csv);
        self::assertSame(2, substr_count($csv, "\n"));
    }

    public function testExportsRemainPrivateEvenWhenTheReportIsShared(): void
    {
        $s = new SisFixture();
        $s->student();
        $report = $this->report($s);
        $service = $this->service($s);
        $job = EntityId::generate();
        $service->request($report, $job, 0);
        $service->work(true);
        $other = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($report, $other);
        $s->access->actor = $other;
        self::assertNull($service->latest($report));
        foreach (['download', 'cancel'] as $method) {
            try {
                $service->$method($report, $job);
                self::fail('A shared report exposed a different account export.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
        $ownJob = EntityId::generate();
        $service->request($report, $ownJob, 0);
        self::assertSame(1, $service->work(true)['ready']);
        self::assertSame('ready', $service->latest($report)['status']);
    }

    public function testRevocationAndChangedDefinitionsPreventPublicationAndDownloads(): void
    {
        $s = new SisFixture();
        $s->student();
        $report = $this->report($s);
        $service = $this->service($s);
        $job = EntityId::generate();
        $service->request($report, $job, 0);
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run'];
        self::assertSame(1, $service->work(true)['failed']);
        self::assertSame([], $s->sql->rows('sis_report_export_chunks'));
        $s->access->staff = true;
        $next = EntityId::generate();
        $service->request($report, $next, 0);
        $s->sql->update('sis_report_queries', ['version' => 1], ['id' => $report->value]);
        self::assertSame(1, $service->work(true)['failed']);
        self::assertSame('failed', $service->latest($report)['status']);
        self::assertSame([], $s->sql->rows('sis_report_export_chunks'));
        $this->expectException(ConcurrentModificationException::class);
        $service->request($report, EntityId::generate(), 0);
    }

    public function testCancellationDuringCaptureCannotPublishOrRestoreTheDownload(): void
    {
        $s = new SisFixture();
        $s->student();
        $report = $this->report($s);
        $plain = $this->service($s);
        $job = EntityId::generate();
        $plain->request($report, $job, 0);
        $capture = $this->createStub(ReportExportCapture::class);
        $capture->method('capture')->willReturnCallback(function (
            $id,
            $r,
            $query,
            $state
        ) use (
            $s,
            $plain,
            $report,
            $job
        ) {
            $result = $this->builder($s)->capture($id, $r, $query, $state);
            $plain->cancel($report, $job);
            return $result;
        });
        self::assertSame(0, $this->service($s, $capture)->work(true)['ready']);
        self::assertSame('cancelled', $plain->latest($report)['status']);
        self::assertSame([], $s->sql->rows('sis_report_export_chunks'));
        self::assertSame('cancelled', $plain->request($report, $job, 0)['status']);
        $this->expectException(RecordNotFoundException::class);
        $plain->download($report, $job);
    }

    public function testAuthenticatedChunksCannotBeMovedAlteredOrOmitted(): void
    {
        $s = new SisFixture();
        $s->student();
        $report = $this->report($s);
        $service = $this->service($s);
        $job = EntityId::generate();
        $service->request($report, $job, 0);
        $service->work(true);
        $chunks = $s->sql->rows('sis_report_export_chunks', ['export_id' => $job->value]);
        $original = $chunks[0]['encrypted_content'];
        foreach (['fictional-invalid-private-output', $chunks[1]['encrypted_content']] as $replacement) {
            $s->sql->update(
                'sis_report_export_chunks',
                ['encrypted_content' => $replacement],
                ['id' => $chunks[0]['id']]
            );
            try {
                $service->download($report, $job);
                self::fail('A damaged export was returned.');
            } catch (\RuntimeException | \Defuse\Crypto\Exception\CryptoException) {
                self::assertTrue(true);
            }
        }
        $s->sql->update('sis_report_export_chunks', ['encrypted_content' => $original], ['id' => $chunks[0]['id']]);
        $s->sql->execute('DELETE FROM sis_report_export_chunks WHERE id = ?', [$chunks[1]['id']]);
        try {
            $service->download($report, $job);
            self::fail('An incomplete export was returned.');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }
        $service->cancel($report, $job);
        self::assertSame([], $s->sql->rows('sis_report_export_chunks'));
        $this->expectException(RecordNotFoundException::class);
        $service->download($report, $job);
    }

    public function testExpiredExportsAreUnavailableBeforeBoundedCleanupAndHistoryIsUntouched(): void
    {
        $s = new SisFixture();
        $s->student();
        $report = $this->report($s);
        $service = $this->service($s);
        $job = EntityId::generate();
        $service->request($report, $job, 0);
        $service->work(true);
        $history = $this->history($s);
        $s->clock->time = $s->clock->time->modify('+1 day');
        self::assertNull($service->latest($report));
        try {
            $service->download($report, $job);
            self::fail('An expired export was returned.');
        } catch (ConcurrentModificationException) {
            self::assertTrue(true);
        }
        $retention = new ReportExportRetention(new SqlReportExportRepository($s->sql), $s->transactions, $s->clock);
        self::assertSame(0, $retention->run()['removed']);
        self::assertCount(1, $s->sql->rows('sis_report_exports'));
        self::assertSame(1, $retention->run(true)['removed']);
        self::assertSame([], $s->sql->rows('sis_report_export_chunks'));
        self::assertSame($history, $this->history($s));
    }

    public function testFailedAuditOrRevokedAuthorityRollsBackPublicationAndDiscardsChunks(): void
    {
        $s = new SisFixture();
        $s->student();
        $report = $this->report($s);
        $service = $this->service($s);
        $job = EntityId::generate();
        $service->request($report, $job, 0);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s): void {
            $s->access->staff = false;
            $s->access->grants = [];
        });
        self::assertSame(1, $this->service($s, audit: $audit)->work(true)['failed']);
        self::assertSame([], $s->sql->rows('sis_report_export_chunks'));
        self::assertNull($s->sql->rows('sis_report_exports')[0]['encrypted_manifest']);
    }

    public function testReadTraversalRollsBackLimitsAndStaticCapturesKeepTheirExistingBounds(): void
    {
        $s = new SisFixture();
        $s->student();
        $s->student();
        $source = new SqlRelationalReportSource($s->sql);
        $query = new ReportQuery($this->query(), $source->datasets()['students']);
        try {
            $reader = new SqlReportResultReader($s->connection->queryBuilder(), $source);
            $reader->read($query, static fn() => null, 1, 5000, 5);
            self::fail('Oversized output was accepted.');
        } catch (ValidationException) {
            self::assertFalse($s->connection->pdo->inTransaction());
        }
        $builder = $this->builder($s);
        $artifact = $builder->capture(EntityId::generate(), EntityId::generate(), $query, null);
        self::assertSame(2, $artifact->rows);
        self::assertStringNotContainsString('Alex Example', json_encode($artifact));
    }

    public function testExportCanExceedStaticCaptureRowsWithoutRetainingAPlaintextResultSet(): void
    {
        $s = new SisFixture();
        $s->transactions->run(static function () use ($s): void {
            for ($i = 0; $i < 5001; $i++) {
                $s->sql->insert('sis_rooms', ['id' => EntityId::generate()->value, 'code' => 'R-' . $i,
                    'name' => 'Fictional room ' . $i, 'capacity' => 20, 'building_id' => null]);
            }
        });
        $definition = ['dataset' => 'rooms', 'columns' => [
            ['field' => 'room_code', 'label' => 'Room', 'aggregate' => 'none'],
        ], 'joins' => [], 'filters' => null, 'having' => null, 'sort' => [], 'distinct' => false];
        $source = new SqlRelationalReportSource($s->sql);
        $artifact = $this->builder($s)->capture(
            EntityId::generate(),
            EntityId::generate(),
            new ReportQuery($definition, $source->datasets()['rooms']),
            null
        );
        self::assertSame(5001, $artifact->rows);
        self::assertCount(102, $artifact->chunks);
        self::assertLessThan(100000, $artifact->bytes);
        self::assertStringNotContainsString('R-5000', implode('', $artifact->chunks));
        self::assertFalse($s->connection->pdo->inTransaction());
    }

    public function testReclaimedLeasePreventsAnEarlierWorkerFromPublishingOverItsReplacement(): void
    {
        $s = new SisFixture();
        $s->student();
        $report = $this->report($s);
        $plain = $this->service($s);
        $job = EntityId::generate();
        $plain->request($report, $job, 0);
        $capture = $this->createStub(ReportExportCapture::class);
        $capture->method('capture')->willReturnCallback(function ($id, $r, $query, $state) use ($s, $plain) {
            $artifact = $this->builder($s)->capture($id, $r, $query, $state);
            $s->clock->time = $s->clock->time->modify('+3 minutes');
            self::assertSame(1, $plain->work(true)['ready']);
            return $artifact;
        });
        self::assertSame(0, $this->service($s, $capture)->work(true)['ready']);
        self::assertSame('ready', $plain->latest($report)['status']);
        self::assertSame(2, (int) $s->sql->rows('sis_report_exports')[0]['attempts']);
        self::assertStringContainsString('Alex Example', implode('', $plain->download($report, $job)->chunks));
    }

    public function testRetentionDuringAnInterruptedCaptureCannotRestoreDeletedArtifacts(): void
    {
        $s = new SisFixture();
        $s->student();
        $report = $this->report($s);
        $plain = $this->service($s);
        $plain->request($report, EntityId::generate(), 0);
        $capture = $this->createStub(ReportExportCapture::class);
        $capture->method('capture')->willReturnCallback(function ($id, $r, $query, $state) use ($s) {
            $artifact = $this->builder($s)->capture($id, $r, $query, $state);
            $s->clock->time = $s->clock->time->modify('+1 day');
            $retention = new ReportExportRetention(new SqlReportExportRepository($s->sql), $s->transactions, $s->clock);
            self::assertSame(1, $retention->run(true)['removed']);
            return $artifact;
        });
        self::assertSame(0, $this->service($s, $capture)->work(true)['ready']);
        self::assertNull($plain->latest($report));
        self::assertSame([], $s->sql->rows('sis_report_exports'));
        self::assertSame([], $s->sql->rows('sis_report_export_chunks'));
    }

    public function testRepeatedlyInterruptedExportsStopWithoutReadingMoreSourceData(): void
    {
        $s = new SisFixture();
        $report = $this->report($s);
        $job = EntityId::generate();
        $this->service($s)->request($report, $job, 0);
        $s->sql->update('sis_report_exports', ['status' => 'running', 'attempts' => 3,
            'lease_until' => '2026-01-01T00:00:00+00:00'], ['id' => $job->value]);
        $capture = $this->createMock(ReportExportCapture::class);
        $capture->expects(self::never())->method('capture');
        $service = $this->service($s, $capture);
        self::assertSame(1, $service->work(true)['failed']);
        self::assertSame('failed', $service->latest($report)['status']);
        self::assertSame([], $s->sql->rows('sis_report_export_chunks'));
    }
    public function testHistoricalStudentExportRetainsCutoffAndEarlierBalanceAfterLaterActivity(): void
    {
        require_once dirname(__DIR__) . '/Support/HistoricalFinancialFixture.php';
        $f = new \Tests\Support\HistoricalFinancialFixture();
        $s = $f->sis;
        $account = $f->account();
        $account->post(
            EntityId::generate(),
            \Domain\Finance\Enum\EntryType::Charge,
            new \Domain\Finance\ValueObject\Money(10000, 'USD'),
            $s->access->actor
        );
        $f->save($account, '2026-08-03T10:00:00Z');
        $definition = $f->definition();
        $definition['columns'] = [
            ['field' => 'cutoff_utc', 'label' => 'Recorded cutoff UTC', 'aggregate' => 'none'],
            ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
            ['field' => 'balance_minor', 'label' => 'Balance in minor units', 'aggregate' => 'none'],
        ];
        $report = EntityId::generate();
        $f->reports()->save($report, EntityId::generate(), -1, 'Fictional historical export', $definition);
        $account->post(
            EntityId::generate(),
            \Domain\Finance\Enum\EntryType::Payment,
            new \Domain\Finance\ValueObject\Money(2500, 'USD'),
            $s->access->actor
        );
        $f->save($account, '2026-08-04T10:00:00Z');
        $service = $this->service($s);
        $job = $service->request($report, EntityId::generate(), 0);
        $events = $this->history($s);
        $service->work(true);
        $download = $service->download($report, new EntityId($job['id']));
        $csv = implode('', iterator_to_array($download->chunks));
        self::assertStringContainsString('2026-08-03 10:00:00', $csv);
        self::assertStringContainsString('USD,10000', $csv);
        self::assertStringNotContainsString('7500', $csv);
        self::assertSame($events, $this->history($s));
    }

}
