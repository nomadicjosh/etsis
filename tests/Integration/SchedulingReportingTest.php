<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingOccurrence;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class SchedulingReportingTest extends TestCase
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

    private function definition(string $dataset, array $fields): array
    {
        return ['dataset' => $dataset, 'columns' => array_map(
            static fn(string $id): array => ['field' => $id, 'label' => $id, 'aggregate' => 'none'],
            $fields,
        )];
    }

    private function meeting(
        SisFixture $s,
        ?EntityId $room,
        string $start = '2026-11-19T10:00:00-08:00',
        string $status = 'approved',
        ?EntityId $publication = null,
    ): Booking {
        $at = new DateTimeImmutable($start);
        $booking = new Booking(
            EntityId::generate(),
            $room,
            $s->access->actor,
            'Fictional seminar',
            [BookingOccurrence::plan(new TimeSlot($at, $at->modify('+1 hour')))],
            $status,
            sectionPublicationId: $publication,
        );
        new SqlBookingRepository($s->sql)->add($booking);
        return $booking;
    }

    private function activeRules(): array
    {
        return ['condition' => 'AND', 'rules' => [
            ['id' => 'booking_status', 'operator' => 'equal', 'value' => 'approved'],
            ['id' => 'cancelled_at', 'operator' => 'is_null'],
        ]];
    }

    public function testMeetingReportsKeepUtcRoomBuildingAndSeparateCancellationFactsWithoutHistoryWrites(): void
    {
        $s = new SisFixture();
        $room = $s->room('SCI.101', 30);
        $this->meeting($s, $room);
        $this->meeting($s, null, status: 'requested');
        $cancelled = $this->meeting($s, $room, status: 'cancelled');
        $s->sql->update(
            'sis_booking_slots',
            ['cancelled_at' => '2026-11-18 15:00:00'],
            ['booking_id' => $cancelled->id->value]
        );
        $before = $s->sql->rows('sis_history_events');
        $d = $this->definition('meetings', [
            'booking_status', 'starts_at', 'room_code', 'building_name', 'cancelled_at',
        ]);
        $rows = array_column($this->service($s)->preview($d, 0)['items'], null, 'c0');
        self::assertCount(3, $rows);
        self::assertSame('2026-11-19 18:00:00', $rows['approved']['c1']);
        self::assertSame('SCI.101', $rows['approved']['c2']);
        self::assertSame('Fictional campus building', $rows['approved']['c3']);
        self::assertNull($rows['requested']['c2']);
        self::assertSame('2026-11-18 15:00:00', $rows['cancelled']['c4']);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $schema = AcademicReportSchema::datasets()['meetings'];
        foreach ($schema['fields'] as $field) {
            self::assertNotContains($field['column'], ['owner_user_id', 'cancelled_by', 'request_hash']);
        }
        self::assertArrayNotHasKey('student_population', $schema);
    }

    public function testApprovedScheduleExcludesRequestedWholeBookingAndIndividualCancellations(): void
    {
        $s = new SisFixture();
        $this->meeting($s, null);
        $this->meeting($s, null, status: 'requested');
        $this->meeting($s, null, status: 'cancelled');
        $cancelled = $this->meeting($s, null);
        $s->sql->update(
            'sis_booking_slots',
            ['cancelled_at' => '2026-11-18 15:00:00'],
            ['booking_id' => $cancelled->id->value]
        );
        $d = $this->definition('meetings', ['booking_id', 'starts_at']);
        $d['filters'] = $this->activeRules();
        self::assertCount(1, $this->service($s)->preview($d, 0)['items']);
    }

    public function testRoomsKeepEmptyBuildingsAndCountsDoNotLoseZeroMeetingRooms(): void
    {
        $s = new SisFixture();
        $busy = $s->room('BUSY', 20);
        $quiet = new EntityId($s->room('QUIET', 10)->value);
        $s->sql->update('sis_rooms', ['building_id' => null], ['id' => $quiet->value]);
        $this->meeting($s, $busy);
        $this->meeting($s, $busy, '2026-11-20T10:00:00-08:00');
        $d = $this->definition('rooms', ['room_code', 'building_name', 'meeting_id']);
        $d['columns'][2]['aggregate'] = 'count_distinct';
        $rows = array_column($this->service($s)->preview($d, 0)['items'], null, 'c0');
        self::assertSame(2, $rows['BUSY']['c2']);
        self::assertSame(0, $rows['QUIET']['c2']);
        self::assertNull($rows['QUIET']['c1']);
    }

    public function testRoomMatchingConditionsShareTheOccurrenceAndRespectUtcWindowAndApproval(): void
    {
        $s = new SisFixture();
        $busy = $s->room('BUSY', 20);
        $quiet = $s->room('QUIET', 10);
        $this->meeting($s, $busy, '2026-11-18T23:30:00+00:00');
        $this->meeting($s, $quiet, status: 'requested');
        $this->meeting($s, $quiet, '2026-11-20T10:00:00-08:00');
        $d = $this->definition('rooms', ['room_code']);
        $filter = $this->activeRules();
        $filter['rules'][] = ['id' => 'starts_at', 'operator' => 'less', 'value' => '2026-11-20 00:00:00'];
        $filter['rules'][] = ['id' => 'ends_at', 'operator' => 'greater', 'value' => '2026-11-19 00:00:00'];
        $d['existence'] = ['meeting' => ['mode' => 'none', 'filters' => $filter]];
        self::assertSame([['c0' => 'QUIET']], $this->service($s)->preview($d, 0)['items']);
    }

    public function testLatestRoomMeetingUsesTimeAndIndexedParentLookupWithoutReplayingStreams(): void
    {
        $s = new SisFixture();
        $room = $s->room('SCI.101', 30);
        $this->meeting($s, $room);
        $this->meeting($s, $room, '2026-11-20T10:00:00-08:00');
        $d = $this->definition('rooms', ['room_code', 'starts_at']);
        $d['record_selection'] = ['meeting' => [
            'date_field' => 'starts_at', 'direction' => 'latest', 'ties' => 'one',
        ]];
        self::assertSame('2026-11-20 18:00:00', $this->service($s)->preview($d, 0)['items'][0]['c1']);
        $plan = new ReportSelectCompiler($s->sql)->compile(
            new ReportQuery($d, AcademicReportSchema::datasets()['rooms']),
        );
        $steps = $s->sql->fetch('EXPLAIN QUERY PLAN ' . $plan['sql'], $plan['parameters']);
        foreach (['pick_row', 'rank_row'] as $alias) {
            self::assertNotEmpty(array_filter($steps, static fn(array $row): bool =>
                str_contains($row['detail'], 'SEARCH ' . $alias) && str_contains($row['detail'], 'room_id=?')));
        }
        self::assertStringNotContainsString('sis_history', $plan['sql']);
    }

    public function testSharedFrozenScheduleRetainsCapturedRowsAndStillRequiresSchedulingAuthority(): void
    {
        $s = new SisFixture();
        $this->meeting($s, null);
        $d = $this->definition('meetings', ['booking_title', 'starts_at']);
        $service = $this->service($s);
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional schedule', $d);
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $this->meeting($s, null, '2026-11-20T10:00:00-08:00');
        self::assertCount(2, $service->preview($d, 0)['items']);
        $reader = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:scheduling:manage'];
        self::assertCount(1, $service->run($id, 0)['items']);
        self::assertSame('2026-11-19 18:00:00', $service->run($id, 0)['items'][0]['c1']);
        $s->access->grants = ['sis:reporting:run'];
        $this->expectException(AccessDeniedException::class);
        $service->run($id, 0);
    }

    public function testHiddenOfferingFieldsRequireAcademicAuthorityAndMeetingsRequireScheduling(): void
    {
        $s = new SisFixture();
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:scheduling:manage'];
        $service = $this->service($s);
        $catalog = array_column($service->catalog()['datasets'], null, 'id');
        self::assertArrayHasKey('rooms', $catalog);
        self::assertArrayNotHasKey('course_title', $catalog['meetings']['fields']);
        $d = $this->definition('meetings', ['booking_title']);
        $d['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'course_title', 'operator' => 'equal', 'value' => 'Protected academic title'],
        ]];
        try {
            $service->preview($d, 0);
            self::fail('A hidden academic filter bypassed its authority.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:catalog:manage'];
        $catalog = array_column($service->catalog()['datasets'], null, 'id');
        self::assertArrayNotHasKey('rooms', $catalog);
        self::assertArrayNotHasKey('scheduled_starts_at', $catalog['sections']['fields']);
        $d = $this->definition('sections', ['section_code']);
        $d['record_selection'] = ['scheduled_meeting' => [
            'date_field' => 'scheduled_starts_at', 'direction' => 'latest', 'ties' => 'one',
        ]];
        $this->expectException(AccessDeniedException::class);
        $service->preview($d, 0);
    }

    public function testTimestampComparisonsAreStrictTypedAndBoundIncludingSummaryConditions(): void
    {
        $s = new SisFixture();
        $this->meeting($s, null);
        $d = $this->definition('meetings', ['starts_at']);
        $d['columns'][0]['aggregate'] = 'max';
        $d['having'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'c0', 'operator' => 'between', 'value' => ['2026-11-19 00:00:00', '2026-11-19 23:59:59']],
        ]];
        self::assertSame([['c0' => '2026-11-19 18:00:00']], $this->service($s)->preview($d, 0)['items']);
        $d = $this->definition('meetings', ['starts_at']);
        foreach (
            ['2026-02-30 10:00:00', '2026-11-19 24:00:00', '2026-11-19T10:00:00',
            '2026-11-19 10:00', '2026-11-19 10:00:00-08:00', 'tomorrow'] as $value
        ) {
            $d['filters'] = ['condition' => 'AND', 'rules' => [
                ['id' => 'starts_at', 'operator' => 'equal', 'value' => $value],
            ]];
            try {
                $this->service($s)->preview($d, 0);
                self::fail('An invalid UTC timestamp was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $d['filters']['rules'][0]['value'] = '2026-11-19 18:00:00';
        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci'] as $driver) {
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => match ($driver) {
                    'mysql' => '`' . $name . '`',
                    'sqlsrv' => '[' . $name . ']',
                    default => '"' . $name . '"',
                },
            );
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method('getConnection')->willReturn($connection);
            $plan = new ReportSelectCompiler(new \Infrastructure\Persistence\SqlRecords($db))->compile(
                new ReportQuery($d, AcademicReportSchema::datasets()['meetings']),
            );
            self::assertSame(['2026-11-19 18:00:00'], $plan['parameters']);
            self::assertStringNotContainsString('2026-11-19', $plan['sql']);
        }
    }

    public function testLinkedOfferingLabelsRemainOriginalAndSectionReportsFollowOnlyTheSelectedOffering(): void
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
        $original = $s->sql->one('sis_section_offering_versions', ['id' => $section->value]);
        $this->meeting($s, null, publication: new EntityId($original['publication_id']));
        $d = $this->definition('meetings', ['section_code', 'course_title', 'term_code']);
        $before = $this->service($s)->preview($d, 0)['items'];
        self::assertNotNull($before[0]['c0']);
        $s->sql->update('sis_sections', ['code' => 'LATER.DRAFT'], ['id' => $section->value]);
        self::assertSame($before, $this->service($s)->preview($d, 0)['items']);
        $bySection = $this->definition('sections', ['section_code', 'scheduled_meeting_id']);
        $bySection['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'section_code', 'operator' => 'equal', 'value' => 'LATER.DRAFT'],
        ]];
        self::assertNotNull($this->service($s)->preview($bySection, 0)['items'][0]['c1']);
        // Fictional reader projection change only; no historical decision is invented by this test.
        $s->sql->execute('DELETE FROM sis_section_offering_versions WHERE id = ?', [$section->value]);
        self::assertNull($this->service($s)->preview($bySection, 0)['items'][0]['c1']);
        self::assertSame($before, $this->service($s)->preview($d, 0)['items']);
    }
}
