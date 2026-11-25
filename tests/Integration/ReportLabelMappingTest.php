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
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ReportLabelMappingTest extends TestCase
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

    private function condition(string $field, string $operator, mixed $value = null): array
    {
        return ['condition' => 'AND', 'rules' => [['id' => $field, 'operator' => $operator, 'value' => $value]]];
    }

    private function mapping(): array
    {
        return ['branches' => [
            ['when' => $this->condition('capacity', 'greater', 15), 'label' => 'Larger section'],
            ['when' => $this->condition('capacity', 'greater', 25), 'label' => 'Very large section'],
        ], 'otherwise' => null];
    }

    private function definition(): array
    {
        return ['dataset' => 'sections', 'columns' => [
            ['field' => 'section_code', 'label' => 'Section', 'aggregate' => 'none'],
            ['field' => 'capacity', 'label' => 'Planning label', 'aggregate' => 'none',
                'label_mapping' => $this->mapping()],
        ], 'sort' => [['column' => 0, 'direction' => 'asc']]];
    }

    public function testFirstMatchingLabelWinsAndUnmatchedRowsRemainNullWithoutChangingFacts(): void
    {
        $s = new SisFixture();
        $small = $s->section(10, 0);
        $large = $s->section(30, 0);
        $before = \EventStoreFixture::historyDigest($s->sql);
        $d = $this->definition();
        $preview = $this->service($s)->preview($d, 0);
        self::assertSame(['Planning label'], $preview['labelled_columns']);
        $rows = array_column($preview['items'], 'c1', 'c0');
        self::assertSame(['Larger section', null], [$rows[$large->code], $rows[$small->code]]);
        $d['columns'][1]['label_mapping']['branches'] = array_reverse($this->mapping()['branches']);
        $d['columns'][1]['label_mapping']['otherwise'] = 'Smaller section';
        $rows = array_column($this->service($s)->preview($d, 0)['items'], 'c1', 'c0');
        self::assertSame(['Very large section', 'Smaller section'], [$rows[$large->code], $rows[$small->code]]);
        self::assertSame('string', new ReportQuery($d, AcademicReportSchema::datasets()['sections'])->outputType(1));
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testLabelsGroupAndSortWhileCountsAndTextSummaryFiltersKeepTheirTypes(): void
    {
        $s = new SisFixture();
        foreach ([10, 20, 30] as $capacity) {
            $s->section($capacity, 0);
        }
        $d = $this->definition();
        $d['columns'] = [$d['columns'][1],
            ['field' => 'section_code', 'label' => 'Sections', 'aggregate' => 'count_distinct']];
        $d['columns'][0]['label_mapping']['otherwise'] = 'Smaller section';
        self::assertSame(
            [['c0' => 'Larger section', 'c1' => 2], ['c0' => 'Smaller section', 'c1' => 1]],
            $this->service($s)->preview($d, 0)['items']
        );
        $d['columns'] = [$d['columns'][0]];
        $d['columns'][0]['aggregate'] = 'max';
        $d['having'] = $this->condition('c0', 'begins_with', 'Smaller');
        self::assertSame([['c0' => 'Smaller section']], $this->service($s)->preview($d, 0)['items']);
        $d['columns'][0]['aggregate'] = 'count';
        $d['columns'][0]['label_mapping']['otherwise'] = null;
        $d['having'] = $this->condition('c0', 'equal', 2);
        self::assertSame([['c0' => 2]], $this->service($s)->preview($d, 0)['items']);
    }

    public function testCaseSummaryJoinWhereAndHavingParametersFollowSqlOrderInAllFiveDialects(): void
    {
        $s = new SisFixture();
        $d = $this->definition();
        $label = 'Fictional <b>label</b> \' UNION SELECT private --';
        $d['columns'] = [$d['columns'][1]];
        $d['columns'][0]['aggregate'] = 'count_distinct';
        $d['columns'][0]['label_mapping'] = ['branches' => [
            ['when' => $this->condition('capacity', 'greater', 15), 'label' => $label],
        ], 'otherwise' => null];
        $d['columns'][0]['conditions'] = $this->condition('enrollment_status', 'not_equal', 'W');
        $d['join_filters'] = ['enrollment' => $this->condition('enrollment_status', 'equal', 'A')];
        $d['filters'] = $this->condition('section_code', 'equal', 'FICTIONAL.101');
        $d['having'] = $this->condition('c0', 'greater', 0);
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
            self::assertSame(['W', 15, $label, null, 'A', 'FICTIONAL.101', 0], $plan['parameters']);
            self::assertStringNotContainsString($label, $plan['sql']);
            self::assertStringContainsString('CASE WHEN', $plan['sql']);
        }
    }

    public function testHiddenNamedBranchConditionsRequireSourcePermissions(): void
    {
        $s = new SisFixture();
        $s->section(20, 0);
        $d = $this->definition();
        $d['aliases'] = ['copy1' => ['relation' => 'enrollment', 'label' => 'Other registrations']];
        $d['columns'][1]['label_mapping']['branches'] = [
            ['when' => $this->condition('copy1__enrollment_status', 'is_null'), 'label' => 'No recorded registration'],
        ];
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:catalog:manage'];
        try {
            $this->service($s)->preview($d, 0);
            self::fail('A condition-only student source bypassed its permission.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->grants[] = 'sis:student:read';
        self::assertSame('No recorded registration', $this->service($s)->preview($d, 0)['items'][0]['c1']);
    }

    public function testLabelOnlyJoinsCannotInflateRootQuantitiesOrCurrencyAmounts(): void
    {
        $sections = $this->definition();
        $sections['columns'][0] = ['field' => 'capacity', 'label' => 'Capacity', 'aggregate' => 'sum'];
        $sections['columns'][1]['label_mapping']['branches'] = [
            ['when' => $this->condition('enrollment_status', 'is_null'), 'label' => 'No registration'],
        ];
        $accounts = ['dataset' => 'accounts', 'columns' => [
            ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
            ['field' => 'balance', 'label' => 'Balance', 'aggregate' => 'sum'],
            ['field' => 'account_id', 'label' => 'Registration label', 'aggregate' => 'none', 'label_mapping' => [
                'branches' => [['when' => $this->condition('registration_status', 'is_null'), 'label' => 'None']],
                'otherwise' => 'Recorded',
            ]],
        ]];
        $currency = $accounts;
        array_pop($currency['columns']);
        $currency['columns'][0]['label_mapping'] = ['branches' => [
            ['when' => $this->condition('currency', 'is_not_null'), 'label' => 'All currencies'],
        ], 'otherwise' => null];
        foreach ([$sections, $accounts, $currency] as $d) {
            try {
                new ReportQuery($d, AcademicReportSchema::datasets()[$d['dataset']]);
                self::fail('Custom labels bypassed the original total grain or actual-currency dimension.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMappingValidationSharesTheConditionBudgetAndRejectsIncompleteOrIncompatibleOutputs(): void
    {
        foreach (
            ['empty', 'too_many', 'long', 'control', 'numeric', 'unknown', 'no_condition',
            'budget', 'calculation', 'calendar', 'sum', 'fallback'] as $case
        ) {
            $d = $this->definition();
            $column = &$d['columns'][1];
            $mapping = &$column['label_mapping'];
            match ($case) {
                'empty' => $mapping['branches'] = [],
                'too_many' => $mapping['branches'] = array_fill(0, 9, $mapping['branches'][0]),
                'long' => $mapping['branches'][0]['label'] = str_repeat('a', 121),
                'control' => $mapping['branches'][0]['label'] = "Private\nlabel",
                'numeric' => $mapping['branches'][0]['label'] = 123,
                'unknown' => $mapping['branches'][0]['when']['rules'][0]['id'] = 'raw_payload',
                'no_condition' => $mapping['branches'][0]['when']['rules'] = [],
                'budget' => $mapping['branches'][0]['when']['rules'] = array_fill(0, 40,
                    $mapping['branches'][0]['when']['rules'][0]),
                'calculation' => $column['calculation'] = ['operator' => '+', 'field' => 'capacity'],
                'calendar' => $column['date_bucket'] = 'month',
                'sum' => $column['aggregate'] = 'sum',
                'fallback' => $mapping['otherwise'] = '',
            };
            try {
                new ReportQuery($d, AcademicReportSchema::datasets()['sections']);
                self::fail('An invalid mapping was accepted: ' . $case);
            } catch (ValidationException) {
                self::assertTrue(true);
            }
            unset($column, $mapping);
        }
    }

    public function testSavedStaticLabelsRetainTheirValuesAndStillRequireHiddenSourceDuties(): void
    {
        $s = new SisFixture();
        $section = $s->section(20, 0);
        $service = $this->service($s);
        $d = $this->definition();
        $d['columns'][1]['label_mapping']['branches'][0]['when'] = $this->condition('enrollment_status', 'is_null');
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional labels', $d);
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        self::assertSame(['Planning label'], $service->view($id)['labelled_columns']);
        $s->sql->update('sis_sections', ['capacity' => 1], ['id' => $section->id->value]);
        self::assertSame('Larger section', $service->run($id, 0)['items'][0]['c1']);
        $reader = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:catalog:manage'];
        $this->expectException(AccessDeniedException::class);
        $service->run($id, 0);
    }

    public function testStudentPopulationPreservesAnInnerRelationshipUsedOnlyByTheLabelRule(): void
    {
        $schema = AcademicReportSchema::datasets()['students'];
        $d = ['dataset' => 'students', 'joins' => ['registration' => 'inner'], 'columns' => [
            ['field' => 'active', 'label' => 'Registration label', 'aggregate' => 'none', 'label_mapping' => [
                'branches' => [['when' => $this->condition('registration_status', 'equal', 'A'), 'label' => 'Staff']],
                'otherwise' => null,
            ]],
        ]];
        $population = new ReportQuery($d, $schema)->studentPopulation();
        self::assertSame('inner', $population->definition['joins']['registration']);
        self::assertArrayNotHasKey('label_mapping', $population->definition['columns'][0]);
    }
}
