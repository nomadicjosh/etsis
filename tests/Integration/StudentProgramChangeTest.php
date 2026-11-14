<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__) . '/Support/StudentRequirementsFixture.php';

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\StudentProgramWasReplaced;
use PHPUnit\Framework\TestCase;
use SisFixture;
use StudentRequirementsFixture;

final class StudentProgramChangeTest extends TestCase
{
    private function review(SisFixture $s, EntityId $student, ?EntityId $program = null): array
    {
        if ($program === null) {
            $program = EntityId::generate();
            $s->sql->insert('sis_programs', ['id' => $program->value,
                'code' => 'NEXT.' . substr($program->value, -6), 'title' => 'Fictional new program']);
        }
        $r = $s->admissions->student($student);
        $revision = hash('sha256', json_encode([$r->id->value, $r->personId->value,
            $r->programId->value, $r->entryTermId->value, $r->active], JSON_THROW_ON_ERROR));
        return $s->programAssignmentService()->reviewChange($student, $program, $s->term,
            $revision, 'Fictional registrar-approved program change.');
    }

    public function testProgramChangeClosesAndReplacesThePursuitAtomicallyWithoutWithdrawingOrMovingAcademicFacts(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        $section = $s->section();
        $enrollment = $s->service->register($f->student, $section->id);
        $credit = new EntityId($s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id']);
        $student = $s->admissions->student($f->student);
        $credits = $s->sql->rows('sis_academic_credits');
        $enrollments = $s->sql->rows('sis_enrollments');
        $finance = $s->financialEvents();
        $events = $s->sql->rows('sis_history_events');
        $review = $this->review($s, $f->student);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertSame($f->assignment->value, $review['before']['assignment_id']);
        $result = $s->programAssignmentService()->changeProgram($f->student, $review['review_token']);
        $next = new EntityId($result['assignment_id']);
        self::assertSame('closed', $s->sql->one('sis_student_programs', ['id' => $f->assignment->value])['status']);
        self::assertSame('active', $s->sql->one('sis_student_programs', ['id' => $next->value])['status']);
        $after = $s->admissions->student($f->student);
        self::assertSame($student->active, $after->active);
        self::assertSame($student->entryTermId->value, $after->entryTermId->value);
        self::assertSame($next->value, $s->primaryProgramLedger()->current($after)['assignment_id']);
        self::assertSame($credits, $s->sql->rows('sis_academic_credits'));
        self::assertSame($enrollments, $s->sql->rows('sis_enrollments'));
        self::assertSame($finance, $s->financialEvents());
        self::assertSame($f->assignment->value, $s->workPursuitLedger()->load($credit)->projection()['owner_assignment_id']);
        self::assertSame([], $s->academicInputs($f->service)->collect($f->student, $next)->items);
        $timeline = $s->programStatusService()->history($f->student, $f->assignment);
        self::assertSame('program_change', $timeline['items'][array_key_last($timeline['items'])]['kind']);
        self::assertSame($next->value, $timeline['items'][array_key_last($timeline['items'])]['replacement_assignment_id']);
        self::assertCount(1, $s->sql->rows('sis_history_events', ['event_type' => 'student-program-was-replaced']));
    }

    public function testOriginalConfirmationAfterALaterProgramChangeReturnsItsReceiptWithoutUndoingTheLaterChange(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $first = $this->review($s, $student);
        $service = $s->programAssignmentService();
        $receipt = $service->changeProgram($student, $first['review_token']);
        $second = $this->review($s, $student);
        $next = $service->changeProgram($student, $second['review_token']);
        $s->clock->time = $s->clock->time->modify('+1 day');
        $events = $s->sql->rows('sis_history_events');
        self::assertSame($receipt, $service->changeProgram($student, $first['review_token']));
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertSame($next['assignment_id'], $s->primaryProgramLedger()->current($s->admissions->student($student))['assignment_id']);
        self::assertSame('closed', $s->sql->one('sis_student_programs', ['id' => $receipt['assignment_id']])['status']);
    }

    public function testLateAuditFailureRollsBackNewPursuitCohortPrimaryClosureEventsAndReceipts(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $review = $this->review($s, $student);
        $tables = ['sis_student_programs', 'sis_student_catalogs', 'sis_students', 'sis_history_events', 'sis_history_operations'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $s->sql->rows($table);
        }
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::atLeastOnce())->method('record')->willReturnCallback(
            static function (EntityId $actor, string $action, EntityId $subject): void {
                if ($action === 'student.program_changed') {
                    throw new \RuntimeException('Fictional final audit failure.');
                }
            });
        try {
            $s->programAssignmentService($audit)->changeProgram($student, $review['review_token']);
            self::fail('A partial program change committed.');
        } catch (\RuntimeException) {
            foreach ($tables as $table) {
                self::assertSame($before[$table], $s->sql->rows($table));
            }
        }
    }

    public function testExpiredProgramChangeCannotCreateAnAssignmentOrCloseTheCurrentProgram(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $review = $this->review($s, $student);
        $s->clock->time = $s->clock->time->modify('+21 minutes');
        $before = $s->sql->rows('sis_student_programs');
        try {
            $s->programAssignmentService()->changeProgram($student, $review['review_token']);
            self::fail('An expired program change committed.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_student_programs'));
        }
    }

    public function testCurrentPursuitChangeAfterReviewRequiresAnotherReview(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $first = $this->review($s, $student);
        $second = $this->review($s, $student);
        $s->programAssignmentService()->changeProgram($student, $second['review_token']);
        $events = $s->sql->rows('sis_history_events');
        try {
            $s->programAssignmentService()->changeProgram($student, $first['review_token']);
            self::fail('A stale program change was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $s->sql->rows('sis_history_events'));
        }
    }

    public function testStudentsAndAcademicReadersCannotProcessAProgramChange(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $review = $this->review($s, $student);
        $s->access->staff = false;
        $s->access->grants = ['sis:student:read'];
        $this->expectException(AccessDeniedException::class);
        $s->programAssignmentService()->changeProgram($student, $review['review_token']);
    }
    public function testProgramChangePrimaryVerificationCannotFollowAnUnboundedCursorChain(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $history = $this->createMock(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $history->expects(self::exactly(400))->method('page')
            ->willReturn(new \Domain\Shared\EventSourcing\EventPage([], 'fictional-next-page', 0));
        $ledger = new \Domain\Student\Service\StudentPrimaryProgramLedger(
            new \Infrastructure\Persistence\Repository\SqlStudentPrimaryProgramRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($s->sql),
            $s->programStatusLedger(), $history, $s->access, $s->clock,
        );
        $this->expectException(\Domain\Shared\EventSourcing\HistoryReadLimitException::class);
        $ledger->verify($student);
    }
}
