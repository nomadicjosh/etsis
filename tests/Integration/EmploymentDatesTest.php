<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\JobDefinition;
use Domain\HumanResources\ValueObject\EmploymentDates;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventPage;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class EmploymentDatesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    /** @return array{EntityId,JobDefinition} */
    private function setupEmployee(SisFixture $s): array
    {
        $employee = $s->timesheetService()->addEmployee($s->student()->personId, 'Fictional librarian');
        $job = new JobDefinition(
            EntityId::generate(),
            'LIB.ASST',
            'Fictional library assistant',
            'USD',
            'hourly',
            true,
            rateMinor: 2500
        );
        $s->hrLibrary()->save($job, null);
        return [$employee, $job];
    }
    private function change(
        SisFixture $s,
        EntityId $employee,
        EntityId $period,
        int $version,
        string $from,
        ?string $through = null,
        bool $voided = false,
        string $reason = 'Fictional HR decision'
    ): array {
        $review = $s->employmentDates()->review($employee, $period, $version, $from, $through, $voided, $reason);
        $s->employmentDates()->confirm(
            $employee,
            $period,
            $version,
            $from,
            $through,
            $voided,
            $reason,
            $review['position_revision']
        );
        return $review;
    }
    public function testHireEndAndRehireReuseTheEmployeeAndPreserveEveryPersonStudentAndAccountFact(): void
    {
        $s = new SisFixture();
        [$employee] = $this->setupEmployee($s);
        $tables = ['sis_employees', 'sis_persons', 'sis_students', 'users'];
        $before = array_map(fn($table) => $s->sql->rows($table), $tables);
        $events = $s->sql->rows('sis_history_events');
        self::assertSame(0, $s->employmentDates()->get($employee)['version']);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        $period = EntityId::generate();
        $this->change($s, $employee, $period, 0, '2026-01-01');
        $this->change($s, $employee, $period, 1, '2026-01-01', '2026-10-31');
        $this->change($s, $employee, EntityId::generate(), 2, '2027-02-01');
        $dates = $s->employmentDates()->get($employee);
        self::assertSame(3, $dates['version']);
        self::assertCount(2, $dates['periods']);
        self::assertArrayNotHasKey('reason', $dates);
        self::assertSame($before, array_map(fn($table) => $s->sql->rows($table), $tables));
        $after = array_column($s->sql->rows('sis_history_events'), null, 'id');
        foreach ($events as $event) {
            self::assertSame($event, $after[$event['id']]);
        }
        foreach (['sis_employment_dates', 'sis_history_events', 'sis_history_operations', 'sis_audit'] as $table) {
            self::assertStringNotContainsString(
                'Fictional HR decision',
                json_encode($s->sql->rows($table), JSON_THROW_ON_ERROR)
            );
        }
    }
    public function testEndingEmploymentClosesCurrentAndVoidsFutureAssignmentsInOneReviewedConfirmation(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupEmployee($s);
        $period = EntityId::generate();
        $this->change($s, $employee, $period, 0, '2026-01-01');
        $ids = [EntityId::generate(), EntityId::generate(), EntityId::generate()];
        foreach (['2026-01-01', '2026-08-01', '2027-01-01'] as $index => $from) {
            $s->positions()->assign(
                $employee,
                $ids[$index],
                $job->id,
                0,
                null,
                $from,
                null,
                2500,
                null,
                'Fictional position'
            );
        }
        $review = $this->change($s, $employee, $period, 1, '2026-01-01', '2026-11-30');
        self::assertCount(3, $review['positions']);
        self::assertSame('2026-11-30', $s->positions()->get($employee, $ids[0])['valid_through']);
        self::assertSame('2026-11-30', $s->positions()->get($employee, $ids[1])['valid_through']);
        self::assertTrue($s->positions()->get($employee, $ids[2])['voided']);
        // Reopening employment or retrying the older end cannot silently restore or close later choices.
        $this->change($s, $employee, $period, 2, '2026-01-01');
        $events = $s->sql->rows('sis_history_events');
        $s->employmentDates()->confirm(
            $employee,
            $period,
            1,
            '2026-01-01',
            '2026-11-30',
            false,
            'Fictional HR decision',
            $review['position_revision']
        );
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertNull($s->employmentDates()->get($employee)['periods'][0]['through']);
        self::assertTrue($s->positions()->get($employee, $ids[2])['voided']);
    }
    public function testAuditFailureAfterClosingPositionsRollsBackTheEntireEmploymentDecision(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupEmployee($s);
        $period = EntityId::generate();
        $this->change($s, $employee, $period, 0, '2026-01-01');
        $s->positions()->assign(
            $employee,
            EntityId::generate(),
            $job->id,
            0,
            null,
            '2026-01-01',
            null,
            2500,
            null,
            'Fictional position'
        );
        $review = $s->employmentDates()->review(
            $employee,
            $period,
            1,
            '2026-01-01',
            '2026-11-30',
            false,
            'Fictional closure'
        );
        $tables = ['sis_employment_dates', 'sis_employee_positions', 'sis_history_events',
            'sis_history_streams', 'sis_history_operations', 'sis_audit'];
        $before = array_map(fn($t) => $s->sql->rows($t), $tables);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional audit failure'));
        try {
            $s->employmentDates($audit)->confirm(
                $employee,
                $period,
                1,
                '2026-01-01',
                '2026-11-30',
                false,
                'Fictional closure',
                $review['position_revision']
            );
            self::fail('Part of the employment ending committed.');
        } catch (RuntimeException) {
            self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
        }
    }
    public function testChangedOrNewAssignmentsAndStaleDatesRequireAnotherReview(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupEmployee($s);
        $period = EntityId::generate();
        $this->change($s, $employee, $period, 0, '2026-01-01');
        $review = $s->employmentDates()->review(
            $employee,
            $period,
            1,
            '2026-01-01',
            '2026-11-30',
            false,
            'Fictional closure'
        );
        $s->positions()->assign(
            $employee,
            EntityId::generate(),
            $job->id,
            0,
            null,
            '2026-01-01',
            null,
            2500,
            null,
            'Added after review'
        );
        $before = $s->sql->rows('sis_history_events');
        try {
            $s->employmentDates()->confirm(
                $employee,
                $period,
                1,
                '2026-01-01',
                '2026-11-30',
                false,
                'Fictional closure',
                $review['position_revision']
            );
            self::fail('An unreviewed position was closed.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
        $this->expectException(ConcurrentModificationException::class);
        $s->employmentDates()->review($employee, $period, 0, '2026-01-01', null, false, 'Stale dates');
    }
    public function testOverlapInvalidDatesAndMissingReasonsCannotWriteHistory(): void
    {
        $s = new SisFixture();
        [$employee] = $this->setupEmployee($s);
        $this->change($s, $employee, EntityId::generate(), 0, '2026-01-01', '2026-12-31');
        $before = $s->sql->rows('sis_history_events');
        foreach (
            [['2026-12-31', null, 'Overlap'], ['2026-02-30', null, 'Invalid date'],
            ['2027-01-01', '2026-12-31', 'Reversed range'], ['2027-01-01', null, ' ']] as [$from, $through, $reason]
        ) {
            try {
                $s->employmentDates()->review($employee, EntityId::generate(), 1, $from, $through, false, $reason);
                self::fail('Invalid employment dates were reviewed.');
            } catch (ValidationException) {
                self::assertSame($before, $s->sql->rows('sis_history_events'));
            }
        }
    }
    public function testRecordedDatesGateActualLocalWorkAndAllowSubmissionThroughTheLastDay(): void
    {
        $s = new SisFixture();
        [$employee] = $this->setupEmployee($s);
        $this->change($s, $employee, EntityId::generate(), 0, '2026-01-01', '2026-08-10');
        $valid = new TimeSlot(
            new DateTimeImmutable('2026-08-10T23:00:00-07:00'),
            new DateTimeImmutable('2026-08-11T00:00:00-07:00')
        );
        self::assertSame('pending', $s->timesheetService()->submit($employee, $valid)->status);
        $this->expectException(ValidationException::class);
        $s->timesheetService()->submit($employee, new TimeSlot(
            new DateTimeImmutable('2026-08-11T09:00:00-07:00'),
            new DateTimeImmutable('2026-08-11T10:00:00-07:00')
        ));
    }
    public function testEndDateCorrectionPreservesEarlierTimeAndPreventsNewApprovalOutsideRecordedDates(): void
    {
        $s = new SisFixture();
        [$employee] = $this->setupEmployee($s);
        $period = EntityId::generate();
        $this->change($s, $employee, $period, 0, '2026-01-01');
        $entry = $s->timesheetService()->submit($employee, new TimeSlot(
            new DateTimeImmutable('2026-08-11T09:00:00-07:00'),
            new DateTimeImmutable('2026-08-11T10:00:00-07:00')
        ));
        $times = $s->sql->rows('sis_timesheets');
        $this->change($s, $employee, $period, 1, '2026-01-01', '2026-08-10');
        self::assertSame($times, $s->sql->rows('sis_timesheets'));
        $s->access->actor = $s->user();
        $this->expectException(ValidationException::class);
        $s->timesheetService()->decide($entry->id, true, 0);
    }
    public function testOwnersCanReadDatesButCannotChangeThemAndUnrelatedPeopleCannotRead(): void
    {
        $s = new SisFixture();
        [$employee] = $this->setupEmployee($s);
        $this->change($s, $employee, EntityId::generate(), 0, '2026-01-01');
        $s->access->staff = false;
        self::assertCount(1, $s->employmentDates()->get($employee)['periods']);
        try {
            $s->employmentDates()->review($employee, EntityId::generate(), 1, '2027-01-01', null, false, 'Owner edit');
            self::fail('Ownership granted HR maintenance.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $s->employmentDates()->get($employee);
    }
    public function testVoidRestoreAndCorruptProjectionPreserveHistoryRatherThanInventingEarlierDates(): void
    {
        $s = new SisFixture();
        [$employee] = $this->setupEmployee($s);
        $period = EntityId::generate();
        $this->change($s, $employee, $period, 0, '2026-01-01');
        $this->change($s, $employee, $period, 1, '2026-01-01', voided: true);
        self::assertTrue($s->employmentDates()->get($employee)['periods'][0]['voided']);
        $this->change($s, $employee, $period, 2, '2026-01-01');
        self::assertFalse($s->employmentDates()->get($employee)['periods'][0]['voided']);
        $s->sql->update('sis_employment_dates', ['encrypted_dates' => 'tampered'], ['employee_id' => $employee->value]);
        $this->expectException(CorruptHistoryException::class);
        $s->timesheetService()->submit($employee, new TimeSlot(
            new DateTimeImmutable('2026-08-11T09:00:00-07:00'),
            new DateTimeImmutable('2026-08-11T10:00:00-07:00')
        ));
    }
    public function testNewAndReopenedPositionsCannotExtendBeyondRecordedEmployment(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupEmployee($s);
        $this->change($s, $employee, EntityId::generate(), 0, '2026-01-01', '2026-12-31');
        $id = EntityId::generate();
        $s->positions()->assign(
            $employee,
            $id,
            $job->id,
            0,
            null,
            '2026-01-01',
            '2026-12-31',
            null,
            null,
            'Valid interval'
        );
        $this->expectException(ValidationException::class);
        $s->positions()->end($employee, $id, 1, null, 'Unbounded position outside employment');
    }

    public function testEarlierEndedAssignmentsStayUnknownAndLaterEditsDoNotVoidThem(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupEmployee($s);
        $position = EntityId::generate();
        $s->positions()->assign(
            $employee,
            $position,
            $job->id,
            0,
            null,
            '2024-01-01',
            '2024-12-31',
            null,
            null,
            'Earlier position with unknown hire date'
        );
        $period = EntityId::generate();
        $this->change($s, $employee, $period, 0, '2026-01-01');
        $review = $this->change($s, $employee, $period, 1, '2026-01-01', '2026-11-30');
        self::assertSame([], $review['positions']);
        self::assertFalse($s->positions()->get($employee, $position)['voided']);
        self::assertSame(1, $s->positions()->get($employee, $position)['version']);
    }

    public function testAnotherRecordedRehireKeepsItsOwnFuturePositionWhenTheEarlierPeriodIsCorrected(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupEmployee($s);
        $old = EntityId::generate();
        $this->change($s, $employee, $old, 0, '2026-01-01', '2026-12-31');
        $this->change($s, $employee, EntityId::generate(), 1, '2027-02-01');
        $position = EntityId::generate();
        $s->positions()->assign(
            $employee,
            $position,
            $job->id,
            0,
            null,
            '2027-02-01',
            null,
            null,
            null,
            'Position under the recorded rehire'
        );
        $review = $this->change($s, $employee, $old, 2, '2026-01-01', '2026-11-30');
        self::assertSame([], $review['positions']);
        self::assertFalse($s->positions()->get($employee, $position)['voided']);
        self::assertNull($s->positions()->get($employee, $position)['valid_through']);
    }

    public function testCurrentAuthorityIsRecheckedAndTheWholeDecisionRollsBackOnRevocation(): void
    {
        $s = new SisFixture();
        [$employee] = $this->setupEmployee($s);
        $period = EntityId::generate();
        $review = $s->employmentDates()->review($employee, $period, 0, '2026-01-01', null, false, 'Fictional hire');
        $tables = ['sis_employment_dates', 'sis_history_events', 'sis_history_streams',
            'sis_history_operations', 'sis_audit'];
        $before = array_map(fn($t) => $s->sql->rows($t), $tables);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s): void {
            $s->access->staff = false;
        });
        try {
            $s->employmentDates($audit)->confirm(
                $employee,
                $period,
                0,
                '2026-01-01',
                null,
                false,
                'Fictional hire',
                $review['position_revision']
            );
            self::fail('Revoked authority committed an employment decision.');
        } catch (AccessDeniedException) {
            self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
        }
    }

    public function testSelectedDateReplayRefusesOversizedOrIncompleteHistory(): void
    {
        $s = new SisFixture();
        [$employee] = $this->setupEmployee($s);
        foreach ([new EventPage([], null, 2001), new EventPage([], 'stalled', 1)] as $page) {
            $history = $this->createMock(HistoricalEventStore::class);
            $history->expects(self::once())->method('page')->willReturn($page);
            $ledger = new \Domain\HumanResources\Service\EmploymentDatesLedger(
                new \Infrastructure\Persistence\Repository\SqlEmploymentDatesRepository($s->sql, $s->dropCipher),
                $history,
                $s->clock
            );
            try {
                $ledger->inspect($employee);
                self::fail('Incomplete employment history returned eligibility facts.');
            } catch (CorruptHistoryException | HistoryReadLimitException) {
                self::assertTrue(true);
            }
        }
    }

    public function testADeletedProjectionDoesNotTurnTrackedDatesBackIntoUnknownEmployment(): void
    {
        $s = new SisFixture();
        [$employee] = $this->setupEmployee($s);
        $this->change($s, $employee, EntityId::generate(), 0, '2026-01-01');
        $s->sql->execute('DELETE FROM sis_employment_dates WHERE employee_id = ?', [$employee->value]);
        $this->expectException(CorruptHistoryException::class);
        $s->employmentDates()->get($employee);
    }

    public function testAReviewThatExceedsThePositionBatchLimitCannotPartiallyCloseAnything(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupEmployee($s);
        $period = EntityId::generate();
        $this->change($s, $employee, $period, 0, '2026-01-01');
        for ($i = 0; $i < 51; $i++) {
            $s->positions()->assign(
                $employee,
                EntityId::generate(),
                $job->id,
                0,
                null,
                '2026-01-01',
                null,
                null,
                null,
                'Fictional concurrent position ' . $i
            );
        }
        $before = [$s->sql->rows('sis_history_events'), $s->sql->rows('sis_employee_positions')];
        try {
            $s->employmentDates()->review(
                $employee,
                $period,
                1,
                '2026-01-01',
                '2026-11-30',
                false,
                'Fictional closure'
            );
            self::fail('A review exceeded the atomic position bound.');
        } catch (HistoryReadLimitException) {
            self::assertSame($before, [$s->sql->rows('sis_history_events'), $s->sql->rows('sis_employee_positions')]);
        }
    }

    public function testAnotherSuperPersonsDatesRequireSuperAuthority(): void
    {
        $s = new SisFixture();
        [$employee] = $this->setupEmployee($s);
        $roles = new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql);
        $roles->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $s->access->actor,
            $roles->roleNamed('super')->id,
            true
        );
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $s->employmentDates()->get($employee);
    }
}
