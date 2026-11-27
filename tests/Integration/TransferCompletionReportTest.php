<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;
use TransferAwardFixture;

final class TransferCompletionReportTest extends TestCase
{
    private function fixture(): TransferAwardFixture
    {
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
        $h = new \HiatusFixture();
        return new TransferAwardFixture($h->sis, $h->cipher);
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
            $s->reportLifecycles()
        );
    }

    private function definition(): array
    {
        return ['dataset' => 'credits', 'columns' => [
            ['field' => 'credit_id', 'label' => 'Credit', 'aggregate' => 'none'],
            ['field' => 'transfer_completed_on', 'label' => 'Current completion', 'aggregate' => 'none'],
            ['field' => 'original_transfer_completed_on', 'label' => 'Original completion', 'aggregate' => 'none'],
        ]];
    }

    private function correct(TransferAwardFixture $t, string $date = '2026-06-12'): void
    {
        $review = $t->periods()->review(
            $t->student,
            $t->award,
            '2026-01-12',
            0,
            'Fictional verified transcript dates.',
            $date,
            '2026-08-10'
        );
        $t->periods()->commit($t->student, $t->award, $review['review_token']);
    }

    /** Additional fictional read-model rows only; no historical events are invented. */
    private function projection(TransferAwardFixture $t, string $original, ?string $corrected = null): EntityId
    {
        $s = $t->sis;
        $credit = EntityId::generate();
        $award = EntityId::generate();
        $equivalency = $s->sql->one('sis_transfer_equivalencies', ['id' => $t->equivalency->id->value]);
        $external = $s->sql->one('sis_external_courses', ['id' => $equivalency['external_course_id']]);
        $external['id'] = EntityId::generate()->value;
        $external['code'] = 'EXT.' . substr($external['id'], -8);
        $s->sql->insert('sis_external_courses', $external);
        $equivalency['id'] = EntityId::generate()->value;
        $equivalency['external_course_id'] = $external['id'];
        $s->sql->insert('sis_transfer_equivalencies', $equivalency);
        $s->sql->insert('sis_academic_credits', array_replace(
            $s->sql->one('sis_academic_credits', ['id' => $t->credit->value]),
            ['id' => $credit->value, 'source_id' => $award->value],
        ));
        $s->sql->insert('sis_transfer_awards', array_replace(
            $s->sql->one('sis_transfer_awards', ['id' => $t->award->value]),
            ['id' => $award->value, 'credit_id' => $credit->value, 'completed_on' => $original,
                'corrected_completed_on' => $corrected, 'equivalency_id' => $equivalency['id']],
        ));
        return $credit;
    }

    public function testActualCorrectionUpdatesLiveDatesAndKeepsOriginalDateAndStaticCapture(): void
    {
        $t = $this->fixture();
        $s = $t->sis;
        $service = $this->service($s);
        $definition = $this->definition();
        $before = $s->sql->rows('sis_history_events');
        self::assertSame(
            [['c0' => $t->credit->value, 'c1' => '2026-05-01', 'c2' => '2026-05-01']],
            $service->preview($definition, 0)['items']
        );
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional transfer completion dates', $definition);
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $this->correct($t);
        $afterCorrection = $s->sql->rows('sis_history_events');
        self::assertSame('2026-06-12', $service->preview($definition, 0)['items'][0]['c1']);
        self::assertSame('2026-05-01', $service->preview($definition, 0)['items'][0]['c2']);
        self::assertSame('2026-05-01', $service->run($id, 0)['items'][0]['c1']);
        self::assertSame($afterCorrection, $s->sql->rows('sis_history_events'));
        self::assertSame('date', $service->graph('credits', [])['dataset']['fields']['transfer_completed_on']['type']);
        $capture = new \Infrastructure\Reporting\SqlReportExportCapture(
            new \Infrastructure\Reporting\SqlReportResultReader(
                $s->connection->queryBuilder(),
                new SqlRelationalReportSource($s->sql)
            ),
            new \Domain\Reporting\Service\ReportSnapshotReader(
                new \Infrastructure\Persistence\Repository\SqlReportLifecycleRepository($s->sql),
                $s->dropCipher
            ),
            $s->dropCipher,
        );
        $query = new ReportQuery($definition, AcademicReportSchema::datasets()['credits']);
        foreach ([false, true] as $static) {
            $artifact = $capture->capture(EntityId::generate(), $id, $query, $static
                ? new \Infrastructure\Persistence\Repository\SqlReportLifecycleRepository($s->sql)->get($id)
                : null);
            $packet = json_decode($s->dropCipher->open($artifact->chunks[1]), true, flags: JSON_THROW_ON_ERROR);
            self::assertStringContainsString('2026-05-01', $packet['content']);
            self::assertSame(!$static, str_contains($packet['content'], '2026-06-12'));
        }
        $recipient = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $recipient);
        $s->access->actor = $recipient;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:student:read'];
        self::assertSame('2026-05-01', $service->run($id, 0)['items'][0]['c1']);
        $s->access->grants = ['sis:reporting:run'];
        $this->expectException(AccessDeniedException::class);
        $service->run($id, 0);
    }

    public function testNullDatesRemainUnknownAndNeverBorrowAnInstitutionalOrAnotherStudentsAward(): void
    {
        foreach (['institutional', 'student', 'credit', 'source'] as $mismatch) {
            $t = $this->fixture();
            $s = $t->sis;
            $changes = match ($mismatch) {
                'institutional' => ['credit_type' => 'institutional'],
                'student' => ['student_id' => $s->student()->id->value],
                'credit' => ['credit_id' => $this->projection($t, '2026-05-02')->value],
                'source' => ['source_id' => EntityId::generate()->value],
            };
            // Deliberately mismatched fictional source projections must not disclose a date.
            $s->sql->update(in_array($mismatch, ['institutional', 'source'], true) ? 'sis_academic_credits' :
                'sis_transfer_awards', $changes, ['id' => in_array($mismatch, ['institutional', 'source'], true)
                    ? $t->credit->value : $t->award->value]);
            $definition = $this->definition();
            $definition['filters'] = ['condition' => 'AND', 'rules' => [
                ['id' => 'credit_id', 'operator' => 'equal', 'value' => $t->credit->value],
            ]];
            $rows = $this->service($s)->preview($definition, 0)['items'];
            self::assertNull($rows[0]['c1'], $mismatch);
            self::assertNull($rows[0]['c2'], $mismatch);
        }
    }

    public function testCorrectedCalendarPeriodsUseDateFiltersAndDistinctCreditGrain(): void
    {
        $t = $this->fixture();
        $s = $t->sis;
        $this->correct($t);
        $this->projection($t, '2025-06-02');
        $this->projection($t, '2026-06-02');
        $section = $s->section(fee: 0);
        $s->service->register($t->student, $section->id);
        $definition = ['dataset' => 'credits', 'columns' => [
            ['field' => 'transfer_completed_on', 'label' => 'Completion month', 'aggregate' => 'none',
                'date_bucket' => 'month'],
            ['field' => 'credit_id', 'label' => 'Credits', 'aggregate' => 'count_distinct'],
        ], 'sort' => [['column' => 0, 'direction' => 'asc']]];
        self::assertSame(
            [['c0' => null, 'c1' => 1], ['c0' => '2025-06', 'c1' => 1], ['c0' => '2026-06', 'c1' => 2]],
            $this->service($s)->preview($definition, 0)['items']
        );
        $definition['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'transfer_completed_on', 'operator' => 'greater_or_equal', 'value' => '2026-06-01'],
        ]];
        self::assertSame([['c0' => '2026-06', 'c1' => 2]], $this->service($s)->preview($definition, 0)['items']);
    }

    private function selected(string $ties = 'all', string $direction = 'latest'): array
    {
        return ['dataset' => 'students', 'columns' => [
            ['field' => 'student_id', 'label' => 'Student', 'aggregate' => 'none'],
            ['field' => 'academic_credit_id', 'label' => 'Credit', 'aggregate' => 'none'],
            ['field' => 'recorded_transfer_completed_on', 'label' => 'Completion', 'aggregate' => 'none'],
        ], 'record_selection' => ['academic_credit' => [
            'date_field' => 'recorded_transfer_completed_on', 'direction' => $direction, 'ties' => $ties,
        ]]];
    }

    public function testWholeRowSelectionUsesCorrectedDatesAndRetainsTiesAndUnmatchedStudents(): void
    {
        $t = $this->fixture();
        $s = $t->sis;
        $other = $this->projection($t, '2026-05-02', '2026-06-12');
        $this->correct($t);
        $earliest = $this->projection($t, '2026-04-01');
        $unmatched = $s->student()->id;
        $service = $this->service($s);
        $rows = $service->preview($this->selected(), 0)['items'];
        self::assertCount(3, $rows);
        $matching = array_values(array_filter($rows, static fn(array $r): bool => $r['c0'] === $t->student->value));
        self::assertEqualsCanonicalizing([$t->credit->value, $other->value], array_column($matching, 'c1'));
        self::assertSame(['2026-06-12', '2026-06-12'], array_column($matching, 'c2'));
        $missing = array_values(array_filter($rows, static fn(array $r): bool => $r['c0'] === $unmatched->value));
        self::assertNull($missing[0]['c1']);
        self::assertNull($missing[0]['c2']);
        self::assertCount(2, $service->preview($this->selected('one'), 0)['items']);
        $rows = $service->preview($this->selected('one', 'earliest'), 0)['items'];
        self::assertContains($earliest->value, array_column($rows, 'c1'));
    }

    public function testDateOnlyQueriesRetainIndependentStudentSourcePermission(): void
    {
        $t = $this->fixture();
        $s = $t->sis;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage'];
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->preview($this->definition(), 0);
    }

    public function testNamedCopiesChooseIndependentlyAndCandidateFiltersRunBeforeSelection(): void
    {
        $t = $this->fixture();
        $s = $t->sis;
        $this->correct($t);
        $earliest = $this->projection($t, '2026-04-01');
        $definition = $this->selected('one');
        $definition['aliases'] = ['copy1' => ['relation' => 'academic_credit', 'label' => 'Earlier work']];
        $definition['columns'][] = ['field' => 'copy1__academic_credit_id', 'label' => 'Earlier credit',
            'aggregate' => 'none'];
        $definition['record_selection']['copy1'] = ['date_field' => 'copy1__recorded_transfer_completed_on',
            'direction' => 'earliest', 'ties' => 'one'];
        $row = $this->service($s)->preview($definition, 0)['items'][0];
        self::assertSame($t->credit->value, $row['c1']);
        self::assertSame($earliest->value, $row['c3']);
        $s->sql->update('sis_academic_credits', ['status' => 'V'], ['id' => $t->credit->value]);
        $definition = $this->selected('one');
        $definition['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'recorded_credit_status', 'operator' => 'equal', 'value' => 'A'],
        ]];
        self::assertSame([], $this->service($s)->preview($definition, 0)['items']);
        unset($definition['filters']);
        $definition['join_filters'] = ['academic_credit' => ['condition' => 'AND', 'rules' => [
            ['id' => 'recorded_credit_status', 'operator' => 'equal', 'value' => 'A'],
        ]]];
        self::assertSame($earliest->value, $this->service($s)->preview($definition, 0)['items'][0]['c1']);
    }

    public function testCalendarAndSourceJoinsCompileForEverySupportedDialectWithBoundDateFilters(): void
    {
        $t = $this->fixture();
        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci'] as $driver) {
            $pdo = $this->createStub(\PDO::class);
            $pdo->method('getAttribute')->willReturn($driver);
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method(\PHPUnit\Framework\MockObject\Runtime\PropertyHook::get('pdo'))->willReturn($pdo);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string =>
                match ($driver) {
                    'mysql' => '`' . $name . '`',
                    'sqlsrv' => '[' . $name . ']',
                    default => '"' . $name . '"',
                });
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method('getConnection')->willReturn($connection);
            $definition = $this->selected('one');
            $definition['filters'] = ['condition' => 'AND', 'rules' => [
                ['id' => 'recorded_transfer_completed_on', 'operator' => 'greater_or_equal', 'value' => '2026-01-01'],
            ]];
            $plan = new ReportSelectCompiler(new \Infrastructure\Persistence\SqlRecords($db))->compile(
                new ReportQuery($definition, AcademicReportSchema::datasets()['students']),
            );
            self::assertSame(['2026-01-01'], $plan['parameters']);
            self::assertStringNotContainsString('2026-01-01', $plan['sql']);
            self::assertStringContainsString('COALESCE', $plan['sql']);
            self::assertStringContainsString('NOT EXISTS', $plan['sql']);
            self::assertStringContainsString("= 'transfer'", $plan['sql']);
            $definition = ['dataset' => 'credits', 'columns' => [
                ['field' => 'transfer_completed_on', 'label' => 'Month', 'aggregate' => 'none',
                    'date_bucket' => 'month'],
                ['field' => 'credit_id', 'label' => 'Credits', 'aggregate' => 'count_distinct'],
            ]];
            $plan = new ReportSelectCompiler(new \Infrastructure\Persistence\SqlRecords($db))->compile(
                new ReportQuery($definition, AcademicReportSchema::datasets()['credits']),
            );
            self::assertStringContainsString('COALESCE', $plan['sql']);
            self::assertStringContainsString('GROUP BY', $plan['sql']);
            self::assertStringNotContainsString('TO_CHAR', $plan['sql']);
        }
    }

    public function testSourceMetadataAndHiddenApplicationDateFieldsKeepIndependentGrants(): void
    {
        $t = $this->fixture();
        $s = $t->sis;
        $metadata = $this->service($s)->graph('students', [])['dataset'];
        $dates = $metadata['relationships']['academic_credit']['date_fields'];
        self::assertArrayHasKey('recorded_transfer_completed_on', $dates);
        self::assertArrayHasKey('original_recorded_transfer_completed_on', $dates);
        self::assertSame('recorded_term_starts_on', array_key_first($dates));
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:admissions:manage'];
        $metadata = $this->service($s)->graph('applications', [])['dataset'];
        self::assertArrayNotHasKey('recorded_transfer_completed_on', $metadata['fields']);
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->preview(['dataset' => 'applications', 'columns' => [
            ['field' => 'recorded_transfer_completed_on', 'label' => 'Completion', 'aggregate' => 'none'],
        ]], 0);
    }
}
