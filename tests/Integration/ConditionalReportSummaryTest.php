<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingOccurrence;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ConditionalReportSummaryTest extends TestCase
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

    private function filter(string $field, string $operator, mixed $value): array
    {
        return ['condition' => 'AND', 'rules' => [['id' => $field, 'operator' => $operator, 'value' => $value]]];
    }

    private function definition(): array
    {
        return ['dataset' => 'sections', 'columns' => [
            ['field' => 'section_code', 'label' => 'Section', 'aggregate' => 'none'],
            ['field' => 'enrollment_id', 'label' => 'Active', 'aggregate' => 'count_distinct',
                'conditions' => $this->filter('enrollment_status', 'in', ['A', 'N'])],
            ['field' => 'enrollment_id', 'label' => 'Withdrawn', 'aggregate' => 'count_distinct',
                'conditions' => $this->filter('enrollment_status', 'equal', 'W')],
        ]];
    }

    /** Fictional reporting projections, without inventing registration decisions or seat receipts. */
    private function enrollment(SisFixture $s, EntityId $section, string $status): void
    {
        $s->sql->insert('sis_enrollments', [
            'id' => EntityId::generate()->value, 'student_id' => $s->student()->id->value,
            'section_id' => $section->value, 'term_id' => $s->term->value, 'status' => $status,
            'registered_at' => '2026-08-10T12:00:00+00:00', 'status_at' => '2026-08-10T12:00:00+00:00',
            'version' => 0,
        ]);
    }

    public function testIndependentCountsPreserveEmptySectionsAndSharedRowsAndHavingKeepTheirOwnScopes(): void
    {
        $s = new SisFixture();
        $busy = $s->section(fee: 0);
        $empty = $s->section(fee: 0);
        foreach (['A', 'N', 'W', 'C'] as $status) {
            $this->enrollment($s, $busy->id, $status);
        }
        $before = $s->sql->rows('sis_history_events');
        $d = $this->definition();
        $rows = array_column($this->service($s)->preview($d, 0)['items'], null, 'c0');
        self::assertSame([2, 1], [$rows[$busy->code]['c1'], $rows[$busy->code]['c2']]);
        self::assertSame([0, 0], [$rows[$empty->code]['c1'], $rows[$empty->code]['c2']]);
        $d['filters'] = $this->filter('enrollment_status', 'not_equal', 'W');
        $rows = $this->service($s)->preview($d, 0)['items'];
        self::assertSame([2, 0], [$rows[0]['c1'], $rows[0]['c2']]);
        unset($d['filters']);
        $d['having'] = $this->filter('c1', 'greater', 0);
        self::assertCount(1, $this->service($s)->preview($d, 0)['items']);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }

    public function testNumericCalculationsNullValuesAndTypedMinMaxRetainTheirMeaning(): void
    {
        $s = new SisFixture();
        $section = $s->section(capacity: 30, fee: 0);
        $yes = $this->filter('section_code', 'equal', $section->code);
        $no = $this->filter('section_code', 'equal', 'NOT.PRESENT');
        $d = ['dataset' => 'sections', 'columns' => [
            ['field' => 'capacity', 'label' => 'Selected seats', 'aggregate' => 'sum', 'conditions' => $yes],
            ['field' => 'capacity', 'label' => 'Other seats', 'aggregate' => 'sum', 'conditions' => $no],
            ['field' => 'capacity', 'label' => 'Average', 'aggregate' => 'avg', 'conditions' => $yes],
            ['field' => 'section_code', 'label' => 'First code', 'aggregate' => 'min', 'conditions' => $yes],
            ['field' => 'term_starts_on', 'label' => 'Last start', 'aggregate' => 'max', 'conditions' => $yes],
            ['field' => 'capacity', 'label' => 'Difference', 'aggregate' => 'sum',
                'calculation' => ['operator' => '-', 'field' => 'capacity'], 'conditions' => $yes],
            ['field' => 'enrollment_id', 'label' => 'Known registrations', 'aggregate' => 'count',
                'conditions' => $this->filter('enrollment_status', 'not_equal', 'W')],
        ]];
        $rows = $this->service($s)->preview($d, 0)['items'];
        self::assertSame([30, null, 30.0, $section->code, '2026-08-01', 0.0, 0], array_values($rows[0]));
    }

    public function testColumnJoinAndRowAndSummaryParametersFollowSqlOrderInEveryDialect(): void
    {
        $s = new SisFixture();
        $section = $s->section(fee: 0);
        $this->enrollment($s, $section->id, 'A');
        $d = $this->definition();
        $d['join_filters'] = ['enrollment' => $this->filter('enrollment_status', 'not_equal', 'C')];
        $d['filters'] = $this->filter('section_code', 'equal', $section->code);
        $d['having'] = $this->filter('c1', 'greater', 0);
        $expected = ['A', 'N', 'W', 'C', $section->code, 0];
        self::assertSame(1, $this->service($s)->preview($d, 0)['items'][0]['c1']);
        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci'] as $driver) {
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => match ($driver) {
                    'mysql' => '`' . $name . '`', 'sqlsrv' => '[' . $name . ']', default => '"' . $name . '"',
                },
            );
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method('getConnection')->willReturn($connection);
            $plan = new ReportSelectCompiler(new \Infrastructure\Persistence\SqlRecords($db))->compile(
                new ReportQuery($d, AcademicReportSchema::datasets()['sections']),
            );
            self::assertSame($expected, $plan['parameters']);
            self::assertStringContainsString('CASE WHEN', $plan['sql']);
            self::assertStringNotContainsString($section->code, $plan['sql']);
        }
    }

    public function testMoneyConditionsKeepCurrencyAndRejectHiddenManyRecordJoins(): void
    {
        $s = new SisFixture();
        foreach (['USD', 'JPY'] as $currency) {
            $student = $s->student();
            $account = StudentAccount::open(new AccountId(), $student->id, $s->term, $currency);
            $account->post(EntityId::generate(), EntryType::Charge, new Money(2500, $currency), $s->access->actor);
            $s->saveAccount($account);
        }
        $d = ['dataset' => 'accounts', 'columns' => [
            ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
            ['field' => 'balance', 'label' => 'Owed', 'aggregate' => 'sum',
                'conditions' => $this->filter('balance_minor', 'greater', 0)],
            ['field' => 'balance', 'label' => 'Credit', 'aggregate' => 'sum',
                'conditions' => $this->filter('balance_minor', 'less', 0)],
        ]];
        $rows = array_column($this->service($s)->preview($d, 0)['items'], null, 'c0');
        self::assertSame(25.0, $rows['USD']['c1']);
        self::assertSame(2500.0, $rows['JPY']['c1']);
        self::assertNull($rows['USD']['c2']);
        $d['columns'][1]['conditions'] = $this->filter('registration_status', 'equal', 'A');
        $this->expectException(ValidationException::class);
        $this->service($s)->preview($d, 0);
    }

    public function testLatestRowSelectionHappensBeforeColumnMatchingWithBoundNestedRules(): void
    {
        $s = new SisFixture();
        $room = $s->room('TEST.101', 20);
        foreach (['2026-11-19' => 'approved', '2026-11-20' => 'requested'] as $day => $status) {
            $time = new DateTimeImmutable($day . 'T10:00:00+00:00');
            new SqlBookingRepository($s->sql)->add(new Booking(
                EntityId::generate(),
                $room,
                $s->access->actor,
                'Fictional meeting',
                [BookingOccurrence::plan(new TimeSlot(
                    $time,
                    $time->modify('+1 hour'),
                ))],
                $status,
            ));
        }
        $d = ['dataset' => 'rooms', 'columns' => [
            ['field' => 'room_code', 'label' => 'Room', 'aggregate' => 'none'],
            ['field' => 'meeting_id', 'label' => 'Approved latest', 'aggregate' => 'count_distinct',
                'conditions' => ['condition' => 'AND', 'rules' => [
                    ['condition' => 'OR', 'rules' => [
                        ['id' => 'booking_status', 'operator' => 'equal', 'value' => 'approved'],
                        ['id' => 'booking_title', 'operator' => 'equal', 'value' => 'MISSING'],
                    ]],
                ]]],
        ], 'record_selection' => ['meeting' => [
            'date_field' => 'starts_at', 'direction' => 'latest', 'ties' => 'one',
        ]], 'join_filters' => ['meeting' => $this->filter('starts_at', 'greater', '2026-11-18 00:00:00')],
            'filters' => $this->filter('room_code', 'equal', 'TEST.101')];
        self::assertSame([['c0' => 'TEST.101', 'c1' => 0]], $this->service($s)->preview($d, 0)['items']);
        $plan = new ReportSelectCompiler($s->sql)->compile(
            new ReportQuery($d, AcademicReportSchema::datasets()['rooms']),
        );
        self::assertSame(
            ['approved', 'MISSING', '2026-11-18 00:00:00', '2026-11-18 00:00:00', 'TEST.101'],
            $plan['parameters']
        );
        $d['record_selection']['meeting']['direction'] = 'earliest';
        self::assertSame([['c0' => 'TEST.101', 'c1' => 1]], $this->service($s)->preview($d, 0)['items']);
    }

    public function testLiveSummariesChangeWhileSharedStaticCapturesAndSourceAuthorityStayFixed(): void
    {
        $s = new SisFixture();
        $section = $s->section(fee: 0);
        $this->enrollment($s, $section->id, 'A');
        $service = $this->service($s);
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional conditional counts', $this->definition());
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $this->enrollment($s, $section->id, 'W');
        self::assertSame(1, $service->preview($this->definition(), 0)['items'][0]['c2']);
        self::assertSame(['Active', 'Withdrawn'], $service->view($id)['conditional_columns']);
        $reader = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:catalog:manage', 'sis:student:read'];
        self::assertSame(0, $service->run($id, 0)['items'][0]['c2']);
        $s->access->grants = ['sis:reporting:run', 'sis:catalog:manage'];
        $this->expectException(AccessDeniedException::class);
        $service->run($id, 0);
    }

    public function testFilterOnlyNamedCopiesRequireTheirOwnSourcesAndDoNotNeedOutputFields(): void
    {
        $s = new SisFixture();
        $section = $s->section(fee: 0);
        $this->enrollment($s, $section->id, 'W');
        $d = ['dataset' => 'sections', 'aliases' => [
            'copy1' => ['relation' => 'enrollment', 'label' => 'Other registrations'],
        ], 'columns' => [
            ['field' => 'section_code', 'label' => 'Sections with withdrawals', 'aggregate' => 'count_distinct',
                'conditions' => $this->filter('copy1__enrollment_status', 'equal', 'W')],
        ]];
        self::assertSame([['c0' => 1]], $this->service($s)->preview($d, 0)['items']);
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:catalog:manage'];
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->preview($d, 0);
    }

    public function testConditionsAreBoundedSharedAcrossColumnsAndCannotTurnSummariesIntoPopulations(): void
    {
        $schema = AcademicReportSchema::datasets()['sections'];
        $d = $this->definition();
        foreach (['none', 'empty', 'unknown', 'budget'] as $case) {
            $bad = $d;
            if ($case === 'none') {
                $bad['columns'][1]['aggregate'] = 'none';
            }
            if ($case === 'empty') {
                $bad['columns'][1]['conditions']['rules'] = [];
            }
            if ($case === 'unknown') {
                $bad['columns'][1]['conditions']['rules'][0]['id'] = 'raw_event';
            }
            if ($case === 'budget') {
                $active = $d['columns'][1]['conditions']['rules'][0];
                $withdrawn = $d['columns'][2]['conditions']['rules'][0];
                $bad['columns'][1]['conditions']['rules'] = array_fill(0, 30, $active);
                $bad['columns'][2]['conditions']['rules'] = array_fill(0, 11, $withdrawn);
            }
            try {
                new ReportQuery($bad, $schema);
                self::fail('Invalid or unbounded column conditions were accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(ValidationException::class);
        new ReportQuery($d, $schema)->studentPopulation();
    }
}
