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

final class ReportRelatedRecordsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function service(
        SisFixture $s,
        ?\Domain\Reporting\Repository\RelationalReportSource $source = null,
    ): RelationalReportService {
        return new RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            $source ?? new SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles()
        );
    }
    private function group(array ...$rules): array
    {
        return ['condition' => 'AND', 'rules' => $rules];
    }
    private function rule(string $id, mixed $value, string $operator = 'equal'): array
    {
        return ['id' => $id, 'operator' => $operator, 'value' => $value];
    }
    private function definition(string $mode = 'any'): array
    {
        return ['dataset' => 'students', 'columns' => [
            ['field' => 'student_id', 'label' => 'Student', 'aggregate' => 'none'],
        ], 'existence' => ['registration' => ['mode' => $mode, 'filters' => $this->group(
            $this->rule('registration_status', ['A', 'N'], 'in'),
            $this->rule('registration_term_code', '2026FA'),
        )]]];
    }
    /** Fictional current projections; never manufacture historical events for these rows. */
    private function row(SisFixture $s, EntityId $student, string $status = 'A', ?EntityId $term = null): void
    {
        $section = $s->section(20, 0);
        $term ??= $s->term;
        $s->sql->update('sis_sections', ['term_id' => $term->value], ['id' => $section->id->value]);
        $s->sql->insert('sis_enrollments', ['id' => EntityId::generate()->value, 'student_id' => $student->value,
            'section_id' => $section->id->value, 'term_id' => $term->value, 'status' => $status,
            'registered_at' => '2026-08-10T12:00:00+00:00', 'status_at' => '2026-08-10T12:00:00+00:00',
            'version' => 0]);
    }
    public function testConditionsMustMatchOneRegistrationAndNeverMultiplyStudentRows(): void
    {
        $s = new SisFixture();
        $yes = $s->student();
        $split = $s->student();
        $never = $s->student();
        $this->row($s, $yes->id);
        $this->row($s, $yes->id, 'N');
        $future = EntityId::generate();
        $s->sql->insert('sis_terms', ['id' => $future->value, 'code' => '27/SP', 'starts_on' => '2027-01-04',
            'ends_on' => '2027-05-01', 'add_drop_ends_on' => '2027-01-14']);
        $this->row($s, $split->id, 'A', $future);
        $this->row($s, $split->id, 'W');
        $events = $s->sql->rows('sis_history_events');
        self::assertSame([['c0' => $yes->id->value]], $this->service($s)->preview($this->definition(), 0)['items']);
        $excluded = array_column($this->service($s)->preview($this->definition('none'), 0)['items'], 'c0');
        $expected = [$split->id->value, $never->id->value];
        sort($expected);
        self::assertSame($expected, $excluded);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
    }
    public function testExistenceAppliesToTheWholeOuterOrGroupAndDoesNotInflateCounts(): void
    {
        $s = new SisFixture();
        $no = $s->student();
        $yes = $s->student();
        $this->row($s, $yes->id);
        $this->row($s, $yes->id);
        $query = $this->definition();
        $query['filters'] = ['condition' => 'OR', 'rules' => [
            $this->rule('student_id', $no->id->value), $this->rule('student_id', $yes->id->value),
        ]];
        self::assertSame([['c0' => $yes->id->value]], $this->service($s)->preview($query, 0)['items']);
        $query['columns'][0]['aggregate'] = 'count';
        self::assertSame([['c0' => 1]], $this->service($s)->preview($query, 0)['items']);
    }
    public function testAbsentSectionsAndNestedStudentNamesUseOneProtectedMatchingBranch(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $filled = $s->section(20, 0);
        $empty = $s->section(20, 0);
        $s->service->register($student->id, $filled->id, true);
        $query = ['dataset' => 'sections', 'columns' => [
            ['field' => 'section_code', 'label' => 'Section', 'aggregate' => 'none'],
        ], 'existence' => ['enrollment' => ['mode' => 'none', 'filters' => $this->group(
            $this->rule('enrollment_status', ['A', 'N'], 'in'),
        )]]];
        self::assertSame([['c0' => $empty->code]], $this->service($s)->preview($query, 0)['items']);
        $query['existence']['enrollment'] = ['mode' => 'any', 'filters' => $this->group(
            $this->rule('full_name', 'Alex Example'),
        )];
        // The section source exposes identity rather than names by default; add the trusted test field explicitly.
        $schema = AcademicReportSchema::datasets()['sections'];
        $schema['fields']['full_name'] = AcademicReportSchema::datasets()['students']['fields']['full_name'];
        $typed = new ReportQuery($query, $schema);
        $plan = new ReportSelectCompiler($s->sql)->compile($typed);
        $rows = $s->sql->fetch($plan['sql'], $plan['parameters']);
        self::assertSame($filled->code, $rows[0]['c0']);
    }
    public function testBlankConditionsMeanPresenceAndDeepTargetsRequireTheirWholePath(): void
    {
        $s = new SisFixture();
        $yes = $s->student();
        $s->student();
        $this->row($s, $yes->id);
        $query = $this->definition();
        $query['existence'] = ['registration_term' => ['mode' => 'any', 'filters' => null]];
        self::assertSame([['c0' => $yes->id->value]], $this->service($s)->preview($query, 0)['items']);
        $query['existence'] = ['latest_registration' => ['mode' => 'any', 'filters' => $this->group(
            $this->rule('latest_term_code', '2026FA'),
        )]];
        self::assertSame([['c0' => $yes->id->value]], $this->service($s)->preview($query, 0)['items']);
    }
    public function testMultipleRequirementsAreIndependentAndAllMustBeMet(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $this->row($s, $student->id);
        $query = $this->definition();
        $query['existence']['registered_section'] = ['mode' => 'none', 'filters' => null];
        self::assertSame([], $this->service($s)->preview($query, 0)['items']);
    }
    public function testMalformedScopeAndWorkBudgetsFailBeforeExecution(): void
    {
        $schema = AcademicReportSchema::datasets()['students'];
        $cases = [];
        $query = $this->definition();
        $query['existence']['registration']['filters'] = $this->group($this->rule('active', true));
        $cases[] = $query;
        $query = $this->definition();
        $query['existence']['registration']['mode'] = 'raw-sql';
        $cases[] = $query;
        $query = $this->definition();
        $query['existence']['unknown'] = ['mode' => 'any'];
        $cases[] = $query;
        $query = $this->definition();
        $query['existence'] = array_fill_keys(
            array_slice(array_keys($schema['relationships']), 0, 7),
            ['mode' => 'any']
        );
        $cases[] = $query;
        $query = $this->definition();
        $query['filters'] = $this->group(...array_fill(0, 40, $this->rule('active', true)));
        $cases[] = $query;
        foreach ($cases as $case) {
            try {
                new ReportQuery($case, $schema);
                self::fail('Invalid scope or a shared work limit was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
    public function testHiddenRelatedDataStillRequiresItsOwnPermission(): void
    {
        $s = new SisFixture();
        $s->access->staff = false;
        $s->access->grants = ['sis:catalog:manage', 'sis:reporting:manage', 'sis:reporting:run'];
        $query = ['dataset' => 'sections', 'columns' => [
            ['field' => 'section_code', 'label' => 'Section', 'aggregate' => 'none'],
        ], 'existence' => ['enrollment' => ['mode' => 'none', 'filters' => null]]];
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->preview($query, 0);
    }
    public function testSavedStaticAndLivePopulationReadsReuseMatchingRulesAndSharingGrants(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $this->row($s, $student->id);
        $this->row($s, $student->id);
        $service = $this->service($s);
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional matching students', $this->definition());
        self::assertCount(1, $service->population($id, 0)['items']);
        $selection = $service->selectStudents($id, [$student->id->value], 0);
        self::assertSame($student->id->value, $selection['items'][0]['id']);
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $s->sql->execute('DELETE FROM sis_enrollments');
        self::assertCount(1, $service->run($id, 0)['items']);
        self::assertSame([], $service->preview($this->definition(), 0)['items']);
        $reader = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run'];
        $this->expectException(AccessDeniedException::class);
        $service->run($id, 0);
    }
    public function testRelatedMatchParametersRespectJoinWhereAndHavingOrderAcrossDialects(): void
    {
        $s = new SisFixture();
        $query = $this->definition();
        $query['columns'][0]['aggregate'] = 'count';
        $query['join_filters'] = ['program' => $this->group($this->rule('program_code', 'BSCS'))];
        $query['filters'] = $this->group($this->rule('active', true));
        $query['having'] = $this->group($this->rule('c0', 0, 'greater'));
        $typed = new ReportQuery($query, AcademicReportSchema::datasets()['students']);
        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci'] as $driver) {
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string =>
                match ($driver) {
'mysql' => '`' . $name . '`', 'sqlsrv' => '[' . $name . ']', default => '"' . $name . '"'
                });
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method('getConnection')->willReturn($connection);
            $plan = new ReportSelectCompiler(new \Infrastructure\Persistence\SqlRecords($db))->compile($typed);
            self::assertSame(['BSCS', 1, 'A', 'N', '2026FA', 0], $plan['parameters']);
            self::assertStringContainsString('EXISTS', $plan['sql']);
            self::assertStringNotContainsString('2026FA', $plan['sql']);
        }
        $rows = $this->service($s)->preview($query, 0)['items'];
        self::assertSame([], $rows); // Count is zero and HAVING requires greater than zero.
    }
    public function testFieldOnlyRelatedPermissionIsCheckedBeforeAnySourceExecution(): void
    {
        $s = new SisFixture();
        $schema = AcademicReportSchema::datasets();
        $schema['students']['fields']['registration_term_code']['permissions'] = ['sis:catalog:manage'];
        $source = $this->createMock(\Domain\Reporting\Repository\RelationalReportSource::class);
        $source->method('datasets')->willReturn($schema);
        $source->expects(self::never())->method('execute');
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:student:read'];
        $this->expectException(AccessDeniedException::class);
        $this->service($s, $source)->preview($this->definition(), 0);
    }
    public function testAnEarlyDropRemainsInHistoryWithoutSatisfyingARecordCondition(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section(20, 0);
        $s->service->register($student->id, $section->id, true);
        self::assertCount(1, $this->service($s)->preview($this->definition(), 0)['items']);
        $review = $s->drops->review($student->id, $section->id);
        $s->drops->commit(
            $student->id,
            $section->id,
            new EntityId($review['enrollment_id']),
            $review['version'],
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['effective_on']
        );
        $history = $s->sql->rows('sis_history_events');
        self::assertNotEmpty($history);
        self::assertSame([], $this->service($s)->preview($this->definition(), 0)['items']);
        self::assertCount(1, $this->service($s)->preview($this->definition('none'), 0)['items']);
        self::assertSame($history, $s->sql->rows('sis_history_events'));
    }
    public function testPagedMatchesKeepOneStudentPerRowAndUseTheExistingRegistrationIndex(): void
    {
        $s = new SisFixture();
        $section = $s->section(100, 0);
        for ($i = 0; $i < 51; $i++) {
            $student = $s->student();
            $s->sql->insert('sis_enrollments', ['id' => EntityId::generate()->value,
                'student_id' => $student->id->value, 'section_id' => $section->id->value,
                'term_id' => $s->term->value, 'status' => 'A', 'registered_at' => '2026-08-10T12:00:00+00:00',
                'status_at' => '2026-08-10T12:00:00+00:00', 'version' => 0]);
        }
        $service = $this->service($s);
        $first = $service->preview($this->definition(), 0);
        $last = $service->preview($this->definition(), 50);
        self::assertCount(50, $first['items']);
        self::assertCount(1, $last['items']);
        self::assertSame(50, $first['next_offset']);
        self::assertNull($last['next_offset']);
        self::assertSame([], array_intersect(array_column($first['items'], 'c0'), array_column($last['items'], 'c0')));
        $plan = new ReportSelectCompiler($s->sql)->compile(new ReportQuery(
            $this->definition(),
            AcademicReportSchema::datasets()['students']
        ));
        $steps = $s->sql->fetch('EXPLAIN QUERY PLAN ' . $plan['sql'], $plan['parameters']);
        self::assertNotEmpty(array_filter($steps, static fn(array $step): bool =>
            str_contains($step['detail'], 'SEARCH match_registration') &&
            str_contains($step['detail'], 'student_id=?')));
    }
    public function testAChangedMatchCannotBeHandedOffFromAnEarlierPopulationPage(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $this->row($s, $student->id);
        $id = EntityId::generate();
        $service = $this->service($s);
        $service->save($id, $id, -1, 'Fictional reviewed matches', $this->definition());
        self::assertCount(1, $service->population($id, 0)['items']);
        $s->sql->update('sis_enrollments', ['status' => 'W'], ['student_id' => $student->id->value]);
        $this->expectException(\Domain\Shared\Exception\ConcurrentModificationException::class);
        $service->selectStudents($id, [$student->id->value], 0);
    }
}
