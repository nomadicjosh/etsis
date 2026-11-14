<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class LatestRegistrationReportTest extends TestCase
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
    private function definition(): array
    {
        return ['dataset' => 'students', 'columns' => [
            ['field' => 'student_id', 'label' => 'Student', 'aggregate' => 'none'],
            ['field' => 'latest_term_code', 'label' => 'Latest registered term', 'aggregate' => 'none'],
            ['field' => 'latest_term_starts_on', 'label' => 'Term starts', 'aggregate' => 'none'],
        ], 'joins' => [], 'sort' => []];
    }
    private function term(SisFixture $s, string $code, string $starts, string $ends): EntityId
    {
        $id = EntityId::generate();
        $s->sql->insert('sis_terms', ['id' => $id->value, 'code' => $code, 'starts_on' => $starts,
            'ends_on' => $ends, 'add_drop_ends_on' => $starts]);
        return $id;
    }
    /** Fictional projection inputs only: no synthetic historical events. */
    private function row(SisFixture $s, EntityId $student, EntityId $term, string $status = 'A'): void
    {
        $section = $s->section(20, 0);
        $s->sql->update('sis_sections', ['term_id' => $term->value], ['id' => $section->id->value]);
        $s->sql->insert('sis_enrollments', ['id' => EntityId::generate()->value, 'student_id' => $student->value,
            'section_id' => $section->id->value, 'term_id' => $term->value, 'status' => $status,
            'registered_at' => '2026-08-10T12:00:00+00:00', 'status_at' => '2026-08-10T12:00:00+00:00',
            'version' => 0]);
    }
    public function testTermDatesBeatCodesAndRepeatedCoursesDoNotMultiplyStudents(): void
    {
        $s = new SisFixture();
        $a = $s->student();
        $never = $s->student();
        $old = $this->term($s, 'ZZZ', '2025-08-01', '2025-12-01');
        $new = $this->term($s, 'AAA', '2027-01-01', '2027-05-01');
        $this->row($s, $a->id, $old);
        $this->row($s, $a->id, $new);
        $this->row($s, $a->id, $new, 'N');
        $facts = $s->sql->rows('sis_history_events');
        $rows = $this->service($s)->preview($this->definition(), 0)['items'];
        self::assertCount(2, $rows);
        $byStudent = array_column($rows, null, 'c0');
        self::assertSame('AAA', $byStudent[$a->id->value]['c1']);
        self::assertSame('2027-01-01', $byStudent[$a->id->value]['c2']);
        self::assertNull($byStudent[$never->id->value]['c1']);
        self::assertSame($facts, $s->sql->rows('sis_history_events'));
        $query = $this->definition();
        $query['joins'] = ['latest_registration' => 'inner'];
        self::assertSame([$byStudent[$a->id->value]], $this->service($s)->preview($query, 0)['items']);
    }
    public function testDateTiesUseEndDateThenStableIdentityAndIgnoreCancelledRegistrations(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $short = $this->term($s, 'SHORT', '2027-01-01', '2027-02-01');
        $long = $this->term($s, 'LONG', '2027-01-01', '2027-05-01');
        $same = $this->term($s, 'SAME', '2027-01-01', '2027-05-01');
        $future = $this->term($s, 'FUTURE', '2028-01-01', '2028-05-01');
        foreach ([$short, $long, $same] as $term) {
            $this->row($s, $student->id, $term, 'W');
        }
        $this->row($s, $student->id, $future, 'C');
        $expected = strcmp($long->value, $same->value) > 0 ? 'LONG' : 'SAME';
        self::assertSame($expected, $this->service($s)->preview($this->definition(), 0)['items'][0]['c1']);
    }
    public function testRelatedConditionsFilterTheSelectedLatestTermRatherThanChooseAnOlderMatch(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $this->row($s, $student->id, $s->term);
        $future = $this->term($s, 'FUTURE', '2027-01-01', '2027-05-01');
        $this->row($s, $student->id, $future);
        $query = $this->definition();
        $condition = ['condition' => 'AND', 'rules' => [
            ['id' => 'latest_term_code', 'operator' => 'equal', 'value' => '2026FA'],
        ]];
        $query['join_filters'] = ['latest_registration' => $condition];
        self::assertNull($this->service($s)->preview($query, 0)['items'][0]['c1']);
        unset($query['join_filters']);
        $query['filters'] = $condition;
        self::assertSame([], $this->service($s)->preview($query, 0)['items']);
    }
    public function testNoRegistrationReportAndPopulationPreserveMembershipAndNoticeLaterRegistrations(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $query = $this->definition();
        $query['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'latest_term_code', 'operator' => 'is_null', 'value' => null],
        ]];
        $service = $this->service($s);
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional unregistered students', $query);
        self::assertSame($student->id->value, $service->population($id, 0)['items'][0]['id']);
        $this->row($s, $student->id, $s->term);
        self::assertSame([], $service->run($id, 0)['items']);
        self::assertSame([], $service->population($id, 0)['items']);
    }
    public function testSharedStaticReportKeepsItsTermAndRequiresCurrentSourceAuthority(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $this->row($s, $student->id, $s->term);
        $id = EntityId::generate();
        $service = $this->service($s);
        $service->save($id, $id, -1, 'Fictional registration snapshot', $this->definition());
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $future = $this->term($s, 'FUTURE', '2027-01-01', '2027-05-01');
        $this->row($s, $student->id, $future);
        self::assertSame('2026FA', $service->run($id, 0)['items'][0]['c1']);
        $reader = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run'];
        $this->expectException(AccessDeniedException::class);
        $service->run($id, 0);
    }
    public function testLatestTermOnlyFiltersStillRequireStudentReadPermission(): void
    {
        $s = new SisFixture();
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run'];
        $query = $this->definition();
        $query['columns'] = [['field' => 'active', 'label' => 'Activity', 'aggregate' => 'none']];
        $query['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'latest_term_code', 'operator' => 'is_null', 'value' => null],
        ]];
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->preview($query, 0);
    }
    public function testEarlyDropHistoryIsPreservedWithoutAppearingAsARetainedRegistration(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section(20, 0);
        $s->service->register($student->id, $section->id, true);
        self::assertSame('2026FA', $this->service($s)->preview($this->definition(), 0)['items'][0]['c1']);
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
        $events = $s->sql->rows('sis_history_events');
        self::assertNotEmpty($events);
        self::assertNull($this->service($s)->preview($this->definition(), 0)['items'][0]['c1']);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
    }
    public function testDerivedLatestTermSqlCompilesWithEachSupportedIdentifierDialect(): void
    {
        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci'] as $driver) {
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $pdo = $this->createStub(\PDO::class);
            $pdo->method('getAttribute')->willReturn($driver);
            $connection->method(\PHPUnit\Framework\MockObject\Runtime\PropertyHook::get('pdo'))->willReturn($pdo);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string =>
                match ($driver) {
                    'mysql' => '`' . $name . '`',
                    'sqlsrv' => '[' . $name . ']',
                    default => '"' . $name . '"',
                });
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method('getConnection')->willReturn($connection);
            $query = $this->definition();
            $query['filters'] = ['condition' => 'AND', 'rules' => [
                ['id' => 'latest_term_code', 'operator' => 'equal', 'value' => 'fictional-bound-code'],
            ]];
            $plan = new ReportSelectCompiler(new SqlRecords($db))->compile(new ReportQuery(
                $query,
                AcademicReportSchema::datasets()['students']
            ));
            self::assertStringContainsString('latest_e', $plan['sql']);
            self::assertStringContainsString(match ($driver) {
                'sqlsrv' => 'TOP (1)', 'oci' => 'ROWNUM <= 1', default => 'LIMIT 1',
            }, $plan['sql']);
            self::assertStringNotContainsString('fictional-bound-code', $plan['sql']);
            self::assertSame(['fictional-bound-code'], $plan['parameters']);
        }
    }
    public function testLatestLookupUsesTheExistingStudentRegistrationIndex(): void
    {
        $s = new SisFixture();
        $query = new ReportQuery($this->definition(), AcademicReportSchema::datasets()['students']);
        $plan = new ReportSelectCompiler($s->sql)->compile($query);
        $steps = $s->sql->fetch('EXPLAIN QUERY PLAN ' . $plan['sql'], $plan['parameters']);
        self::assertNotEmpty(array_filter($steps, static fn(array $step): bool =>
            str_contains($step['detail'], 'SEARCH latest_e') && str_contains($step['detail'], 'student_id=?')));
        self::assertSame([], array_values(array_filter($steps, static fn(array $step): bool =>
            str_contains($step['detail'], 'SCAN latest_e'))));
    }
}
