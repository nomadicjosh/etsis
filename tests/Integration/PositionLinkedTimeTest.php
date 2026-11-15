<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\JobDefinition;
use Domain\HumanResources\Service\PositionLedger;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlPositionRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class PositionLinkedTimeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    /** @return array{EntityId,JobDefinition,EntityId} */
    private function setupPosition(SisFixture $s, string $from = '2026-01-01', ?string $through = null): array
    {
        $employee = $s->timesheetService()->addEmployee($s->student()->personId, 'General employee label');
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
        $position = EntityId::generate();
        $s->positions()->assign(
            $employee,
            $position,
            $job->id,
            0,
            null,
            $from,
            $through,
            2500,
            2250,
            'Fictional appointment'
        );
        return [$employee, $job, $position];
    }
    private function work(
        string $from = '2026-08-11T09:00:00-07:00',
        string $through = '2026-08-11T10:00:00-07:00'
    ): TimeSlot {
        return new TimeSlot(new DateTimeImmutable($from), new DateTimeImmutable($through));
    }
    public function testOnePositionIsSelectedAutomaticallyAndItsVersionAndTermsRemainHistoricallyBound(): void
    {
        $s = new SisFixture();
        [$employee, $job, $position] = $this->setupPosition($s);
        $events = $s->sql->rows('sis_history_events');
        $entry = $s->timesheetService()->submit($employee, $this->work());
        self::assertSame($position->value, $entry->positionId->value);
        self::assertSame(1, $entry->positionVersion);
        self::assertCount(1, $s->sql->rows('sis_timesheet_positions'));
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        $s->hrLibrary()->save(new JobDefinition(
            $job->id,
            $job->code,
            'Different setup title',
            'USD',
            'hourly',
            true,
            rateMinor: 3500
        ), 0);
        $s->positions()->end($employee, $position, 1, '2026-12-31', 'Later assignment closure');
        $details = $s->timesheetService()->entryDetails($entry->id);
        self::assertSame($job->title, $details['position']['title']);
        self::assertSame(1, $details['position']['accepted_version']);
        self::assertSame(2, $details['position']['current_version']);
        self::assertArrayNotHasKey('rate_minor', $details['position']);
        self::assertStringNotContainsString('Later assignment closure', json_encode($details, JSON_THROW_ON_ERROR));
        $reference = $s->sql->rows('sis_timesheet_positions');
        $s->access->actor = $s->user();
        $s->timesheetService()->decide($entry->id, true, 0);
        self::assertSame($reference, $s->sql->rows('sis_timesheet_positions'));
        self::assertSame(1, $s->timesheetService()->get($entry->id)->positionVersion);
    }
    public function testMultiplePositionsRequireAnExplicitChoiceAndOverlappingEntriesAreStillRejected(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $other = EntityId::generate();
        $s->positions()->assign(
            $employee,
            $other,
            $job->id,
            0,
            null,
            '2026-01-01',
            null,
            3000,
            null,
            'Fictional second job'
        );
        $choices = $s->timesheetService()->workPositions($employee, $this->work());
        self::assertCount(2, $choices['items']);
        self::assertTrue($choices['position_required']);
        try {
            $s->timesheetService()->submit($employee, $this->work());
            self::fail('Ambiguous work was assigned silently.');
        } catch (ValidationException) {
            self::assertSame([], $s->sql->rows('sis_timesheets'));
        }
        $entry = $s->timesheetService()->submit($employee, $this->work(), $other, 1);
        self::assertSame($other->value, $entry->positionId->value);
        $this->expectException(\DomainException::class);
        $s->timesheetService()->submit($employee, $this->work(), $other, 1);
    }
    public function testEarlierAndUnassignedWorkIsNotBackfilledWhenPositionsAreRecordedLater(): void
    {
        $s = new SisFixture();
        $employee = $s->timesheetService()->addEmployee($s->student()->personId, 'General employee label');
        self::assertFalse($s->timesheetService()->workPositions($employee, $this->work())['position_required']);
        $entry = $s->timesheetService()->submit($employee, $this->work());
        self::assertNull($entry->positionId);
        $job = new JobDefinition(EntityId::generate(), 'LIB', 'Fictional job', 'USD', 'hourly', true);
        $s->hrLibrary()->save($job, null);
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
            'Recorded later'
        );
        self::assertNull($s->timesheetService()->entryDetails($entry->id)['position']);
        self::assertNull($s->timesheetService()->entries($employee)['items'][0]['position']);
        self::assertSame([], $s->sql->rows('sis_timesheet_positions'));
        $s->access->actor = $s->user();
        $s->timesheetService()->decide($entry->id, true, 0);
        self::assertNull($s->timesheetService()->get($entry->id)->positionId);
    }
    public function testAssignmentEndingOrVoidingBlocksNewApprovalWithoutRewritingEarlierWork(): void
    {
        foreach (['end', 'void'] as $change) {
            $s = new SisFixture();
            [$employee, , $position] = $this->setupPosition($s);
            $entry = $s->timesheetService()->submit($employee, $this->work(), $position, 1);
            $before = [$s->sql->rows('sis_timesheets'), $s->sql->rows('sis_timesheet_positions')];
            if ($change === 'end') {
                $s->positions()->end($employee, $position, 1, '2026-08-10', 'Corrected actual assignment end');
            } else {
                $s->positions()->setVoid($employee, $position, 1, true, 'Incorrect appointment');
            }
            $s->access->actor = $s->user();
            try {
                $s->timesheetService()->decide($entry->id, true, 0);
                self::fail('Work without current assignment coverage was approved.');
            } catch (ValidationException) {
                self::assertSame($before, [$s->sql->rows('sis_timesheets'), $s->sql->rows('sis_timesheet_positions')]);
            }
            $s->timesheetService()->decide($entry->id, false, 0);
            self::assertSame($before[1], $s->sql->rows('sis_timesheet_positions'));
        }
    }
    public function testAChangedChoiceNeedsFreshReviewEvenWhenTheDatesStillFit(): void
    {
        $s = new SisFixture();
        [$employee, , $position] = $this->setupPosition($s);
        $s->positions()->end($employee, $position, 1, '2026-12-31', 'Scheduled end');
        $this->expectException(ConcurrentModificationException::class);
        $s->timesheetService()->submit($employee, $this->work(), $position, 1);
    }
    public function testMidnightAndDaylightSavingUseInstitutionDatesAndRequireTheFullInterval(): void
    {
        $s = new SisFixture();
        $s->clock->time = new DateTimeImmutable('2026-08-10T10:00:00', new DateTimeZone('America/Los_Angeles'));
        [$employee, , $position] = $this->setupPosition($s, '2026-03-08', '2026-03-08');
        $work = $this->work('2026-03-08T01:30:00-08:00', '2026-03-08T03:30:00-07:00');
        self::assertSame($position->value, $s->timesheetService()->submit($employee, $work)->positionId->value);
        $last = $this->work('2026-03-08T23:00:00-07:00', '2026-03-09T00:00:00-07:00');
        self::assertSame($position->value, $s->timesheetService()->submit($employee, $last)->positionId->value);
        $this->expectException(ValidationException::class);
        $s->timesheetService()->submit(
            $employee,
            $this->work('2026-03-08T23:00:00-07:00', '2026-03-09T00:01:00-07:00')
        );
    }
    public function testOwnersGetPrivateLabelsAndChoicesWithoutCompensationAndOtherPeopleCannotReadThem(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $s->access->staff = false;
        $choices = $s->timesheetService()->workPositions($employee, $this->work());
        self::assertSame(['id', 'code', 'title', 'version'], array_keys($choices['items'][0]));
        $entry = $s->timesheetService()->submit($employee, $this->work());
        self::assertSame($job->title, $s->timesheetService()->entryDetails($entry->id)['position']['title']);
        self::assertSame(
            $job->title,
            $s->timesheetService()->week($employee, '2026-08-11')['items'][0]['position']['title']
        );
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $s->timesheetService()->workPositions($employee, $this->work());
    }
    public function testAnAssignmentFromAnotherEmployeeCannotBeSubmittedAndTheAuditFailureRollsBackItsLink(): void
    {
        $s = new SisFixture();
        [$employee, , $position] = $this->setupPosition($s);
        $other = $s->timesheetService()->addEmployee($s->student()->personId, 'Another employee');
        try {
            $s->timesheetService()->submit($other, $this->work(), $position, 1);
            self::fail('Work was attached to another employees position.');
        } catch (AccessDeniedException) {
            self::assertSame([], $s->sql->rows('sis_timesheet_positions'));
        }
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional audit failure'));
        try {
            $s->timesheetService($audit)->submit($employee, $this->work(), $position, 1);
            self::fail('Time or its position reference committed without the required audit.');
        } catch (RuntimeException) {
            self::assertSame([], $s->sql->rows('sis_timesheets'));
            self::assertSame([], $s->sql->rows('sis_timesheet_positions'));
        }
    }
    public function testSwappedPositionReferencesOrWorkIntervalsFailTheirAuthenticatedBinding(): void
    {
        foreach (['position_id', 'position_version', 'work'] as $field) {
            $s = new SisFixture();
            [$employee, $job, $position] = $this->setupPosition($s);
            $entry = $s->timesheetService()->submit($employee, $this->work(), $position, 1);
            if ($field === 'position_id') {
                $other = EntityId::generate();
                $s->positions()->assign(
                    $employee,
                    $other,
                    $job->id,
                    0,
                    null,
                    '2026-01-01',
                    null,
                    null,
                    null,
                    'Other assignment'
                );
                $s->sql->update(
                    'sis_timesheet_positions',
                    ['position_id' => $other->value],
                    ['entry_id' => $entry->id->value]
                );
            } elseif ($field === 'work') {
                $s->sql->update('sis_timesheets', ['ends_at' => '2026-08-11 18:00:00'], ['id' => $entry->id->value]);
            } else {
                $s->sql->update(
                    'sis_timesheet_positions',
                    ['position_version' => 2],
                    ['entry_id' => $entry->id->value]
                );
            }
            try {
                $s->timesheetService()->entries($employee);
                self::fail('A changed work-position binding was displayed.');
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }
    public function testChoicesAndBatchLabelsUseReadModelsInsteadOfReplayingEveryAssignment(): void
    {
        $s = new SisFixture();
        [$employee, , $position] = $this->setupPosition($s);
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::never())->method('page');
        $ledger = new PositionLedger(
            new SqlPositionRepository($s->sql, $s->dropCipher),
            $history,
            $s->audit,
            $s->clock
        );
        self::assertCount(1, $ledger->choices($employee, $this->work())['items']);
        $entries = array_fill(0, 50, ['position_id' => $position->value, 'position_version' => 1]);
        self::assertCount(50, $ledger->labelEntries($employee, $entries));
    }
}
