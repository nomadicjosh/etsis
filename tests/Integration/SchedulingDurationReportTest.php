<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\Service\ReportSnapshotReader;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingOccurrence;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportLifecycleRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use Infrastructure\Reporting\SqlReportExportCapture;
use Infrastructure\Reporting\SqlReportResultReader;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Database;
use SisFixture;

final class SchedulingDurationReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
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

    private function definition(string $dataset = 'meetings', string $aggregate = 'none'): array
    {
        return ['dataset' => $dataset, 'columns' => [[
            'field' => $dataset === 'sections' ? 'scheduled_elapsed_minutes' : 'elapsed_minutes',
            'label' => 'Scheduled minutes', 'aggregate' => $aggregate,
        ]]];
    }

    private function meeting(
        SisFixture $s,
        string $start = '2026-11-27T10:00:00Z',
        string $end = '2026-11-27T11:00:00Z',
        string $status = 'approved',
        ?EntityId $room = null,
        ?EntityId $publication = null,
    ): Booking {
        $booking = new Booking(
            EntityId::generate(),
            $room,
            $s->access->actor,
            'Fictional instructional meeting',
            [BookingOccurrence::plan(new TimeSlot(new DateTimeImmutable($start), new DateTimeImmutable($end)))],
            $status,
            sectionPublicationId: $publication,
        );
        new SqlBookingRepository($s->sql)->add($booking);
        return $booking;
    }

    public static function intervals(): iterable
    {
        yield 'one second without Julian rounding' => ['2026-11-27T10:00:00Z', '2026-11-27T10:00:01Z', 1 / 60];
        yield 'fractional minute' => ['2026-11-27T10:00:00Z', '2026-11-27T10:50:30Z', 50.5];
        yield 'year boundary' => ['2026-12-31T23:30:00Z', '2027-01-01T01:00:00Z', 90.0];
        yield 'leap day' => ['2028-02-28T23:30:00Z', '2028-03-01T00:30:00Z', 1500.0];
        yield 'spring clock transition' => ['2026-03-08T01:30:00-08:00', '2026-03-08T03:30:00-07:00', 60.0];
        yield 'fall repeated clock hour' => ['2026-11-01T01:30:00-07:00', '2026-11-01T01:30:00-08:00', 60.0];
        yield 'offset normalization' => ['2026-11-27T10:00:00+05:30', '2026-11-27T05:30:00Z', 60.0];
    }

    #[DataProvider('intervals')]
    public function testElapsedUtcMinutesKeepSecondsAndCalendarBoundaries(
        string $start,
        string $end,
        float $expected,
    ): void {
        $s = new SisFixture();
        $this->meeting($s, $start, $end);
        $before = $s->sql->rows('sis_history_events');
        $result = $this->service($s)->preview($this->definition(), 0);
        self::assertEqualsWithDelta($expected, $result['items'][0]['c0'], 0.00000001);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }

    public function testNonpositiveAndUnmatchedIntervalsStayNullInsteadOfInventingZeroMinutes(): void
    {
        $s = new SisFixture();
        $s->room('EMPTY', 20);
        $service = $this->service($s);
        foreach (['none', 'sum', 'avg'] as $aggregate) {
            self::assertNull($service->preview($this->definition('rooms', $aggregate), 0)['items'][0]['c0']);
        }
        $meeting = $this->meeting($s);
        foreach (['2026-11-27 10:00:00', '2026-11-26 10:00:00'] as $end) {
            // An inconsistent fictional old projection must not become a negative or zero duration.
            $s->sql->update('sis_booking_slots', ['ends_at' => $end], ['booking_id' => $meeting->id->value]);
            self::assertNull($service->preview($this->definition(), 0)['items'][0]['c0']);
        }
    }

    public function testApprovalCancellationNumericFiltersConditionalSummariesAndHavingRemainExplicit(): void
    {
        $s = new SisFixture();
        $this->meeting($s, end: '2026-11-27T10:50:30Z');
        $this->meeting($s, status: 'requested');
        $this->meeting($s, status: 'cancelled');
        $cancelled = $this->meeting($s);
        $s->sql->update('sis_booking_slots', ['cancelled_at' => '2026-11-26 09:00:00'], [
            'booking_id' => $cancelled->id->value,
        ]);
        $service = $this->service($s);
        self::assertSame(230.5, $service->preview($this->definition(aggregate: 'sum'), 0)['items'][0]['c0']);
        $d = $this->definition(aggregate: 'sum');
        $d['columns'][0]['conditions'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'booking_status', 'operator' => 'equal', 'value' => 'approved'],
            ['id' => 'cancelled_at', 'operator' => 'is_null'],
        ]];
        $d['having'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'c0', 'operator' => 'greater', 'value' => 50],
        ]];
        self::assertSame(50.5, $service->preview($d, 0)['items'][0]['c0']);
        $d['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'elapsed_minutes', 'operator' => 'greater_or_equal', 'value' => 55],
        ]];
        self::assertSame([], $service->preview($d, 0)['items']);
        $d = $this->definition(aggregate: 'avg');
        $d['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'elapsed_minutes', 'operator' => 'between', 'value' => [50, 51]],
        ]];
        self::assertSame(50.5, $service->preview($d, 0)['items'][0]['c0']);
    }

    public function testCalendarGroupingMinMaxAndNumericHourConversionsUseTheExistingCompiler(): void
    {
        $s = new SisFixture();
        $this->meeting($s);
        $this->meeting($s, '2026-11-28T10:00:00Z', '2026-11-28T11:30:00Z');
        $this->meeting($s, '2026-12-01T23:00:00Z', '2026-12-02T00:15:00Z');
        $d = $this->definition(aggregate: 'sum');
        $d['columns'][0]['calculation'] = ['operator' => '/', 'value' => '60'];
        array_unshift($d['columns'], [
            'field' => 'starts_at', 'label' => 'Month', 'aggregate' => 'none', 'date_bucket' => 'month',
        ]);
        foreach (['min', 'max', 'avg'] as $aggregate) {
            $d['columns'][] = ['field' => 'elapsed_minutes', 'label' => $aggregate, 'aggregate' => $aggregate];
        }
        $d['sort'] = [['column' => 0, 'direction' => 'asc']];
        $result = $this->service($s)->preview($d, 0)['items'];
        self::assertSame([
            ['c0' => '2026-11', 'c1' => 2.5, 'c2' => 60.0, 'c3' => 90.0, 'c4' => 75.0],
            ['c0' => '2026-12', 'c1' => 1.25, 'c2' => 75.0, 'c3' => 75.0, 'c4' => 75.0],
        ], $result);
    }

    public function testRoomAndCopiedMeetingSummariesKeepTheirOwnDeclaredGrain(): void
    {
        $s = new SisFixture();
        $room = $s->room('CLASSROOM', 20);
        $this->meeting($s, room: $room);
        $this->meeting($s, '2026-11-28T10:00:00Z', '2026-11-28T11:30:00Z', room: $room);
        $d = $this->definition('rooms', 'sum');
        self::assertSame(150.0, $this->service($s)->preview($d, 0)['items'][0]['c0']);
        $d['aliases'] = ['copy1' => ['relation' => 'meeting', 'label' => 'Second occurrence branch']];
        $d['columns'][0]['field'] = 'copy1__elapsed_minutes';
        $query = new ReportQuery($d, AcademicReportSchema::datasets()['rooms']);
        self::assertSame('copy1', $query->schema['fields']['copy1__elapsed_minutes']['quantity_grain']);
        self::assertSame(150.0, $this->service($s)->preview($d, 0)['items'][0]['c0']);
        $d['columns'][0]['aggregate'] = 'avg';
        self::assertSame(75.0, $this->service($s)->preview($d, 0)['items'][0]['c0']);
        $d['record_selection'] = ['copy1' => [
            'date_field' => 'copy1__starts_at', 'direction' => 'latest', 'ties' => 'one',
        ]];
        self::assertSame(90.0, $this->service($s)->preview($d, 0)['items'][0]['c0']);
    }

    public static function multiplyingDefinitions(): iterable
    {
        $d = ['dataset' => 'rooms', 'columns' => [[
            'field' => 'elapsed_minutes', 'label' => 'Minutes', 'aggregate' => 'sum',
        ]], 'aliases' => ['copy1' => ['relation' => 'meeting', 'label' => 'Other meetings']]];
        yield 'explicit second branch' => [$d + ['joins' => ['copy1' => 'left']]];
        yield 'hidden filter branch' => [$d + ['filters' => ['condition' => 'AND', 'rules' => [
            ['id' => 'copy1__starts_at', 'operator' => 'is_not_null'],
        ]]]];
        $conditional = $d;
        $conditional['columns'][0]['conditions'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'copy1__elapsed_minutes', 'operator' => 'greater', 'value' => 0],
        ]];
        yield 'conditional hidden branch' => [$conditional];
        $nested = $d;
        $nested['columns'][0]['calculation'] = ['operator' => '/', 'group' => [
            'left' => ['field' => 'copy1__elapsed_minutes'], 'operator' => '+', 'right' => ['value' => '1'],
        ]];
        yield 'nested numeric branch' => [$nested];
        $tied = $d + ['record_selection' => ['copy1' => [
            'date_field' => 'copy1__starts_at', 'direction' => 'latest', 'ties' => 'all',
        ]]];
        yield 'latest tied rows still multiply' => [$tied];
        yield 'unrelated section registrations' => [[
            'dataset' => 'sections', 'joins' => ['enrollment' => 'left'], 'columns' => [[
                'field' => 'scheduled_elapsed_minutes', 'label' => 'Minutes', 'aggregate' => 'avg',
            ]],
        ]];
        yield 'original branch multiplies a copied measure' => [array_replace($d, [
            'joins' => ['meeting' => 'left'], 'columns' => [[
                'field' => 'copy1__elapsed_minutes', 'label' => 'Minutes', 'aggregate' => 'sum',
            ]],
        ])];
    }

    #[DataProvider('multiplyingDefinitions')]
    public function testMultiplyingBranchesCannotInflateTotalsOrAverages(array $definition): void
    {
        $this->expectException(ValidationException::class);
        new ReportQuery($definition, AcademicReportSchema::datasets()[$definition['dataset']]);
    }

    public function testExistenceAndOneRowSelectionCanRestrictAnotherBranchWithoutMultiplyingMinutes(): void
    {
        $s = new SisFixture();
        $room = $s->room('CLASSROOM', 20);
        $this->meeting($s, room: $room);
        $this->meeting($s, '2026-11-28T10:00:00Z', '2026-11-28T11:30:00Z', room: $room);
        $d = $this->definition('rooms', 'sum');
        $d['aliases'] = ['copy1' => ['relation' => 'meeting', 'label' => 'Qualifying meeting']];
        $d['existence'] = ['copy1' => ['mode' => 'any', 'filters' => ['condition' => 'AND', 'rules' => [
            ['id' => 'copy1__elapsed_minutes', 'operator' => 'greater', 'value' => 80],
        ]]]];
        $service = $this->service($s);
        self::assertSame(150.0, $service->preview($d, 0)['items'][0]['c0']);
        $d['joins'] = ['copy1' => 'left'];
        $d['record_selection'] = ['copy1' => [
            'date_field' => 'copy1__starts_at', 'direction' => 'latest', 'ties' => 'one',
        ]];
        self::assertSame(150.0, $service->preview($d, 0)['items'][0]['c0']);
    }

    public function testSectionMeetingsFollowOnlyTheSelectedOfferingWithoutCertifyingPublishedInstruction(): void
    {
        $c = new \CatalogWorkflowFixture();
        $s = $c->sis;
        $offerings = $c->offerings();
        $plan = $c->plan();
        $review = $offerings->review($plan);
        $operation = new EntityId($review['operation_id']);
        $offerings->save($operation, $plan, $review['revision']);
        $publication = $c->publicationReview($offerings, $operation);
        $offerings->publish($operation, $publication['review_token']);
        $section = $plan->sections[0]->id;
        $selected = $s->sql->one('sis_section_offering_versions', ['id' => $section->value]);
        foreach ([50, 75] as $minutes) {
            $end = new DateTimeImmutable('2026-11-27T10:00:00Z')->modify('+' . $minutes . ' minutes');
            $this->meeting($s, end: $end->format(DATE_ATOM), publication: new EntityId($selected['publication_id']));
        }
        $d = $this->definition('sections', 'sum');
        $d['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'section_code', 'operator' => 'equal',
                'value' => $s->sql->one('sis_sections', ['id' => $section->value])['code']],
        ]];
        $service = $this->service($s);
        $before = $s->sql->rows('sis_history_events');
        self::assertSame(125.0, $service->preview($d, 0)['items'][0]['c0']);
        // Removing a fictional current selection never adopts another offering's bookings.
        $s->sql->execute('DELETE FROM sis_section_offering_versions WHERE id = ?', [$section->value]);
        self::assertNull($service->preview($d, 0)['items'][0]['c0']);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }

    public function testSavedDynamicStaticSharedAndCompleteCsvRetainTheExistingSourceContract(): void
    {
        $s = new SisFixture();
        $this->meeting($s, end: '2026-11-27T10:50:30Z');
        $d = $this->definition(aggregate: 'sum');
        $service = $this->service($s);
        $report = EntityId::generate();
        $service->save($report, EntityId::generate(), -1, 'Fictional scheduled duration', $d);
        self::assertSame($d, $service->view($report)['definition']);
        $recipient = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($report, $recipient);
        $service->configureReport($report, EntityId::generate(), 0, 'static', null);
        $this->meeting($s);
        self::assertSame(110.5, $service->preview($d, 0)['items'][0]['c0']);
        self::assertSame(50.5, $service->run($report, 0)['items'][0]['c0']);
        $capture = new SqlReportExportCapture(
            new SqlReportResultReader($s->connection->queryBuilder(), new SqlRelationalReportSource($s->sql)),
            new ReportSnapshotReader(new SqlReportLifecycleRepository($s->sql), $s->dropCipher),
            $s->dropCipher,
        );
        $before = $s->sql->rows('sis_history_events');
        foreach (['dynamic' => 110.5, 'static' => 50.5] as $mode => $expected) {
            $artifact = $capture->capture(
                EntityId::generate(),
                $report,
                new ReportQuery($d, AcademicReportSchema::datasets()['meetings']),
                $mode === 'static' ? new SqlReportLifecycleRepository($s->sql)->get($report) : null,
            );
            $packet = json_decode($s->dropCipher->open($artifact->chunks[1]), true, flags: JSON_THROW_ON_ERROR);
            self::assertStringContainsString((string) $expected, $packet['content']);
        }
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $s->access->actor = $recipient;
        self::assertSame(50.5, $service->run($report, 0)['items'][0]['c0']);
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run'];
        $this->expectException(AccessDeniedException::class);
        $service->run($report, 0);
    }

    public function testHiddenSchedulingFieldsAndCopiedBranchesKeepIndependentSourcePermissions(): void
    {
        $s = new SisFixture();
        $visible = array_column($this->service($s)->catalog()['datasets'], null, 'id');
        $field = $visible['meetings']['fields']['elapsed_minutes'];
        self::assertSame('double', $field['type']);
        self::assertStringContainsString('including breaks', $field['help']);
        self::assertArrayNotHasKey('expression', $field);
        self::assertArrayNotHasKey('quantity_grain', $field);
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:catalog:manage', 'sis:student:read'];
        $service = $this->service($s);
        $catalog = array_column($service->catalog()['datasets'], null, 'id');
        self::assertArrayNotHasKey('meetings', $catalog);
        self::assertArrayNotHasKey('scheduled_elapsed_minutes', $catalog['sections']['fields']);
        $d = ['dataset' => 'sections', 'columns' => [[
            'field' => 'section_code', 'label' => 'Section', 'aggregate' => 'none',
        ]], 'aliases' => ['copy1' => ['relation' => 'scheduled_booking', 'label' => 'Copied meetings']],
        'filters' => ['condition' => 'AND', 'rules' => [
            ['id' => 'copy1__scheduled_elapsed_minutes', 'operator' => 'greater', 'value' => 30],
        ]]];
        $this->expectException(AccessDeniedException::class);
        $service->preview($d, 0);
    }

    public static function dialects(): iterable
    {
        yield 'MySQL' => ['mysql', 'TIMESTAMPDIFF(SECOND'];
        yield 'PostgreSQL' => ['pgsql', 'EXTRACT(EPOCH'];
        yield 'SQLite' => ['sqlite', "STRFTIME('%s'"];
        yield 'SQL Server' => ['sqlsrv', 'DATEDIFF_BIG(SECOND'];
        yield 'Oracle' => ['oci', 'TO_DATE('];
    }

    #[DataProvider('dialects')]
    public function testAllFiveDialectsCompileBoundHiddenCopiedAndSelectedDurationExpressions(
        string $driver,
        string $function,
    ): void {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn($driver);
        $connection = $this->createStub(Connection::class);
        $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string => match ($driver) {
            'mysql' => '`' . $name . '`', 'sqlsrv' => '[' . $name . ']', default => '"' . $name . '"',
        });
        $db = $this->createStub(Database::class);
        $db->method('getConnection')->willReturn($connection);
        $d = $this->definition('rooms', 'sum');
        $d['aliases'] = ['copy1' => ['relation' => 'meeting', 'label' => 'Selected occurrences']];
        $d['columns'][0]['field'] = 'copy1__elapsed_minutes';
        $d['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'copy1__elapsed_minutes', 'operator' => 'greater', 'value' => 50.5],
        ]];
        $compiled = new ReportSelectCompiler(new SqlRecords($db))->compile(
            new ReportQuery($d, AcademicReportSchema::datasets()['rooms']),
        );
        self::assertStringContainsString($function, $compiled['sql']);
        self::assertStringContainsString('60.0', $compiled['sql']);
        self::assertStringContainsString('ELSE NULL END', $compiled['sql']);
        self::assertStringNotContainsString('50.5', $compiled['sql']);
        self::assertStringNotContainsString('sis_history', $compiled['sql']);
        self::assertSame([50.5], $compiled['parameters']);
    }
}
