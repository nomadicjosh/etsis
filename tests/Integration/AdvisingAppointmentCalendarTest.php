<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Advising\AdvisingAppointment;
use Domain\Advising\Service\AdvisingAppointmentService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Tests\Support\AdvisingAppointmentsFixture;

final class AdvisingAppointmentCalendarTest extends TestCase
{
    private function fixture(): AdvisingAppointmentsFixture
    {
        require_once dirname(__DIR__) . '/Support/AdvisingAppointmentsFixture.php';
        return new AdvisingAppointmentsFixture();
    }

    private function rejects(callable $run, string $type): void
    {
        try {
            $run();
            self::fail('Expected a rejected private appointment operation.');
        } catch (\Throwable $error) {
            self::assertInstanceOf($type, $error);
        }
    }

    private function held(AdvisingAppointmentsFixture $f): EntityId
    {
        $id = new EntityId($f->schedule()['id']);
        $f->sis->clock->time = new DateTimeImmutable('2026-08-11T10:30:00-07:00');
        $f->act($id, 'held');
        return $id;
    }

    public function testCorrectionPreservesOriginalAttendanceTimesAcademicHistoryAndEveryAcceptedReceipt(): void
    {
        $f = $this->fixture();
        $id = $this->held($f);
        $original = $f->records->find($f->student, $f->pursuit, $id)->facts();
        $history = \EventStoreFixture::historyDigest($f->sis->sql);
        $op = EntityId::generate();
        $correct = fn() => $f->service()->act(
            $f->student,
            $f->pursuit,
            'staff',
            $id,
            $op,
            'correct_no_show',
            1,
            null,
            'Fictional attendance correction.'
        );
        $receipt = $correct();
        self::assertSame('no_show', $receipt['status']);
        $f->act($id, 'correct_held', 2);
        self::assertSame($receipt, $correct());
        $facts = $f->records->find($f->student, $f->pursuit, $id)->facts();
        self::assertSame($original['time'], $facts['time']);
        self::assertSame($original['actions'], array_slice($facts['actions'], 0, 2));
        self::assertSame($history, \EventStoreFixture::historyDigest($f->sis->sql));
        self::assertSame([], $f->sis->sql->rows('sis_advising_checkins'));
        self::assertSame([], $f->sis->sql->rows('sis_advising_updates'));
        $f->sis->access->actor = $f->owner;
        $view = $f->service()->listing($f->student, $f->pursuit, 'owner', selected: $id)['items'][0];
        self::assertCount(4, $view['changes']);
        self::assertFalse($view['can_correct']);
        self::assertSame('held', $view['changes'][1]['action']);
        self::assertSame('correct_no_show', $view['changes'][2]['action']);
        self::assertStringNotContainsString('actor_id', json_encode($view));
    }

    public function testIndependentCorrectionDutyNeverComesFromOwnershipOrAdvisorScope(): void
    {
        $f = $this->fixture();
        $id = $this->held($f);
        $s = $f->sis;
        $s->access->staff = false;
        $s->access->grants = [AdvisingAppointmentService::PERMISSION, 'sis:student:read'];
        self::assertFalse($f->service()->listing($f->student, $f->pursuit, 'staff', true)['items'][0]['can_correct']);
        $this->rejects(fn() => $f->act($id, 'correct_no_show', 1), AccessDeniedException::class);
        $s->access->grants[] = AdvisingAppointmentService::CORRECT;
        $s->access->actor = $f->owner;
        $this->rejects(fn() => $f->act($id, 'correct_no_show', 1, 'owner'), AccessDeniedException::class);
        $s->access->actor = $f->advisor;
        $this->rejects(fn() => $f->act($id, 'correct_no_show', 1, 'advisor'), AccessDeniedException::class);
        $s->access->actor = $f->staff;
        self::assertSame('no_show', $f->act($id, 'correct_no_show', 1)['status']);
    }

    public function testCorrectionsRejectStaleSameOutcomeAndDifferentActorOrIntentRetries(): void
    {
        $f = $this->fixture();
        $id = $this->held($f);
        $this->rejects(fn() => $f->act($id, 'correct_held', 1), ConcurrentModificationException::class);
        $this->rejects(fn() => $f->act($id, 'correct_no_show', 0), ConcurrentModificationException::class);
        $op = EntityId::generate();
        $f->service()->act($f->student, $f->pursuit, 'staff', $id, $op, 'correct_no_show', 1, null, 'Original reason.');
        $this->rejects(fn() => $f->service()->act(
            $f->student,
            $f->pursuit,
            'staff',
            $id,
            $op,
            'correct_no_show',
            1,
            null,
            'Changed reason.'
        ), ConcurrentModificationException::class);
        $f->sis->access->actor = $f->sis->user();
        $this->rejects(fn() => $f->service()->act(
            $f->student,
            $f->pursuit,
            'staff',
            $id,
            $op,
            'correct_no_show',
            1,
            null,
            'Original reason.'
        ), ConcurrentModificationException::class);
    }

    public function testScheduledAndCancelledAppointmentsCannotAcquireAnAttendanceCorrection(): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule()['id']);
        $this->rejects(fn() => $f->act($id, 'correct_no_show'), ConcurrentModificationException::class);
        $f->act($id, 'cancel');
        $this->rejects(fn() => $f->act($id, 'correct_held', 1), ConcurrentModificationException::class);
    }

    public function testFiveCorrectionsRemainBoundedWithoutRemovingTheirOriginalFacts(): void
    {
        $f = $this->fixture();
        $id = $this->held($f);
        for ($i = 1; $i <= 5; $i++) {
            $f->act($id, $i % 2 ? 'correct_no_show' : 'correct_held', $i);
        }
        $item = $f->records->find($f->student, $f->pursuit, $id);
        self::assertSame(5, $item->corrections());
        self::assertSame($item->facts(), AdvisingAppointment::restore($item->facts())->facts());
        self::assertFalse($f->service()->listing($f->student, $f->pursuit, 'staff', true)['items'][0]['can_correct']);
        $this->rejects(fn() => $f->act($id, 'correct_held', 6), ConcurrentModificationException::class);
    }

    #[DataProvider('drifts')]
    public function testCorrectionRollsBackOnFinalAuditAccountAuthorityOrSourceFailure(string $drift): void
    {
        $f = $this->fixture();
        $id = $this->held($f);
        $before = $f->sis->sql->rows('sis_advising_appointments');
        $audits = $f->sis->sql->rows('sis_audit');
        $s = $f->sis;
        $s->access->staff = false;
        $s->access->grants = [AdvisingAppointmentService::PERMISSION, AdvisingAppointmentService::CORRECT,
            'sis:student:read'];
        $audit = $f->audit(function () use ($drift, $f, $s, $id): void {
            match ($drift) {
                'authority' => $s->access->grants = [AdvisingAppointmentService::PERMISSION, 'sis:student:read'],
                'audit' => throw new \RuntimeException('Fictional audit failure.'),
                'host' => $s->sql->update('sis_persons', ['owner_user_id' => $s->user()->value],
                    ['id' => $f->host->value]),
                'owner' => $s->sql->update('sis_persons', ['owner_user_id' => $s->user()->value],
                    ['id' => $s->admissions->student($f->student)->personId->value]),
                'record' => $s->sql->update('sis_advising_appointments', ['version' => 9], ['id' => $id->value]),
            };
        });
        $this->rejects(fn() => $f->service($audit)->act(
            $f->student,
            $f->pursuit,
            'staff',
            $id,
            EntityId::generate(),
            'correct_no_show',
            1,
            null,
            'Fictional corrected attendance.'
        ), match ($drift) {
                'authority' => AccessDeniedException::class, 'audit' => \RuntimeException::class,
                'record' => CorruptHistoryException::class, default => ConcurrentModificationException::class,
        });
        self::assertSame($before, $s->sql->rows('sis_advising_appointments'));
        self::assertSame($audits, $s->sql->rows('sis_audit'));
    }

    public static function drifts(): array
    {
        return [['authority'], ['audit'], ['host'], ['owner'], ['record']];
    }

    public function testCalendarUsesHalfOpenUtcWindowsChronologicalOrderAndMinimizedSummaries(): void
    {
        $f = $this->fixture();
        $late = $f->schedule(time: $f->time('2026-08-12T10:00', '2026-08-12T11:00'));
        $early = $f->schedule();
        $f->schedule(time: $f->time('2026-08-17T00:00', '2026-08-17T00:30'));
        $calendar = $f->service()->calendar('staff', $f->student, $f->pursuit, '2026-08-11');
        self::assertSame('2026-08-10', $calendar['from']);
        self::assertSame('2026-08-16', $calendar['through']);
        self::assertSame([$early['id'], $late['id']], array_column($calendar['items'], 'id'));
        self::assertSame(2, $calendar['totals']['scheduled']);
        self::assertCount(1, $calendar['days'][1]['items']);
        self::assertStringNotContainsString('Fictional meeting instructions.', json_encode($calendar));
        self::assertStringNotContainsString('owner_user_id', json_encode($calendar));
        self::assertStringNotContainsString('actions', json_encode($calendar));
        self::assertSame('Computer Science', $calendar['items'][0]['program_title']);
    }

    public function testCancelledFilterAndExactFinishedRecordKeepTheOriginalReasonBehindAuthorization(): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule()['id']);
        $f->act($id, 'cancel');
        $f->sis->access->actor = $f->owner;
        self::assertSame([], $f->service()->calendar('owner', null, null, '2026-08-11')['items']);
        self::assertCount(1, $f->service()->calendar(
            'owner',
            null,
            null,
            '2026-08-11',
            includeCancelled: true
        )['items']);
        $view = $f->service()->listing($f->student, $f->pursuit, 'owner', selected: $id);
        self::assertSame('cancelled', $view['items'][0]['status']);
        self::assertFalse($view['can_schedule']);
        self::assertCount(2, $view['items'][0]['changes']);
    }

    public function testGlobalCalendarIsOwnerOnlyEvenForPrivilegedStaffAndNeverTransfersOnAccountRelinking(): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule()['id']);
        self::assertSame([], $f->service()->calendar('owner', null, null, '2026-08-11')['items']);
        $this->rejects(
            fn() => $f->service()->calendar('staff', null, null, '2026-08-11'),
            AccessDeniedException::class
        );
        $f->sis->access->actor = $f->owner;
        self::assertCount(1, $f->service()->calendar('owner', null, null, '2026-08-11')['items']);
        $new = $f->sis->user();
        $f->sis->sql->update(
            'sis_persons',
            ['owner_user_id' => $new->value],
            ['id' => $f->sis->admissions->student($f->student)->personId->value]
        );
        self::assertSame([], $f->service()->calendar('owner', null, null, '2026-08-11')['items']);
        $f->sis->access->actor = $new;
        self::assertSame([], $f->service()->calendar('owner', null, null, '2026-08-11')['items']);
        $this->rejects(
            fn() => $f->service()->listing($f->student, $f->pursuit, 'owner', selected: $id),
            AccessDeniedException::class
        );
    }

    public function testHistoricalStudentCalendarRemainsAvailableWithoutGrantingAnEndedAdvisorAccess(): void
    {
        $f = $this->fixture();
        $f->schedule();
        $f->assign(end: '2026-08-10');
        $f->sis->clock->time = new DateTimeImmutable('2026-08-12T10:00:00-07:00');
        $f->sis->sql->update('sis_students', ['active' => 0], ['id' => $f->student->value]);
        $f->sis->access->actor = $f->owner;
        self::assertCount(1, $f->service()->calendar('owner', null, null, '2026-08-11')['items']);
        $f->sis->access->actor = $f->advisor;
        $this->rejects(
            fn() => $f->service()->calendar('advisor', $f->student, $f->pursuit, '2026-08-11'),
            AccessDeniedException::class
        );
    }

    public function testEmptyCalendarStillRequiresAnActualStudentPursuit(): void
    {
        $f = $this->fixture();
        $other = $f->sis->student($f->sis->user());
        $this->rejects(
            fn() => $f->service()->calendar('staff', $other->id, $f->pursuit, '2026-08-11'),
            RecordNotFoundException::class
        );
    }

    #[DataProvider('calendarDrifts')]
    public function testCalendarReadRejectsChangedMembershipRecordsLabelsAndAuthorityAfterItsAudit(string $drift): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule()['id']);
        $f->sis->access->actor = $f->owner;
        $before = $f->sis->sql->rows('sis_advising_appointments');
        $audit = $f->audit(function () use ($drift, $f, $id): void {
            $s = $f->sis;
            match ($drift) {
                'owner' => $s->sql->update('sis_persons', ['owner_user_id' => $s->user()->value],
                    ['id' => $s->admissions->student($f->student)->personId->value]),
                'host' => $s->sql->update('sis_persons', ['given_name' => 'Changed'], ['id' => $f->host->value]),
                'program' => $s->sql->update('sis_programs', ['title' => 'Changed'], ['id' => $s->program->value]),
                'record' => $s->sql->update('sis_advising_appointments', ['version' => 9], ['id' => $id->value]),
            };
        });
        $this->rejects(
            fn() => $f->service($audit)->calendar('owner', null, null, '2026-08-11'),
            $drift === 'record' ? CorruptHistoryException::class : ConcurrentModificationException::class
        );
        self::assertSame($before, $f->sis->sql->rows('sis_advising_appointments'));
    }

    public static function calendarDrifts(): array
    {
        return [['owner'], ['host'], ['program'], ['record']];
    }

    public function testCalendarLimitRejectsAllOutputInsteadOfReturningATruncatedMonth(): void
    {
        $f = $this->fixture();
        $owner = $f->owner;
        for ($i = 0; $i < 101; $i++) {
            $day = sprintf('2026-08-%02d', 11 + ($i % 20));
            $time = $f->time($day . 'T10:00', $day . 'T10:30');
            $item = AdvisingAppointment::create(
                EntityId::generate(),
                $f->student,
                $f->pursuit,
                $owner,
                $f->host,
                $f->advisor,
                $f->staff,
                $time,
                'Fictional archived appointment.',
                '2026-08-10T17:00:00Z'
            );
            $item->apply(
                EntityId::generate(),
                $f->staff,
                'held',
                0,
                null,
                'Fictional attendance.',
                '2026-09-01T17:00:00Z'
            );
            $f->records->save($item, null);
        }
        $f->sis->access->actor = $owner;
        $this->rejects(
            fn() => $f->service()->calendar('owner', null, null, '2026-08-11', 'month'),
            ValidationException::class
        );
        $week = $f->service()->calendar('owner', null, null, '2026-08-11');
        self::assertLessThanOrEqual(100, count($week['items']));
    }

    public function testOwnerCalendarIncludesHistoricalPursuitsWithoutLeakingAnotherStudentsAppointments(): void
    {
        $f = $this->fixture();
        $first = $f->schedule();
        $program = EntityId::generate();
        $f->sis->sql->insert('sis_programs', ['id' => $program->value, 'code' => 'ARTS-UG',
            'title' => 'Fictional Arts']);
        $pursuit = new \Domain\Student\StudentProgram(
            EntityId::generate(),
            $f->student,
            $program,
            $f->sis->term,
            'closed'
        );
        new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($f->sis->sql)->addAssignment($pursuit);
        $second = AdvisingAppointment::create(
            EntityId::generate(),
            $f->student,
            $pursuit->id,
            $f->owner,
            $f->host,
            $f->advisor,
            $f->staff,
            $f->time('2026-08-12T10:00', '2026-08-12T10:30'),
            'Fictional earlier-pursuit appointment.',
            '2026-08-10T17:00:00Z'
        );
        $f->records->save($second, null);
        $other = $f->sis->student($f->sis->user());
        $otherPursuit = new EntityId($f->sis->sql->rows(
            'sis_student_programs',
            ['student_id' => $other->id->value],
            1
        )[0]['id']);
        $foreign = AdvisingAppointment::create(
            EntityId::generate(),
            $other->id,
            $otherPursuit,
            $f->sis->admissions->person($other->personId)->ownerUserId,
            $f->host,
            $f->advisor,
            $f->staff,
            $f->time('2026-08-13T10:00', '2026-08-13T10:30'),
            'Fictional other student.',
            '2026-08-10T17:00:00Z'
        );
        $f->records->save($foreign, null);
        $f->sis->access->actor = $f->owner;
        $calendar = $f->service()->calendar('owner', null, null, '2026-08-11');
        self::assertSame([$first['id'], $second->facts()['id']], array_column($calendar['items'], 'id'));
        self::assertSame(['BSCS', 'ARTS-UG'], array_column($calendar['items'], 'program_code'));
        self::assertStringNotContainsString($foreign->facts()['id'], json_encode($calendar));
    }

    public function testCalendarUsesTodaysInstitutionTimezoneButKeepsOriginalAppointmentTimeUntouched(): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule(time: $f->time('2026-08-11T23:30', '2026-08-11T23:59'))['id']);
        $original = $f->records->find($f->student, $f->pursuit, $id)->facts();
        $f->sis->clock->time = new DateTimeImmutable('2026-08-12T10:00:00Z');
        $calendar = $f->service()->calendar('staff', $f->student, $f->pursuit, '2026-08-11');
        self::assertSame('Z', $calendar['timezone']);
        self::assertSame([], $calendar['days'][1]['items']);
        self::assertCount(1, $calendar['days'][2]['items']);
        self::assertSame($original, $f->records->find($f->student, $f->pursuit, $id)->facts());
    }

    public function testAttendanceCorrectionRemainsAnOfficeActionAfterTheStudentBecomesInactive(): void
    {
        $f = $this->fixture();
        $id = $this->held($f);
        $f->sis->sql->update('sis_students', ['active' => 0], ['id' => $f->student->value]);
        self::assertSame('no_show', $f->act($id, 'correct_no_show', 1)['status']);
        $f->sis->access->actor = $f->owner;
        self::assertSame('no_show', $f->service()->listing(
            $f->student,
            $f->pursuit,
            'owner',
            selected: $id
        )['items'][0]['status']);
    }

    public function testCalendarMigrationRetriesPreserveOriginalFactsAndEveryExistingGrant(): void
    {
        $f = $this->fixture();
        $f->schedule();
        $before = [];
        foreach (
            ['sis_advising_appointments', 'sis_access_permissions', 'sis_access_implications',
            'sis_access_role_permissions', 'sis_access_user_permissions', 'sis_history_events'] as $table
        ) {
            $before[$table] = $f->sis->sql->rows($table);
        }
        $migration = new \AddSisAppointmentCalendar('1');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter(
            $f->sis->connection,
            'test_migrations'
        )]));
        $migration->up();
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $f->sis->sql->rows($table));
        }
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
