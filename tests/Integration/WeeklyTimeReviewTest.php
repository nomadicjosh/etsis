<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Domain\HumanResources\Repository\TimeRepository;
use Domain\HumanResources\TimeEntry;
use Domain\HumanResources\ValueObject\TimeReviewWeek;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlTimeRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class WeeklyTimeReviewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function slot(string $start, string $end): TimeSlot
    {
        return new TimeSlot(new DateTimeImmutable($start), new DateTimeImmutable($end));
    }

    public function testWeeklyReviewClipsOvernightWorkAndKeepsRejectedTimeSeparateWithoutWritingFacts(): void
    {
        $s = new SisFixture();
        self::assertSame(['employee_id', 'starts_at', 'id'], array_column(
            $s->connection->pdo->query('PRAGMA index_info(sts_employee_work)')->fetchAll(\PDO::FETCH_ASSOC),
            'name'
        ));
        $s->clock->time = new DateTimeImmutable('2026-08-12T10:00:00', new DateTimeZone('America/Los_Angeles'));
        $service = $s->timesheetService();
        $id = $service->addEmployee($s->student()->personId, 'Fictional assistant');
        $service->submit($id, $this->slot('2026-08-09T23:00:00-07:00', '2026-08-10T01:00:00-07:00'));
        $approved = $service->submit($id, $this->slot('2026-08-10T23:00:00-07:00', '2026-08-11T02:00:00-07:00'));
        $rejected = $service->submit($id, $this->slot('2026-08-12T09:00:00-07:00', '2026-08-12T11:00:00-07:00'));
        $service->submit($id, $this->slot('2026-08-16T23:00:00-07:00', '2026-08-17T01:00:00-07:00'));
        // Earlier and later intervals are excluded, even for the same employee.
        $service->submit($id, $this->slot('2026-08-09T21:00:00-07:00', '2026-08-09T23:00:00-07:00'));
        $service->submit($id, $this->slot('2026-08-17T01:00:00-07:00', '2026-08-17T02:00:00-07:00'));
        $s->access->actor = $s->user();
        $service->decide($approved->id, true, 0);
        $service->decide($rejected->id, false, 0);
        $other = $service->addEmployee($s->student()->personId, 'Fictional other employee');
        $service->submit($other, $this->slot('2026-08-12T09:00:00-07:00', '2026-08-12T10:00:00-07:00'));
        $service->editEmployee(
            $id,
            'Fictional assistant',
            false,
            $service->employeeDetails($id)['revision'],
            'Fictional pause'
        );
        $tables = ['sis_history_events', 'sis_history_operations', 'sis_timesheets', 'sis_employees', 'sis_audit'];
        $before = array_map(fn($t) => $s->sql->rows($t), $tables);
        $week = $service->week($id);
        self::assertSame('2026-08-10', $week['week']);
        self::assertSame('2026-08-16', $week['through']);
        self::assertSame('America/Los_Angeles', $week['timezone']);
        self::assertFalse($week['employee']['active']);
        self::assertSame(['seconds' => 7200, 'entries' => 2], $week['totals']['pending']);
        self::assertSame(['seconds' => 10800, 'entries' => 1], $week['totals']['approved']);
        self::assertSame(['seconds' => 7200, 'entries' => 1], $week['totals']['rejected']);
        self::assertCount(4, $week['items']);
        self::assertCount(7, $week['days']);
        self::assertSame(3600, $week['days'][0]['pending_seconds']);
        self::assertSame(3600, $week['days'][0]['approved_seconds']);
        self::assertSame(7200, $week['days'][1]['approved_seconds']);
        self::assertSame(3600, $week['items'][0]['week_seconds']);
        self::assertSame(7200, $week['items'][0]['duration_seconds']);
        self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
    }

    public function testDaylightSavingUsesActualElapsedTimeAndAdjacentWeekBoundaries(): void
    {
        foreach (
            [['2026-03-08T01:30:00-08:00', '2026-03-08T03:30:00-07:00', 3600],
            ['2026-11-01T00:30:00-07:00', '2026-11-01T02:30:00-08:00', 10800]] as [$start, $end, $seconds]
        ) {
            $s = new SisFixture();
            $s->clock->time = new DateTimeImmutable($start)->setTimezone(new DateTimeZone('America/Los_Angeles'));
            $service = $s->timesheetService();
            $id = $service->addEmployee($s->student()->personId, 'Fictional assistant');
            $service->submit($id, $this->slot($start, $end));
            $week = $service->week($id);
            self::assertSame($seconds, $week['totals']['pending']['seconds']);
            self::assertSame($seconds, $week['days'][6]['pending_seconds']);
            self::assertSame(0, $service->week($id, $week['next'])['totals']['pending']['seconds']);
        }
    }

    public function testOwnerReadRequiresTheCurrentPersonAndDoesNotGrantHrApproval(): void
    {
        $s = new SisFixture();
        $service = $s->timesheetService();
        $id = $service->addEmployee($s->student()->personId, 'Fictional assistant');
        $s->access->staff = false;
        self::assertTrue($service->week($id)['employee']['is_self']);
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $service->week($id);
    }

    public function testAuthorityIsRecheckedAfterQueryAndNoPartialSummaryEscapesTheLimit(): void
    {
        foreach (['limit', 'actor', 'permission', 'other_employee'] as $case) {
            $s = new SisFixture();
            $service = $s->timesheetService();
            $id = $service->addEmployee($s->student($s->user())->personId, 'Fictional assistant');
            $real = new SqlTimeRepository($s->sql);
            $entry = new TimeEntry(
                EntityId::generate(),
                $case === 'other_employee' ? EntityId::generate() : $id,
                $this->slot('2026-08-11T09:00:00-07:00', '2026-08-11T10:00:00-07:00')
            );
            $repository = $this->createMock(TimeRepository::class);
            $repository->method('employee')->willReturnCallback($real->employee(...));
            $repository->expects(self::once())->method('inWindow')->with($id, self::anything(), 501)
                ->willReturnCallback(static function () use ($s, $case, $entry): array {
                    if ($case === 'actor') {
                        $s->access->actor = EntityId::generate();
                    } elseif ($case === 'permission') {
                        $s->access->staff = false;
                    }
                    return array_fill(0, $case === 'limit' ? 501 : 1, $entry);
                });
            try {
                $s->timesheetService(repository: $repository)->week($id, '2026-08-12');
                self::fail('An unauthorized or partial weekly review was returned.');
            } catch (AccessDeniedException | ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testInvalidCalendarDatesHaveASafeWeekFieldError(): void
    {
        foreach (['2026-02-30', '2026-8-12', '', 'not-a-date', '2026-08-12T00:00:00'] as $value) {
            try {
                new TimeReviewWeek($value, new DateTimeZone('UTC'));
                self::fail('An invalid review date was accepted.');
            } catch (ValidationException $error) {
                self::assertSame('week', $error->errors[0]['field']);
            }
        }
    }
}
