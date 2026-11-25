<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Advising\Service\AdvisingAppointmentService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AdvisingAppointmentsFixture;

final class AdvisingAppointmentsTest extends TestCase
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
            self::fail('Expected a rejected appointment action.');
        } catch (\Throwable $error) {
            self::assertInstanceOf($type, $error);
        }
    }

    public function testScheduleRescheduleOwnerReadCancellationAndOriginalRetriesPreserveAcademicFacts(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $history = $s->sql->rows('sis_history_events');
        $id = EntityId::generate();
        $created = $f->schedule($id);
        $operation = EntityId::generate();
        $time = $f->time('2026-08-12T11:00', '2026-08-12T12:00');
        $change = fn() => $f->service()->act(
            $f->student,
            $f->pursuit,
            'staff',
            $id,
            $operation,
            'reschedule',
            0,
            $time,
            'Fictional new meeting.'
        );
        $changed = $change();
        self::assertSame(1, $changed['version']);
        self::assertSame($created, $f->schedule($id));
        self::assertSame($changed, $change());
        $s->access->actor = $f->owner;
        $view = $f->service()->listing($f->student, $f->pursuit, 'owner');
        self::assertCount(1, $view['items']);
        self::assertFalse($view['can_schedule']);
        self::assertSame([], $view['hosts']);
        self::assertSame($time->facts(), $view['items'][0]['time']);
        self::assertCount(2, $view['items'][0]['changes']);
        self::assertStringNotContainsString('actor_id', json_encode($view));
        self::assertStringNotContainsString($f->advisor->value, json_encode($view));
        $cancelOperation = EntityId::generate();
        $cancel = fn() => $f->service()->act(
            $f->student,
            $f->pursuit,
            'owner',
            $id,
            $cancelOperation,
            'cancel',
            1,
            null,
            'Fictional student cancellation.'
        );
        self::assertSame('cancelled', $cancel()['status']);
        $s->clock->time = new DateTimeImmutable('2026-09-01T10:00:00-07:00');
        self::assertSame(2, $cancel()['version']);
        self::assertSame([], $f->service()->listing($f->student, $f->pursuit, 'owner')['items']);
        self::assertCount(1, $f->service()->listing($f->student, $f->pursuit, 'owner', true)['items']);
        $s->access->actor = $f->staff;
        self::assertSame($created, $f->schedule($id));
        self::assertSame($changed, $change());
        self::assertSame($history, $s->sql->rows('sis_history_events'));
        self::assertSame([], $s->sql->rows('sis_advising_checkins'));
        self::assertSame([], $s->sql->rows('sis_advising_updates'));
        self::assertStringNotContainsString(
            'Fictional new meeting.',
            json_encode($s->sql->rows('sis_advising_appointments'))
        );
        self::assertStringNotContainsString('Fictional new meeting.', json_encode($s->sql->rows('sis_audit')));
    }

    public function testDutyIsIndependentFromAssignmentManagementAndOwnerPrivileges(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $s->access->staff = false;
        $s->access->grants = ['sis:student:read', 'sis:advising:manage', 'sis:advising:checkin'];
        $this->rejects(fn() => $f->schedule(), AccessDeniedException::class);
        $s->access->grants = [AdvisingAppointmentService::PERMISSION];
        $this->rejects(fn() => $f->schedule(), AccessDeniedException::class);
        $s->access->staff = true;
        $this->rejects(fn() => $f->service()->listing($f->student, $f->pursuit, 'owner'), AccessDeniedException::class);
        $s->access->actor = $f->owner;
        $this->rejects(fn() => $f->schedule(channel: 'owner'), AccessDeniedException::class);
    }

    public function testAssignedAdvisorCanScheduleOwnTimeWithoutBroadStudentAuthorityAndLosesScope(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $s->access->staff = false;
        $s->access->grants = [AdvisingAppointmentService::PERMISSION];
        $s->access->actor = $f->advisor;
        $id = new EntityId($f->schedule(channel: 'advisor')['id']);
        self::assertTrue($f->service()->listing($f->student, $f->pursuit, 'advisor')['can_schedule']);
        $other = $s->legacyPerson($s->user(), 'Other', 'Advisor');
        $this->rejects(fn() => $f->service()->schedule(
            $f->student,
            $f->pursuit,
            'advisor',
            EntityId::generate(),
            $other->id,
            '2026-08-12T10:00',
            '2026-08-12T10:30',
            '-07:00',
            'Wrong host.'
        ), ConcurrentModificationException::class);
        $s->sql->update('sis_employees', ['active' => 0], ['person_id' => $f->host->value]);
        $this->rejects(fn() => $f->act($id, 'cancel', channel: 'advisor'), AccessDeniedException::class);
        $this->rejects(
            fn() => $f->service()->listing($f->student, $f->pursuit, 'advisor'),
            AccessDeniedException::class
        );
    }

    public function testConflictsCoverSameStudentOtherPursuitAndSameHostOtherStudentButAllowAdjacentTimes(): void
    {
        $f = $this->fixture();
        $first = new EntityId($f->schedule()['id']);
        $this->rejects(fn() => $f->schedule(), ConcurrentModificationException::class);
        $other = $f->sis->student($f->sis->user());
        $pursuit = new EntityId($f->sis->sql->rows(
            'sis_student_programs',
            ['student_id' => $other->id->value],
            1
        )[0]['id']);
        self::assertTrue($f->records->conflict($other->id, $f->host, EntityId::generate(), $f->time()));
        $f->sis->sql->update('sis_advising_appointments', ['pursuit_id' => $pursuit->value], ['id' => $first->value]);
        $this->rejects(fn() => $f->schedule(), CorruptHistoryException::class);
        $f->sis->sql->update(
            'sis_advising_appointments',
            ['pursuit_id' => $f->pursuit->value],
            ['id' => $first->value]
        );
        self::assertSame(0, $f->schedule(time: $f->time('2026-08-11T10:30', '2026-08-11T11:00'))['version']);
        $f->act($first, 'cancel');
        self::assertSame(0, $f->schedule()['version']);
    }

    public function testCanonicalPersonConflictAcrossStudentAndAdvisorCapabilities(): void
    {
        $f = $this->fixture();
        $application = $f->sis->legacyApplication($f->host, $f->sis->program, $f->sis->term);
        $f->sis->legacyAdmissionDecision($application->id, true);
        $dual = $f->sis->legacyStudent($application->id);
        self::assertSame($f->host->value, $dual->personId->value);
        $f->schedule();
        $otherHost = $f->sis->legacyPerson($f->sis->user(), 'Other', 'Advisor');
        self::assertTrue($f->records->conflict($dual->id, $otherHost->id, EntityId::generate(), $f->time()));
    }

    public function testDatedAssignmentMustCoverTheMeetingAndTimezoneMustMatchInstitution(): void
    {
        $f = $this->fixture();
        $f->assign(end: '2026-08-10');
        $this->rejects(fn() => $f->schedule(), ConcurrentModificationException::class);
        $f->assign(start: '2026-08-11', end: '2026-08-11');
        self::assertSame('scheduled', $f->schedule()['status']);
        $this->rejects(fn() => $f->service()->schedule(
            $f->student,
            $f->pursuit,
            'staff',
            EntityId::generate(),
            $f->host,
            '2026-08-12T10:00',
            '2026-08-12T10:30',
            'UTC',
            'Wrong timezone.'
        ), AccessDeniedException::class);
    }

    public function testStaleChangedAndWrongActorRetriesNeverReplaceAnAcceptedTime(): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule()['id']);
        $op = EntityId::generate();
        $time = $f->time('2026-08-12T10:00', '2026-08-12T10:30');
        $f->service()->act($f->student, $f->pursuit, 'staff', $id, $op, 'reschedule', 0, $time, 'First change.');
        $before = $f->records->find($f->student, $f->pursuit, $id)->facts();
        $this->rejects(fn() => $f->act($id, 'cancel'), ConcurrentModificationException::class);
        $this->rejects(fn() => $f->service()->act(
            $f->student,
            $f->pursuit,
            'staff',
            $id,
            $op,
            'reschedule',
            0,
            $time,
            'Different change.'
        ), ConcurrentModificationException::class);
        $f->sis->access->actor = $f->sis->user();
        $this->rejects(fn() => $f->service()->act(
            $f->student,
            $f->pursuit,
            'staff',
            $id,
            $op,
            'reschedule',
            0,
            $time,
            'First change.'
        ), ConcurrentModificationException::class);
        self::assertSame($before, $f->records->find($f->student, $f->pursuit, $id)->facts());
    }

    public function testStudentRelinkingNeverTransfersEarlierAppointmentsAndStaffCanOnlyCancel(): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule()['id']);
        $new = $f->sis->user();
        $f->sis->sql->update('sis_persons', ['owner_user_id' => $new->value], [
            'id' => $f->sis->admissions->student($f->student)->personId->value,
        ]);
        $f->sis->access->actor = $f->owner;
        $this->rejects(fn() => $f->service()->listing($f->student, $f->pursuit, 'owner'), AccessDeniedException::class);
        $f->sis->access->actor = $new;
        self::assertSame([], $f->service()->listing($f->student, $f->pursuit, 'owner')['items']);
        $this->rejects(fn() => $f->act($id, 'cancel', channel: 'owner'), AccessDeniedException::class);
        $f->sis->access->actor = $f->staff;
        self::assertTrue($f->service()->listing($f->student, $f->pursuit, 'staff')['items'][0]['cancel_only']);
        $this->rejects(
            fn() => $f->act($id, 'reschedule', time: $f->time('2026-08-12T10:00', '2026-08-12T10:30')),
            AccessDeniedException::class
        );
        self::assertSame('cancelled', $f->act($id, 'cancel')['status']);
    }

    #[DataProvider('outcomes')]
    public function testAttendanceIsOnlyRecordedAfterTheScheduledEndAndNeverCreatesACheckIn(string $outcome): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule()['id']);
        $this->rejects(fn() => $f->act($id, $outcome), ConcurrentModificationException::class);
        $f->sis->clock->time = new DateTimeImmutable('2026-08-11T10:30:00-07:00');
        $f->sis->access->actor = $f->owner;
        $this->rejects(fn() => $f->act($id, $outcome, channel: 'owner'), AccessDeniedException::class);
        $this->rejects(fn() => $f->act($id, 'cancel', channel: 'owner'), AccessDeniedException::class);
        $f->sis->access->actor = $f->staff;
        self::assertSame($outcome, $f->act($id, $outcome)['status']);
        $this->rejects(fn() => $f->act($id, 'reschedule', 1, time: $f->time(
            '2026-08-12T10:00',
            '2026-08-12T10:30'
        )), ConcurrentModificationException::class);
        self::assertSame([], $f->sis->sql->rows('sis_advising_checkins'));
    }

    public static function outcomes(): array
    {
        return [['held'], ['no_show']];
    }

    public function testTwentyActiveCapPagingAndFinishedNavigation(): void
    {
        $f = $this->fixture();
        for ($i = 1; $i <= 20; $i++) {
            $day = sprintf('2026-09-%02d', $i);
            $f->schedule(time: $f->time($day . 'T10:00', $day . 'T10:30'));
        }
        $page = $f->service()->listing($f->student, $f->pursuit, 'staff');
        self::assertCount(10, $page['items']);
        self::assertNotNull($page['next_after']);
        $next = $f->service()->listing($f->student, $f->pursuit, 'staff', false, new EntityId($page['next_after']));
        self::assertCount(10, $next['items']);
        self::assertNull($next['next_after']);
        self::assertSame([], array_intersect(array_column($page['items'], 'id'), array_column($next['items'], 'id')));
        $this->rejects(fn() => $f->schedule(), ConcurrentModificationException::class);
        $f->act(new EntityId($page['items'][0]['id']), 'cancel');
        self::assertSame('scheduled', $f->schedule()['status']);
        self::assertCount(1, $f->service()->listing($f->student, $f->pursuit, 'staff', true)['items']);
    }

    #[DataProvider('auditFailures')]
    public function testFinalAuditFailureOrSourceDriftRollsBackAppointmentAndCoupledWrites(string $failure): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule()['id']);
        $before = $f->sis->sql->rows('sis_advising_appointments');
        $auditBefore = $f->sis->sql->rows('sis_audit');
        $audit = new class ($f, $failure) implements AuditTrail {
            public function __construct(private AdvisingAppointmentsFixture $f, private string $failure)
            {
            }
            public function forSubject(EntityId $subject): array
            {
                return $this->f->sis->audit->forSubject($subject);
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $s = $this->f->sis;
                $s->audit->record($actor, $action, $subject);
                match ($this->failure) {
                    'audit' => throw new RuntimeException('Fictional required-audit failure.'),
                    'host' => $s->sql->update('sis_persons', ['owner_user_id' => $s->user()->value],
                        ['id' => $this->f->host->value]),
                    'student' => $s->sql->update('sis_students', ['active' => 0], ['id' => $this->f->student->value]),
                    'projection' => $s->sql->update('sis_advising_appointments', ['version' => 9],
                        ['id' => $subject->value]),
                    'authority' => $s->access->grants = [],
                };
            }
        };
        $f->sis->access->staff = false;
        $f->sis->access->grants = [AdvisingAppointmentService::PERMISSION, 'sis:student:read'];
        $this->rejects(
            fn() => $f->service($audit)->act(
                $f->student,
                $f->pursuit,
                'staff',
                $id,
                EntityId::generate(),
                'cancel',
                0,
                null,
                'Fictional cancellation.'
            ),
            match ($failure) {
                'audit' => RuntimeException::class, 'projection' => CorruptHistoryException::class,
                'authority' => AccessDeniedException::class, default => ConcurrentModificationException::class,
            }
        );
        self::assertSame($before, $f->sis->sql->rows('sis_advising_appointments'));
        self::assertSame($auditBefore, $f->sis->sql->rows('sis_audit'));
        self::assertTrue($f->sis->admissions->student($f->student)->active);
        self::assertSame($f->advisor->value, $f->sis->admissions->person($f->host)->ownerUserId->value);
    }

    public static function auditFailures(): array
    {
        return [['audit'], ['host'], ['student'], ['projection'], ['authority']];
    }

    public function testCiphertextAndIndexDamageFailClosed(): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule()['id']);
        $row = $f->sis->sql->rows('sis_advising_appointments', ['id' => $id->value], 1)[0];
        foreach (
            ['sealed_data' => 'damaged', 'starts_at' => '2026-08-12T17:00:00Z',
            'version' => 9] as $key => $value
        ) {
            $f->sis->sql->update('sis_advising_appointments', [$key => $value], ['id' => $id->value]);
            $this->rejects(fn() => $f->records->find($f->student, $f->pursuit, $id), CorruptHistoryException::class);
            $f->sis->sql->update('sis_advising_appointments', $row, ['id' => $id->value]);
        }
    }

    public function testInactivePursuitKeepsOriginalReceiptsAndOwnerHistoryButRejectsNewTimes(): void
    {
        $f = $this->fixture();
        $id = EntityId::generate();
        $original = $f->schedule($id);
        $f->sis->sql->update('sis_students', ['active' => 0], ['id' => $f->student->value]);
        self::assertSame($original, $f->schedule($id));
        self::assertFalse($f->service()->listing($f->student, $f->pursuit, 'staff')['can_schedule']);
        $this->rejects(fn() => $f->schedule(), AccessDeniedException::class);
        $this->rejects(
            fn() => $f->act($id, 'reschedule', time: $f->time('2026-08-12T10:00', '2026-08-12T10:30')),
            AccessDeniedException::class
        );
        $f->sis->access->actor = $f->owner;
        self::assertCount(1, $f->service()->listing($f->student, $f->pursuit, 'owner')['items']);
        self::assertSame('cancelled', $f->act($id, 'cancel', channel: 'owner')['status']);
    }

    public function testHostAccountRelinkCannotTransferAnAppointmentButOwnerMayStillCancel(): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule()['id']);
        $f->sis->sql->update('sis_persons', ['owner_user_id' => $f->sis->user()->value], ['id' => $f->host->value]);
        $this->rejects(
            fn() => $f->act($id, 'reschedule', time: $f->time('2026-08-12T10:00', '2026-08-12T10:30')),
            AccessDeniedException::class
        );
        $f->sis->access->actor = $f->owner;
        self::assertSame('cancelled', $f->act($id, 'cancel', channel: 'owner')['status']);
    }

    public function testMigrationRetryKeepsExistingGrantsAndRecordsUnchanged(): void
    {
        $f = $this->fixture();
        $f->schedule();
        $before = [];
        foreach (
            ['sis_advising_appointments', 'sis_access_role_permissions', 'sis_access_user_permissions',
            'sis_history_events', 'sis_access_permissions', 'sis_access_implications'] as $table
        ) {
            $before[$table] = $f->sis->sql->rows($table);
        }
        $migration = new \CreateSisAdvisingAppointments('1');
        $adapter = new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
            $f->sis->connection,
            'test_migrations'
        );
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' => $adapter]));
        $migration->up();
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $f->sis->sql->rows($table));
        }
        $this->expectException(\LogicException::class);
        $migration->down();
    }

    public function testFinalAssignmentChangeRollsBackItsCoupledAcademicJournalAndTheNewAppointment(): void
    {
        $f = $this->fixture();
        $before = $f->sis->sql->rows('sis_history_events');
        $advisors = $f->sis->sql->rows('sis_student_advisors');
        $audit = $f->audit(fn() => $f->assign(end: '2026-08-10'));
        $time = $f->time();
        $this->rejects(fn() => $f->service($audit)->schedule(
            $f->student,
            $f->pursuit,
            'staff',
            EntityId::generate(),
            $f->host,
            $time->start,
            $time->end,
            $time->timezone,
            'Fictional new appointment.'
        ), ConcurrentModificationException::class);
        self::assertSame([], $f->sis->sql->rows('sis_advising_appointments'));
        self::assertSame($before, $f->sis->sql->rows('sis_history_events'));
        self::assertSame($advisors, $f->sis->sql->rows('sis_student_advisors'));
    }

    public function testFinalOverlapRollsBackBothChangedWorkingAppointments(): void
    {
        $f = $this->fixture();
        $first = new EntityId($f->schedule()['id']);
        $second = new EntityId($f->schedule(time: $f->time('2026-08-11T11:00', '2026-08-11T11:30'))['id']);
        $before = $f->sis->sql->rows('sis_advising_appointments');
        $time = $f->time('2026-08-11T12:00', '2026-08-11T12:30');
        $audit = $f->audit(function () use ($f, $second, $time): void {
            $item = $f->records->find($f->student, $f->pursuit, $second);
            $item->apply(
                EntityId::generate(),
                $f->staff,
                'reschedule',
                0,
                $time,
                'Fictional competing change.',
                '2026-08-10T17:00:00Z'
            );
            $f->records->save($item, 0);
        });
        $this->rejects(fn() => $f->service($audit)->act(
            $f->student,
            $f->pursuit,
            'staff',
            $first,
            EntityId::generate(),
            'reschedule',
            0,
            $time,
            'Fictional reschedule.'
        ), ConcurrentModificationException::class);
        self::assertSame($before, $f->sis->sql->rows('sis_advising_appointments'));
    }

    public function testReadRechecksHostLabelsAndCanonicalLinksAfterItsRequiredAudit(): void
    {
        $f = $this->fixture();
        $f->schedule();
        $audit = $f->audit(fn() => $f->sis->sql->update(
            'sis_persons',
            ['owner_user_id' => $f->sis->user()->value],
            ['id' => $f->host->value]
        ));
        $this->rejects(
            fn() => $f->service($audit)->listing($f->student, $f->pursuit, 'staff'),
            ConcurrentModificationException::class
        );
        self::assertSame($f->advisor->value, $f->sis->admissions->person($f->host)->ownerUserId->value);
    }
}
