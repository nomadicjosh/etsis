<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Admissions\ValueObject\AdmissionDecisionId;
use Domain\Admissions\ValueObject\SubmissionFact;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\EventSourcing\SubmissionProjectionUpgrade;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Persistence\Repository\SqlSubmissionProjection;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class SubmissionReportingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function service(SisFixture $s): RelationalReportService
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
            $s->reportLifecycles(),
        );
    }

    private function submit(SisFixture $s): EntityId
    {
        $person = $s->legacyPerson($s->user(), 'Fictional', 'Applicant')->id;
        return $s->submissionService()->apply(EntityId::generate(), $person, $s->program, $s->term)->id;
    }

    private function definition(): array
    {
        return ['dataset' => 'applications', 'columns' => array_map(
            static fn(string $field): array => ['field' => $field, 'label' => $field, 'aggregate' => 'none'],
            ['application_id', 'submitted_on', 'submission_recorded_on', 'submission_timezone', 'submission_channel'],
        )];
    }

    private function codec(SisFixture $s): ProtectedEventCodec
    {
        return new ProtectedEventCodec(\EventStoreFixture::config($s->connection->queryBuilder()));
    }

    private function upgrade(SisFixture $s): SubmissionProjectionUpgrade
    {
        return new SubmissionProjectionUpgrade($s->connection->queryBuilder(), $this->codec($s));
    }

    public function testLocalSubmissionDateAndUtcRecordedDateRemainDistinctAcrossMidnightAndRetries(): void
    {
        $s = new SisFixture();
        $s->clock->time = new DateTimeImmutable('2026-08-10 23:30:00 America/Los_Angeles');
        $id = $this->submit($s);
        $row = $s->sql->one('sis_application_intake', ['id' => $id->value]);
        self::assertSame('2026-08-10', $row['submitted_on']);
        self::assertSame('2026-08-11', $row['recorded_on']);
        self::assertSame('America/Los_Angeles', $row['submission_timezone']);
        self::assertSame('2026-08-11T06:30:00.000000Z', $row['submitted_at']);
        self::assertSame('2026-08-11T06:30:00.000000Z', $row['recorded_at']);
        $review = $s->decisionService()->review($id, true, 'Fictional acceptance');
        $s->decisionService()->commit($id, $review['review_token']);
        $s->matriculate($id);
        $s->clock->time = $s->clock->time->modify('+2 weeks');
        $a = $s->admissions->application($id);
        $events = $s->sql->rows('sis_history_events');
        $s->submissionService()->apply($id, $a->personId, $a->programId, $a->entryTermId);
        self::assertSame($row, $s->sql->one('sis_application_intake', ['id' => $id->value]));
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertSame(
            [['c0' => $id->value, 'c1' => '2026-08-10', 'c2' => '2026-08-11',
                'c3' => 'America/Los_Angeles', 'c4' => 'direct']],
            $this->service($s)->preview($this->definition(), 0)['items'],
        );
    }

    public function testExistingApplicationsAndLaterObservationsKeepUnknownSubmissionDates(): void
    {
        $s = new SisFixture();
        $person = $s->legacyPerson($s->user(), 'Earlier', 'Applicant')->id;
        $older = $s->legacyApplication($person, $s->program, $s->term);
        $review = $s->decisionService()->review($older->id, true, 'Fictional later acceptance');
        $s->decisionService()->commit($older->id, $review['review_token']);
        $known = $this->submit($s);
        $this->upgrade($s)->run();
        $query = $this->definition();
        $query['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'submitted_on', 'operator' => 'is_null'],
        ]];
        self::assertSame(
            [['c0' => $older->id->value, 'c1' => null, 'c2' => null, 'c3' => null, 'c4' => null]],
            $this->service($s)->preview($query, 0)['items']
        );
        $query['filters']['rules'][0]['operator'] = 'is_not_null';
        self::assertSame($known->value, $this->service($s)->preview($query, 0)['items'][0]['c0']);
        $query['filters'] = null;
        $query['joins'] = ['submission' => 'inner'];
        self::assertCount(1, $this->service($s)->preview($query, 0)['items']);
    }

    public function testDateRangeAndGroupedDateSummariesUseAnIndexedProjectionWithoutReadingTheJournal(): void
    {
        $s = new SisFixture();
        $first = $this->submit($s);
        $s->clock->time = $s->clock->time->modify('+3 days');
        $this->submit($s);
        $query = $this->definition();
        $query['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'submitted_on', 'operator' => 'between', 'value' => ['2026-08-10', '2026-08-11']],
        ]];
        $typed = new ReportQuery($query, AcademicReportSchema::datasets()['applications']);
        $plan = new ReportSelectCompiler($s->sql)->compile($typed);
        self::assertStringNotContainsString('sis_history', $plan['sql']);
        $explain = $s->sql->fetch('EXPLAIN QUERY PLAN ' . $plan['sql'], $plan['parameters']);
        self::assertStringContainsString('sai_submitted', json_encode($explain, JSON_THROW_ON_ERROR));
        // A report uses its read model. Authenticated replay remains a separate write/rebuild requirement.
        $s->sql->execute('UPDATE sis_history_events SET sealed_event = ?', ['fictional unreadable event']);
        self::assertSame($first->value, $this->service($s)->preview($query, 0)['items'][0]['c0']);
        $query['filters'] = null;
        $query['columns'] = [
            ['field' => 'submission_channel', 'label' => 'Channel', 'aggregate' => 'none'],
            ['field' => 'submitted_on', 'label' => 'Latest actual submission', 'aggregate' => 'max'],
            ['field' => 'application_id', 'label' => 'Applications', 'aggregate' => 'count_distinct'],
        ];
        $query['having'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'c1', 'operator' => 'greater', 'value' => '2026-08-11'],
        ]];
        self::assertSame(
            [['c0' => 'direct', 'c1' => '2026-08-13', 'c2' => 2]],
            $this->service($s)->preview($query, 0)['items']
        );
    }

    public function testReconstructionUsesTheActualOpeningEventAndChangesNoRecordedFactsOrReceipts(): void
    {
        $s = new SisFixture();
        $id = $this->submit($s);
        $s->clock->time = $s->clock->time->modify('+1 day');
        $review = $s->decisionService()->review($id, false, 'Fictional rejection');
        $s->decisionService()->commit($id, $review['review_token']);
        $original = $s->sql->rows('sis_application_intake');
        $events = $s->sql->rows('sis_history_events');
        $heads = $s->sql->rows('sis_history_streams');
        $receipts = $s->sql->rows('sis_history_operations');
        $s->sql->execute('DELETE FROM sis_application_intake');
        $this->upgrade($s)->run();
        $this->upgrade($s)->run();
        self::assertSame($original, $s->sql->rows('sis_application_intake'));
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertSame($heads, $s->sql->rows('sis_history_streams'));
        self::assertSame($receipts, $s->sql->rows('sis_history_operations'));
    }

    public function testCorruptHistoricalEvidenceRollsBackTheWholeDerivedIndexConversion(): void
    {
        $s = new SisFixture();
        $this->submit($s);
        $this->submit($s);
        $s->sql->execute('DELETE FROM sis_application_intake');
        $heads = $s->sql->fetch('SELECT * FROM sis_history_streams ORDER BY id');
        $s->sql->execute(
            'UPDATE sis_history_events SET sealed_event = ? WHERE stream_id = ?',
            ['fictional corrupt payload', $heads[1]['id']]
        );
        try {
            $this->upgrade($s)->run();
            self::fail('Unverified history became a reportable submission date.');
        } catch (CorruptHistoryException) {
            self::assertSame([], $s->sql->rows('sis_application_intake'));
            self::assertFalse($s->connection->pdo->inTransaction());
        }
    }

    public function testMissingKeysAndProjectionDriftAreRejectedWithoutOverwritingTheEvidence(): void
    {
        $s = new SisFixture();
        $id = $this->submit($s);
        $s->sql->update('sis_application_intake', ['submitted_on' => '2020-01-01'], ['id' => $id->value]);
        try {
            new SubmissionProjectionUpgrade($s->connection->queryBuilder(), null)->run();
            self::fail('An upgrade ignored missing verification keys.');
        } catch (\LogicException) {
            self::assertSame(
                '2020-01-01',
                $s->sql->one('sis_application_intake', ['id' => $id->value])['submitted_on'],
            );
        }
        $this->expectException(CorruptHistoryException::class);
        $this->upgrade($s)->run();
    }

    public function testSharedAndFilterOnlyDateReferencesRequireCurrentAdmissionsAccess(): void
    {
        $s = new SisFixture();
        $this->submit($s);
        $query = ['dataset' => 'applications', 'columns' => [
            ['field' => 'application_id', 'label' => 'Application', 'aggregate' => 'none'],
        ], 'filters' => ['condition' => 'AND', 'rules' => [
            ['id' => 'submitted_on', 'operator' => 'equal', 'value' => '2026-08-10'],
        ]]];
        $service = $this->service($s);
        $report = EntityId::generate();
        $service->save($report, $report, -1, 'Fictional intake', $query);
        $recipient = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($report, $recipient);
        $s->access->actor = $recipient;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:admissions:manage'];
        self::assertCount(1, $service->run($report, 0)['items']);
        $s->access->grants = ['sis:reporting:run', 'sis:student:read'];
        $this->expectException(AccessDeniedException::class);
        $service->run($report, 0);
    }

    public function testPublicMetadataExposesOnlyApprovedSubmissionFields(): void
    {
        $s = new SisFixture();
        $datasets = $this->service($s)->catalog()['datasets'];
        $source = array_values(array_filter($datasets, static fn(array $d): bool => $d['id'] === 'applications'))[0];
        self::assertSame('date', $source['fields']['submitted_on']['type']);
        self::assertSame('Recorded submissions', $source['relationships']['submission']['label']);
        $json = json_encode($source, JSON_THROW_ON_ERROR);
        $excluded = ['source_event_id', 'submitted_at', 'encrypted', 'source_event', 'sis_application_intake'];
        foreach ($excluded as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testProjectorIsIdempotentAndRejectsObservationsAsSubmissionFacts(): void
    {
        $s = new SisFixture();
        $id = $this->submit($s);
        $history = new SqlHistoricalEventStore(
            $s->sql,
            $s->transactions,
            $s->clock,
            $this->codec($s),
            new HistoricalSchemaRegistry()
        );
        $event = $history->page(new StreamReference(new AdmissionDecisionId($id->value), 'admission_decision'), null, 1)
            ->events[0];
        $before = $s->sql->rows('sis_application_intake');
        new SqlSubmissionProjection($s->sql)->project(SubmissionFact::fromEvent($event));
        self::assertSame($before, $s->sql->rows('sis_application_intake'));
        $person = $s->legacyPerson($s->user(), 'Earlier', 'Applicant')->id;
        $older = $s->legacyApplication($person, $s->program, $s->term);
        $review = $s->decisionService()->review($older->id, true, 'Fictional later acceptance');
        $s->decisionService()->commit($older->id, $review['review_token']);
        $observation = $history->page(
            new StreamReference(new AdmissionDecisionId($older->id->value), 'admission_decision'),
            null,
            1
        )->events[0];
        $this->expectException(CorruptHistoryException::class);
        SubmissionFact::fromEvent($observation);
    }

    public function testMigrationRebuildsActualRecordedDatesAndCanSafelyResumeAfterSchemaCreation(): void
    {
        $s = new SisFixture();
        $this->submit($s);
        $before = $s->sql->rows('sis_application_intake');
        $events = $s->sql->rows('sis_history_events');
        $s->sql->execute('DROP TABLE sis_application_intake');
        $migration = new \CreateSisApplicationIntake('20261114000003');
        $migration->setObjectMap(new \ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                $s->connection,
                'test_migrations',
            ),
            'sis.history_codec' => $this->codec($s),
        ]));
        $migration->up();
        $migration->up();
        self::assertSame($before, $s->sql->rows('sis_application_intake'));
        self::assertSame($events, $s->sql->rows('sis_history_events'));
    }

    public function testSubmissionSchemaAndDateReportsCompileForTheFiveSupportedDialects(): void
    {
        $s = new SisFixture();
        $table = null;
        $schema = $this->createMock(\Qubus\Expressive\Schema::class);
        $schema->method('hasTable')->willReturn(false);
        $schema->expects(self::once())->method('create')->willReturnCallback(
            static function (string $name, callable $callback) use (&$table): void {
                $table = new \Qubus\Expressive\Schema\CreateTable($name);
                $callback($table);
            }
        );
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('queryBuilder')->willReturn($s->connection->queryBuilder());
        $migration = new \CreateSisApplicationIntake('submission');
        $migration->setObjectMap(new \ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'migration'),
        ]));
        $migration->up();
        self::assertNotNull($table);
        $definition = $this->definition();
        $definition['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'submitted_on', 'operator' => 'between', 'value' => ['2026-08-01', '2026-08-31']],
        ]];
        $typed = new ReportQuery($definition, AcademicReportSchema::datasets()['applications']);
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $driver) {
            $dialect = $this->createStub(\Qubus\Expressive\Connection::class);
            $dialect->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $ddl = json_encode(new $compiler($dialect)->create($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('sai_submitted', $ddl, $driver);
            self::assertStringContainsString('sai_application_fk', $ddl, $driver);
            self::assertStringNotContainsString('AUTO_INCREMENT', strtoupper($ddl));
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method('getConnection')->willReturn($dialect);
            $plan = new ReportSelectCompiler(new \Infrastructure\Persistence\SqlRecords($db))->compile($typed);
            self::assertStringContainsString('sis_application_intake', $plan['sql']);
            self::assertSame(['2026-08-01', '2026-08-31'], $plan['parameters']);
        }
    }

    public function testStaticSubmissionReportsRetainTheirCapturedDatesAndRecheckSourceAccess(): void
    {
        $s = new SisFixture();
        $this->submit($s);
        $person = $s->legacyPerson($s->user(), 'Earlier', 'Applicant')->id;
        $s->legacyApplication($person, $s->program, $s->term);
        $service = $this->service($s);
        $report = EntityId::generate();
        $service->save($report, $report, -1, 'Fictional fixed intake', $this->definition());
        $service->configureReport($report, EntityId::generate(), 0, 'static', null);
        $captured = $service->run($report, 0)['items'];
        self::assertCount(2, $captured);
        self::assertContains(null, array_column($captured, 'c1'));
        $s->clock->time = $s->clock->time->modify('+3 days');
        $this->submit($s);
        self::assertCount(3, $service->preview($this->definition(), 0)['items']);
        self::assertSame($captured, $service->run($report, 0)['items']);
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run'];
        $this->expectException(AccessDeniedException::class);
        $service->run($report, 0);
    }
}
