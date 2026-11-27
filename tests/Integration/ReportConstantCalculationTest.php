<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ReportConstantCalculationTest extends TestCase
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

    private function definition(string $operator = '/', string $value = '2'): array
    {
        return ['dataset' => 'sections', 'columns' => [
            ['field' => 'section_code', 'label' => 'Section', 'aggregate' => 'none'],
            ['field' => 'capacity', 'label' => 'Fictional planning quantity', 'aggregate' => 'none',
                'calculation' => ['operator' => $operator, 'value' => $value]],
        ], 'sort' => [['column' => 0, 'direction' => 'asc']]];
    }

    public function testDecimalArithmeticRetainsFractionalZeroAndNegativeResultsWithoutWritingFacts(): void
    {
        $s = new SisFixture();
        $section = $s->section(25, 0);
        $before = \EventStoreFixture::historyDigest($s->sql);
        foreach ([['/', '2', 12.5], ['*', '0.125', 3.125], ['+', '-25', 0.0], ['-', '30.5', -5.5]] as $case) {
            $result = $this->service($s)->preview($this->definition($case[0], $case[1]), 0);
            self::assertSame($case[2], $result['items'][0]['c1']);
        }
        self::assertSame(25, (int) $s->sql->one('sis_sections', ['id' => $section->id->value])['capacity']);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testZeroDivisorsAndMissingRelatedInputsRemainNullIncludingSummaries(): void
    {
        $s = new SisFixture();
        $s->section(25, 0);
        foreach (['0', '-0.000000'] as $zero) {
            $d = $this->definition('/', $zero);
            self::assertNull($this->service($s)->preview($d, 0)['items'][0]['c1']);
            $d['columns'] = [$d['columns'][1]];
            $d['columns'][0]['aggregate'] = 'sum';
            self::assertNull($this->service($s)->preview($d, 0)['items'][0]['c0']);
        }
        $d = $this->definition('+', '1');
        $d['columns'][1]['field'] = 'selected_minimum_enrollment';
        self::assertNull($this->service($s)->preview($d, 0)['items'][0]['c1']);
    }

    public function testConditionalSummariesAndTypedHavingUseCalculatedRowsBeforeAggregation(): void
    {
        $s = new SisFixture();
        $s->section(10, 0);
        $s->section(25, 0);
        $d = $this->definition('*', '0.5');
        $d['columns'] = [$d['columns'][1]];
        $d['columns'][0]['aggregate'] = 'sum';
        $d['columns'][0]['conditions'] = $this->condition('capacity', 'greater', 15);
        $d['having'] = $this->condition('c0', 'greater', 12);
        self::assertSame(12.5, $this->service($s)->preview($d, 0)['items'][0]['c0']);
        $d['having'] = $this->condition('c0', 'less', 12);
        self::assertSame([], $this->service($s)->preview($d, 0)['items']);
    }

    private function condition(string $field, string $operator, mixed $value): array
    {
        return ['condition' => 'AND', 'rules' => [['id' => $field, 'operator' => $operator, 'value' => $value]]];
    }

    public function testCompilerBindsExactDecimalAfterColumnConditionsBeforeJoinAndRowPredicates(): void
    {
        $d = $this->definition('*', '-999999999.123456');
        $d['columns'][1]['aggregate'] = 'sum';
        $d['columns'][1]['conditions'] = $this->condition('capacity', 'greater', 12);
        $d['join_filters'] = ['course' => $this->condition('course_code', 'equal', 'FICTIONAL')];
        $d['filters'] = $this->condition('section_code', 'equal', 'FICTIONAL.101');
        $d['having'] = $this->condition('c1', 'less', 50);
        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci'] as $driver) {
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string =>
                match ($driver) {
                    'mysql' => '`' . $name . '`', 'sqlsrv' => '[' . $name . ']', default => '"' . $name . '"',
                });
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method('getConnection')->willReturn($connection);
            $plan = new ReportSelectCompiler(new \Infrastructure\Persistence\SqlRecords($db))->compile(
                new ReportQuery($d, AcademicReportSchema::datasets()['sections']),
            );
            self::assertSame([12, '-999999999.123456', 'FICTIONAL', 'FICTIONAL.101', 50], $plan['parameters']);
            self::assertStringNotContainsString('-999999999.123456', $plan['sql']);
            self::assertStringContainsString('CAST(? AS DECIMAL(24, 6))', $plan['sql']);
        }
    }

    public static function invalidConstants(): array
    {
        return array_map(static fn(mixed $value): array => [$value], [
            '', ' 2', '2 ', "2\n", '+2', '01', '.5', '1.', '1,5', '1e3', 'NaN', 'INF',
            '1000000000', '0.1234567', "1'); DROP TABLE sis_sections; --", 2, 0.5, null, true, [],
        ]);
    }

    #[DataProvider('invalidConstants')]
    public function testInvalidConstantsAreRejectedBeforeSql(mixed $value): void
    {
        $d = $this->definition();
        $d['columns'][1]['calculation']['value'] = $value;
        $this->expectException(ValidationException::class);
        new ReportQuery($d, AcademicReportSchema::datasets()['sections']);
    }

    public function testAmbiguousOperandsUnknownFieldsAndTextInputsCannotBypassValidation(): void
    {
        $calculations = [['field' => 'capacity', 'value' => '2', 'operator' => '+'],
            ['field' => 'missing', 'operator' => '+'], ['value' => '2', 'operator' => 'UNION'],
            ['value' => '2', 'operator' => '+', 'sql' => 'private'], ['operator' => '+']];
        foreach ($calculations as $calculation) {
            $d = $this->definition();
            $d['columns'][1]['calculation'] = $calculation;
            try {
                new ReportQuery($d, AcademicReportSchema::datasets()['sections']);
                self::fail('An untyped calculation was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $d['columns'][1]['calculation'] = ['operator' => '+', 'value' => '2'];
        $d['columns'][1]['field'] = 'section_code';
        $this->expectException(ValidationException::class);
        new ReportQuery($d, AcademicReportSchema::datasets()['sections']);
    }

    public function testConstantCannotBypassMonetaryUnitsOrRootMetricFanout(): void
    {
        $d = $this->definition('*', '2');
        $d['columns'][1]['aggregate'] = 'sum';
        $d['joins'] = ['enrollment' => 'left'];
        $account = ['dataset' => 'accounts', 'columns' => [
            ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
            ['field' => 'balance', 'label' => 'Amount', 'aggregate' => 'none',
                'calculation' => ['operator' => '*', 'value' => '2']],
        ]];
        $definitions = [$d, $account,
            array_replace_recursive($account, ['columns' => [1 => ['aggregate' => 'count']]])];
        foreach ($definitions as $definition) {
            try {
                new ReportQuery($definition, AcademicReportSchema::datasets()[$definition['dataset']]);
                self::fail('A constant bypassed quantity grain or monetary units.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSavedSharedStaticResultsKeepDefinitionAndRecheckSourceGrants(): void
    {
        $s = new SisFixture();
        $section = $s->section(25, 0);
        $service = $this->service($s);
        $id = EntityId::generate();
        $d = $this->definition('/', '2');
        $before = \EventStoreFixture::historyDigest($s->sql);
        $service->save($id, $id, -1, 'Fictional calculation', $d);
        self::assertSame($d, $service->view($id)['definition']);
        $record = $s->sql->one('sis_report_queries', ['id' => $id->value]);
        self::assertStringNotContainsString('calculation', $record['encrypted_content']);
        self::assertSame(12.5, $service->run($id, 0)['items'][0]['c1']);
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $s->sql->update('sis_sections', ['capacity' => 30], ['id' => $section->id->value]);
        self::assertSame(12.5, $service->run($id, 0)['items'][0]['c1']);
        self::assertSame(15.0, $service->preview($d, 0)['items'][0]['c1']);
        $recipient = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $recipient);
        $s->access->actor = $recipient;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:catalog:manage'];
        self::assertSame(12.5, $service->run($id, 0)['items'][0]['c1']);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        $s->access->grants = ['sis:reporting:run'];
        $this->expectException(AccessDeniedException::class);
        $service->run($id, 0);
    }

    public function testMetadataAdvertisesConstantsOnlyForAuthorizedNonmonetaryNumericFields(): void
    {
        $s = new SisFixture();
        $service = $this->service($s);
        $sections = $service->graph('sections', [])['dataset'];
        self::assertTrue($sections['fields']['capacity']['constant_calculation']);
        self::assertFalse($sections['fields']['section_code']['constant_calculation']);
        $accounts = $service->graph('accounts', [])['dataset'];
        self::assertFalse($accounts['fields']['balance']['constant_calculation']);
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:catalog:manage'];
        self::assertArrayNotHasKey('institutional_id', $service->graph('sections', [])['dataset']['fields']);
    }
}
