<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\{ReportCalculation, ReportQuery};
use Domain\Shared\Exception\{AccessDeniedException, ValidationException};
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\{SqlRelationalReportRepository, SqlReportRepository};
use Infrastructure\Reporting\{AcademicReportSchema, ReportSelectCompiler, SqlRelationalReportSource};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ReportNestedCalculationTest extends TestCase
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

    private static function group(string $op, array $left, array $right): array
    {
        return ['group' => ['operator' => $op, 'left' => $left, 'right' => $right]];
    }

    private function calculation(): array
    {
        return ['operator' => '/', 'left' => self::group('-', ['field' => 'capacity'], ['value' => '5']),
            ...self::group('+', ['value' => '2'], ['value' => '2'])];
    }

    private function definition(?array $calculation = null): array
    {
        return ['dataset' => 'sections', 'columns' => [
            ['field' => 'capacity', 'label' => 'Fictional grouped quantity', 'aggregate' => 'none',
                'calculation' => $calculation ?? $this->calculation()],
        ]];
    }

    private function condition(string $field, string $operator, mixed $value): array
    {
        return ['condition' => 'AND', 'rules' => [['id' => $field, 'operator' => $operator, 'value' => $value]]];
    }

    public function testBothSidesAreParenthesizedAndFractionalBeforeConditionalSummariesAndHaving(): void
    {
        $s = new SisFixture();
        $s->section(25, 0);
        $s->section(10, 0);
        $before = \EventStoreFixture::historyDigest($s->sql);
        $d = $this->definition();
        $d['sort'] = [['column' => 0, 'direction' => 'desc']];
        self::assertSame([5.0, 1.25], array_column($this->service($s)->preview($d, 0)['items'], 'c0'));
        $d['columns'][0]['aggregate'] = 'sum';
        $d['columns'][0]['conditions'] = $this->condition('capacity', 'greater', 15);
        $d['having'] = $this->condition('c0', 'greater', 4.5);
        self::assertSame(5.0, $this->service($s)->preview($d, 0)['items'][0]['c0']);
        $d['having'] = $this->condition('c0', 'less', 4.5);
        self::assertSame([], $this->service($s)->preview($d, 0)['items']);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testEveryInnerZeroDivisorAndMissingInputRemainsNullWithoutInventingZero(): void
    {
        $s = new SisFixture();
        $s->section(25, 0);
        $calculations = [
            ['operator' => '/', ...self::group('-', ['value' => '3'], ['value' => '3'])],
            ['operator' => '+', ...self::group('/', ['value' => '3'], ['value' => '-0.000000'])],
            ['operator' => '+', ...self::group('*', ['field' => 'selected_minimum_enrollment'], ['value' => '0'])],
            ['operator' => '*', 'left' => self::group('/', ['field' => 'capacity'], ['value' => '0']), 'value' => '0'],
        ];
        foreach ($calculations as $calculation) {
            $d = $this->definition($calculation);
            self::assertNull($this->service($s)->preview($d, 0)['items'][0]['c0']);
            $d['columns'][0]['aggregate'] = 'sum';
            self::assertNull($this->service($s)->preview($d, 0)['items'][0]['c0']);
            $d['columns'][0]['aggregate'] = 'count';
            self::assertSame(0, $this->service($s)->preview($d, 0)['items'][0]['c0']);
        }
    }

    public function testCompilerBindsBothBranchesInSqlOrderAndRetainsPortableNullSafeDivision(): void
    {
        $d = $this->definition();
        $d['columns'][0]['aggregate'] = 'sum';
        $d['columns'][0]['conditions'] = $this->condition('capacity', 'greater', 10);
        $d['join_filters'] = ['course' => $this->condition('course_code', 'equal', 'FICTIONAL')];
        $d['filters'] = $this->condition('capacity', 'less', 100);
        $d['having'] = $this->condition('c0', 'greater', 3.5);
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
            self::assertSame([10, '5', '2', '2', 'FICTIONAL', 100, 3.5], $plan['parameters']);
            self::assertStringContainsString(
                'NULLIF((CAST(? AS DECIMAL(24, 6)) + CAST(? AS DECIMAL(24, 6))), 0)',
                $plan['sql'],
            );
            self::assertStringNotContainsString('FICTIONAL', $plan['sql']);
        }
    }

    public static function malformedOperands(): iterable
    {
        $valid = self::group('+', ['value' => '1'], ['field' => 'capacity']);
        yield 'ambiguous leaf' => [self::group('+', ['value' => '1', 'field' => 'capacity'], ['value' => '2'])];
        yield 'operator text' => [['group' => ['operator' => 'UNION',
            'left' => ['value' => '1'], 'right' => ['value' => '2']]]];
        yield 'extra group key' => [['group' => $valid['group'] + ['sql' => 'private']]];
        yield 'extra operand key' => [$valid + ['sql' => 'private']];
        yield 'unknown field' => [self::group('+', ['field' => 'missing'], ['value' => '1'])];
        yield 'text field' => [self::group('+', ['field' => 'section_code'], ['value' => '1'])];
        yield 'boolean field' => [self::group('+', ['field' => 'section_active'], ['value' => '1'])];
        yield 'date field' => [self::group('+', ['field' => 'term_starts_on'], ['value' => '1'])];
        yield 'float literal' => [self::group('+', ['value' => 0.5], ['value' => '1'])];
        yield 'exponent' => [self::group('+', ['value' => '1e3'], ['value' => '1'])];
        yield 'hostile literal' => [self::group(
            '+',
            ['value' => "1'); DROP TABLE sis_sections; --"],
            ['value' => '1']
        )];
        yield 'null group' => [['group' => null]];
        yield 'scalar left' => [['group' => ['operator' => '+',
            'left' => '1', 'right' => ['value' => '1']]]];
    }

    #[DataProvider('malformedOperands')]
    public function testNestedOperandsAreTypedBeforeSql(array $operand): void
    {
        foreach ([['operator' => '+', ...$operand], ['operator' => '+', 'left' => $operand, 'value' => '1']] as $bad) {
            try {
                new ReportQuery($this->definition($bad), AcademicReportSchema::datasets()['sections']);
                self::fail('An untyped operand reached query compilation.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDepthAndOperationBoundsApplyAcrossBothBranches(): void
    {
        $branch = ['field' => 'capacity'];
        for ($i = 0; $i < 4; $i++) {
            $branch = self::group('+', $branch, ['value' => '1']);
        }
        self::assertSame(['capacity'], new ReportCalculation(['operator' => '+', ...$branch])->fields());
        $tooDeep = self::group('+', $branch, ['value' => '1']);
        $wide = ['field' => 'capacity'];
        for ($i = 0; $i < 3; $i++) {
            $wide = self::group('+', $wide, $wide);
        }
        // Two seven-operation groups plus the outer operation reach exactly fifteen.
        $valid = ['operator' => '+', 'left' => $wide, ...$wide];
        self::assertSame(['capacity'], new ReportCalculation($valid)->fields());
        $s = new SisFixture();
        $s->section(25, 0);
        self::assertSame(400.0, $this->service($s)->preview($this->definition($valid), 0)['items'][0]['c0']);
        self::assertSame(54.0, $this->service($s)->preview(
            $this->definition(['operator' => '+', ...$branch]),
            0,
        )['items'][0]['c0']);
        $tooMany = ['operator' => '+', 'left' => $wide,
            ...self::group('+', $wide, ['value' => '1'])];
        $invalid = [['operator' => '+', ...$tooDeep], $tooMany, ['operator' => '+', 'left' => null, 'value' => '1']];
        foreach ($invalid as $bad) {
            try {
                new ReportCalculation($bad);
                self::fail('An unbounded expression was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMonetaryFieldsCannotHideInEitherBranchIncludingCountedColumns(): void
    {
        $schema = AcademicReportSchema::datasets()['accounts'];
        self::assertArrayHasKey('recorded_completed', $schema['fields']);
        foreach (['none', 'count'] as $summary) {
            foreach ([false, true] as $left) {
                $calculation = ['operator' => '+', ...self::group('+', ['field' => 'balance'], ['value' => '1'])];
                if ($left) {
                    $calculation = ['operator' => '+', 'left' => $calculation, 'value' => '1'];
                    unset($calculation['left']['operator']);
                }
                $d = ['dataset' => 'accounts', 'columns' => [
                    ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
                    ['field' => 'balance', 'label' => 'Amount', 'aggregate' => $summary, 'calculation' => $calculation],
                ]];
                foreach (['balance', 'recorded_completed'] as $base) {
                    $d['columns'][1]['field'] = $base;
                    try {
                        new ReportQuery($d, $schema);
                        self::fail('A nested calculation bypassed monetary units.');
                    } catch (ValidationException) {
                        self::assertTrue(true);
                    }
                }
            }
        }
    }

    public function testHiddenRootQuantityStillRejectsManyRowInflation(): void
    {
        $d = $this->definition(['operator' => '+', ...self::group('+', ['field' => 'capacity'], ['value' => '1'])]);
        $d['columns'][0]['field'] = 'retained_course_credits';
        $d['columns'][0]['aggregate'] = 'sum';
        $d['joins'] = ['enrollment' => 'left'];
        $this->expectException(ValidationException::class);
        new ReportQuery($d, AcademicReportSchema::datasets()['sections']);
    }

    public function testHiddenFieldsAndNamedAncestorsRetainPermissionsAndPopulationJoins(): void
    {
        $d = $this->definition(['operator' => '+',
            ...self::group('+', ['field' => 'copy1__recorded_completed'], ['value' => '1'])]);
        $d['aliases'] = ['copy1' => ['relation' => 'academic_credit', 'label' => 'Fictional second credit']];
        $query = new ReportQuery($d, AcademicReportSchema::datasets()['sections']);
        self::assertContains('sis:student:read', $query->permissions());
        $s = new SisFixture();
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:catalog:manage'];
        try {
            $this->service($s)->preview($d, 0);
            self::fail('A hidden academic operand bypassed its source duty.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $students = ['dataset' => 'students', 'columns' => [
            ['field' => 'recorded_attempted', 'label' => 'Fictional quantity', 'aggregate' => 'none',
                'calculation' => ['operator' => '/',
                    ...self::group('+', ['field' => 'recorded_completed'], ['value' => '1'])]],
        ]];
        $population = new ReportQuery($students, AcademicReportSchema::datasets()['students'])->studentPopulation();
        self::assertSame('left', $population->definition['joins']['academic_credit']);
    }

    public function testSavedSharedStaticAndDynamicResultsPreserveExactGroupsAndCurrentSourceGrants(): void
    {
        $s = new SisFixture();
        $section = $s->section(26, 0);
        $service = $this->service($s);
        $id = EntityId::generate();
        $d = $this->definition();
        $before = \EventStoreFixture::historyDigest($s->sql);
        $service->save($id, $id, -1, 'Fictional nested calculation', $d);
        self::assertSame($d, $service->view($id)['definition']);
        self::assertSame(5.25, $service->run($id, 0)['items'][0]['c0']);
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $s->sql->update('sis_sections', ['capacity' => 30], ['id' => $section->id->value]);
        self::assertSame(5.25, $service->run($id, 0)['items'][0]['c0']);
        self::assertSame(6.25, $service->preview($d, 0)['items'][0]['c0']);
        $recipient = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $recipient);
        $s->access->actor = $recipient;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:catalog:manage'];
        self::assertSame(5.25, $service->run($id, 0)['items'][0]['c0']);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        $s->access->grants = ['sis:reporting:run'];
        $this->expectException(AccessDeniedException::class);
        $service->run($id, 0);
    }
}
