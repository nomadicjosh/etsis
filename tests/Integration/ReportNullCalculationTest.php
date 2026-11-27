<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\Service\ReportSnapshotReader;
use Domain\Reporting\ValueObject\ReportCalculation;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportLifecycleRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use Infrastructure\Reporting\SqlReportExportCapture;
use Infrastructure\Reporting\SqlReportResultReader;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ReportNullCalculationTest extends TestCase
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
            $s->reportLifecycles()
        );
    }

    private static function group(string $operator, array $left, array $right): array
    {
        return ['group' => ['operator' => $operator, 'left' => $left, 'right' => $right]];
    }

    private function definition(array $calculation, string $field = 'capacity'): array
    {
        return ['dataset' => 'sections', 'columns' => [['field' => $field, 'label' => 'Fictional numeric result',
            'aggregate' => 'none', 'calculation' => $calculation]]];
    }

    private function condition(string $field, string $operator, mixed $value): array
    {
        return ['condition' => 'AND', 'rules' => [['id' => $field, 'operator' => $operator, 'value' => $value]]];
    }

    public function testFallbackIsExplicitAndZeroNeverBecomesMissingAutomatically(): void
    {
        $s = new SisFixture();
        $s->section(25, 0);
        $service = $this->service($s);
        $before = \EventStoreFixture::historyDigest($s->sql);
        foreach (
            [
            [['operator' => '+', 'value' => '7'], 'selected_minimum_enrollment', null],
            [['operator' => 'coalesce', 'value' => '7'], 'selected_minimum_enrollment', 7.0],
            [['operator' => 'coalesce', 'field' => 'capacity'], 'selected_minimum_enrollment', 25.0],
            [['operator' => 'coalesce', 'value' => '7'], 'capacity', 25.0],
            [['operator' => 'coalesce', 'left' => ['value' => '0'], 'value' => '7'], 'capacity', 0.0],
            [['operator' => 'coalesce', 'field' => 'selected_minimum_enrollment'], 'selected_minimum_enrollment', null],
            ] as [$calculation, $field, $expected]
        ) {
            self::assertSame(
                $expected,
                $service->preview($this->definition($calculation, $field), 0)['items'][0]['c0']
            );
        }
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testBlankMatchingValueUsesNumericEqualityAndUnknownComparisonDoesNotEraseKnownValues(): void
    {
        $s = new SisFixture();
        $s->section(25, 0);
        $service = $this->service($s);
        foreach (
            [
            [['operator' => 'nullif', 'value' => '25.000000'], 'capacity', null],
            [['operator' => 'nullif', 'value' => '24.999999'], 'capacity', 25.0],
            [['operator' => 'nullif', 'field' => 'selected_minimum_enrollment'], 'capacity', 25.0],
            [['operator' => 'nullif', 'value' => '25'], 'selected_minimum_enrollment', null],
            [['operator' => 'nullif', 'left' => ['value' => '0'], 'value' => '-0.000000'], 'capacity', null],
            ] as [$calculation, $field, $expected]
        ) {
            self::assertSame(
                $expected,
                $service->preview($this->definition($calculation, $field), 0)['items'][0]['c0']
            );
        }
    }

    public function testNestedFallbackCanHandleAProtectedZeroDivisionWithoutChangingOrdinaryArithmetic(): void
    {
        $s = new SisFixture();
        $s->section(25, 0);
        $service = $this->service($s);
        $calculation = ['operator' => 'coalesce',
            'left' => self::group('/', ['field' => 'capacity'], ['value' => '0']), 'value' => '-9.5'];
        self::assertSame(-9.5, $service->preview($this->definition($calculation), 0)['items'][0]['c0']);
        $calculation = ['operator' => '+', 'left' => self::group(
            'coalesce',
            self::group('/', ['field' => 'capacity'], ['value' => '0']),
            ['value' => '-9.5']
        ), 'value' => '1'];
        self::assertSame(-8.5, $service->preview($this->definition($calculation), 0)['items'][0]['c0']);
        $calculation = ['operator' => 'coalesce', 'left' => ['field' => 'selected_minimum_enrollment'],
            ...self::group('nullif', ['value' => '3'], ['value' => '3'])];
        self::assertNull($service->preview($this->definition($calculation), 0)['items'][0]['c0']);
    }

    public function testFallbackAndBlankingApplyToRowsBeforeCountAverageConditionsAndHaving(): void
    {
        $s = new SisFixture();
        $s->section(10, 0);
        $s->section(25, 0);
        $service = $this->service($s);
        $d = $this->definition(['operator' => 'nullif', 'value' => '10']);
        foreach (['sum' => 25.0, 'avg' => 25.0, 'count' => 1, 'count_distinct' => 1] as $summary => $expected) {
            $d['columns'][0]['aggregate'] = $summary;
            self::assertSame($expected, $service->preview($d, 0)['items'][0]['c0']);
        }
        $d['columns'][0]['aggregate'] = 'sum';
        $d['columns'][0]['conditions'] = $this->condition('capacity', 'greater', 15);
        $d['having'] = $this->condition('c0', 'greater', 24.5);
        self::assertSame(25.0, $service->preview($d, 0)['items'][0]['c0']);
        $d['having'] = $this->condition('c0', 'less', 24.5);
        self::assertSame([], $service->preview($d, 0)['items']);
        $d = $this->definition(['operator' => 'coalesce', 'value' => '0'], 'selected_minimum_enrollment');
        $d['columns'][0]['aggregate'] = 'count';
        self::assertSame(2, $service->preview($d, 0)['items'][0]['c0']);
    }

    public function testCompilerBindsEveryOperandInSqlOrderOnFiveDialects(): void
    {
        $d = $this->definition(['operator' => 'coalesce',
            'left' => self::group('nullif', ['value' => '7'], ['value' => '7']),
            ...self::group('+', ['value' => '1'], ['value' => '2'])]);
        $d['columns'][0]['aggregate'] = 'sum';
        $d['columns'][0]['conditions'] = $this->condition('capacity', 'greater', 5);
        $d['join_filters'] = ['course' => $this->condition('course_code', 'equal', 'FICTIONAL')];
        $d['filters'] = $this->condition('capacity', 'less', 100);
        $d['having'] = $this->condition('c0', 'greater', 2.5);
        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci'] as $driver) {
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string =>
                match ($driver) {
                    'mysql' => '`' . $name . '`', 'sqlsrv' => '[' . $name . ']', default => '"' . $name . '"',
                });
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method('getConnection')->willReturn($connection);
            $plan = new ReportSelectCompiler(new SqlRecords($db))->compile(
                new ReportQuery($d, AcademicReportSchema::datasets()['sections'])
            );
            self::assertSame([5, '7', '7', '1', '2', 'FICTIONAL', 100, 2.5], $plan['parameters']);
            self::assertStringContainsString(
                'COALESCE(NULLIF(CAST(? AS DECIMAL(24, 6)), CAST(? AS DECIMAL(24, 6)))',
                $plan['sql']
            );
            self::assertStringNotContainsString('FICTIONAL', $plan['sql']);
        }
    }

    public function testMoneyCannotEnterNullHandlingThroughFlatNestedOrCountedOperands(): void
    {
        foreach (['coalesce', 'nullif'] as $operation) {
            foreach (['none', 'count', 'count_distinct'] as $summary) {
                foreach ([false, true] as $nested) {
                    $calculation = $nested ? ['operator' => '+',
                        ...self::group($operation, ['field' => 'balance'], ['value' => '0'])]
                    : ['operator' => $operation, 'field' => 'balance'];
                    foreach (['balance', 'recorded_completed'] as $field) {
                        $d = ['dataset' => 'accounts', 'columns' => [['field' => $field, 'label' => 'Fictional amount',
                            'aggregate' => $summary, 'calculation' => $calculation]]];
                        try {
                            new ReportQuery($d, AcademicReportSchema::datasets()['accounts']);
                            self::fail('Money passed.');
                        } catch (ValidationException) {
                            self::assertTrue(true);
                        }
                    }
                }
            }
        }
    }

    public function testUnknownOperatorsAndNonnumericOperandsRemainInvalidBeforeSql(): void
    {
        foreach (['coalesce', 'nullif'] as $operation) {
            foreach (
                [['value' => null], ['value' => '1e3'], ['value' => 0], ['value' => "1'); DROP TABLE users; --"],
                ['field' => 'section_code'], ['field' => 'section_active'], ['field' => 'term_starts_on'],
                ['field' => 'unknown'], ['field' => 'capacity', 'value' => '1']] as $operand
            ) {
                try {
                    new ReportQuery(
                        $this->definition(['operator' => $operation, ...$operand]),
                        AcademicReportSchema::datasets()['sections']
                    );
                    self::fail('An untyped operand passed.');
                } catch (ValidationException) {
                    self::assertTrue(true);
                }
            }
        }
        foreach (['COALESCE', 'coalesce; SELECT 1', 'ISNULL', 'NVL', ''] as $operator) {
            try {
                new ReportCalculation(['operator' => $operator, 'value' => '0']);
                self::fail('Operator accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testHiddenFieldsAncestorsAndQuantityGrainsKeepTheirExistingGuards(): void
    {
        $s = new SisFixture();
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:catalog:manage'];
        $d = $this->definition(['operator' => 'coalesce',
            ...self::group('nullif', ['field' => 'copy1__recorded_completed'], ['value' => '0'])]);
        $d['aliases'] = ['copy1' => ['relation' => 'academic_credit', 'label' => 'Fictional copy']];
        self::assertContains(
            'sis:student:read',
            new ReportQuery($d, AcademicReportSchema::datasets()['sections'])->permissions()
        );
        try {
            $this->service($s)->preview($d, 0);
            self::fail('A hidden duty was bypassed.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $d = $this->definition(['operator' => 'coalesce', 'field' => 'capacity']);
        $d['columns'][0]['field'] = 'retained_course_credits';
        $d['columns'][0]['aggregate'] = 'sum';
        $d['joins'] = ['enrollment' => 'left'];
        $this->expectException(ValidationException::class);
        new ReportQuery($d, AcademicReportSchema::datasets()['sections']);
    }

    public function testSaveShareFixedCaptureAndEncryptedCompleteCsvUseTheExactDefinition(): void
    {
        $s = new SisFixture();
        $section = $s->section(25, 0);
        $service = $this->service($s);
        $d = $this->definition(['operator' => 'coalesce',
            'left' => self::group('nullif', ['field' => 'capacity'], ['value' => '25']), 'value' => '7.25']);
        $before = \EventStoreFixture::historyDigest($s->sql);
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional missing-value calculation', $d);
        self::assertSame($d, $service->view($id)['definition']);
        self::assertSame(7.25, $service->run($id, 0)['items'][0]['c0']);
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $s->sql->update('sis_sections', ['capacity' => 30], ['id' => $section->id->value]);
        self::assertSame(7.25, $service->run($id, 0)['items'][0]['c0']);
        self::assertSame(30.0, $service->preview($d, 0)['items'][0]['c0']);
        $capture = new SqlReportExportCapture(
            new SqlReportResultReader($s->connection->queryBuilder(), new SqlRelationalReportSource($s->sql)),
            new ReportSnapshotReader(new SqlReportLifecycleRepository($s->sql), $s->dropCipher),
            $s->dropCipher
        );
        $artifact = $capture->capture(EntityId::generate(), $id, new ReportQuery(
            $d,
            AcademicReportSchema::datasets()['sections']
        ), null);
        self::assertSame(1, $artifact->rows);
        $content = '';
        foreach ($artifact->chunks as $sealed) {
            $content .= json_decode($s->dropCipher->open($sealed), true, flags: JSON_THROW_ON_ERROR)['content'];
        }
        self::assertStringContainsString("30\n", str_replace("\r\n", "\n", $content));
        self::assertStringNotContainsString('30', json_encode($artifact));
        $recipient = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $recipient);
        $s->access->actor = $recipient;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:catalog:manage'];
        self::assertSame(7.25, $service->run($id, 0)['items'][0]['c0']);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        $s->access->grants = ['sis:reporting:run'];
        $this->expectException(AccessDeniedException::class);
        $service->run($id, 0);
    }

    public function testNullHandlingSharesTheSameDepthAndWholeColumnOperationBudget(): void
    {
        $branch = ['field' => 'capacity'];
        for ($index = 0; $index < 3; $index++) {
            $branch = self::group('coalesce', $branch, $branch);
        }
        $valid = ['operator' => 'nullif', 'left' => $branch, ...$branch];
        self::assertSame(['capacity'], new ReportCalculation($valid)->fields());
        self::assertTrue(new ReportCalculation($valid)->usesNullHandling());
        $tooMany = ['operator' => 'nullif', 'left' => $branch,
            ...self::group('coalesce', $branch, ['value' => '0'])];
        $deep = ['field' => 'capacity'];
        for ($index = 0; $index < 5; $index++) {
            $deep = self::group('nullif', $deep, ['value' => '0']);
        }
        foreach ([$tooMany, ['operator' => 'coalesce', ...$deep]] as $invalid) {
            try {
                new ReportCalculation($invalid);
                self::fail('A missing-value calculation exceeded its work bound.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
}
