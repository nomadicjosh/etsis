<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/HiatusFixture.php';

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use Domain\Student\StudentHiatus;
use Domain\Student\ValueObject\HiatusReturnPlan;
use PHPUnit\Framework\TestCase;

final class HiatusReturnTest extends TestCase
{
    private function hiatus(HiatusFixture $h, ?string $end = null): StudentHiatus
    {
        $student = $h->sis->student();
        $h->sis->sql->update('sis_students', ['active' => 0], ['id' => $student->id->value]);
        $hiatus = new StudentHiatus(
            EntityId::generate(),
            $student->id,
            HiatusCode::Leave,
            new DateRange(new DateTimeImmutable('2026-08-01'), $end === null ? null : new DateTimeImmutable($end))
        );
        $h->history->create($hiatus, 'Fictional leave reason');
        return $hiatus;
    }

    private function plan(array $review, bool $reactivate = true): HiatusReturnPlan
    {
        return new HiatusReturnPlan(
            new EntityId($review['operation_id']),
            new DateTimeImmutable($review['return_on']),
            $review['revision'],
            $reactivate
        );
    }

    public function testReturnClosesLeaveBeforeReturnAndReactivatesOnlyWhenConfirmedWithEncryptedHistory(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $hiatus = $this->hiatus($h);
        $service = $h->returns();
        $academic = $f->sql->rows('sis_academic_credits');
        $events = $f->financialEvents();
        $review = $service->review($hiatus->studentId, $hiatus->id, new DateTimeImmutable('2026-08-10'));
        self::assertSame('2026-08-09', $review['hiatus_ends_on']);
        self::assertSame('BSCS', $review['primary_program']['code']);
        self::assertFalse($f->admissions->student($hiatus->studentId)->active);
        $plan = $this->plan($review);
        $note = 'Fictional registrar readmission decision';
        $result = $service->commit($hiatus->studentId, $hiatus->id, $plan, $note);
        self::assertTrue($f->admissions->student($hiatus->studentId)->active);
        self::assertSame('2026-08-09', $h->repository->find($hiatus->id)->dates->end->format('Y-m-d'));
        self::assertSame($result, $service->commit($hiatus->studentId, $hiatus->id, $plan, $note));
        self::assertSame($result, $service->saved($hiatus->studentId, $hiatus->id, $plan->operation));
        self::assertCount(2, $f->sql->rows('sis_student_hiatus_actions'));
        self::assertSame($events, $f->financialEvents());
        self::assertSame($academic, $f->sql->rows('sis_academic_credits'));
        self::assertStringNotContainsString($note, json_encode($f->sql->rows('sis_student_hiatus_actions')));
        self::assertStringNotContainsString($note, json_encode($f->sql->rows('sis_audit')));
        $history = $h->history->history($hiatus->studentId, $hiatus->id)['items'];
        self::assertSame($note, $history[0]['note']);
        self::assertSame($result['result'], $history[0]['return_result']);
        self::assertSame($plan->operation->value, $h->history->view($hiatus->studentId, $hiatus->id)['return_operation_id']);
        $this->expectException(ValidationException::class);
        $service->review($hiatus->studentId, $hiatus->id, new DateTimeImmutable('2026-08-10'));
    }

    public function testAlreadyEndedLeaveStaysHistoricalAndActivityCanRemainInactive(): void
    {
        $h = new HiatusFixture();
        $hiatus = $this->hiatus($h, '2026-08-03');
        $service = $h->returns();
        $review = $service->review($hiatus->studentId, $hiatus->id, new DateTimeImmutable('2026-08-10'));
        self::assertSame('2026-08-03', $review['hiatus_ends_on']);
        $result = $service->commit($hiatus->studentId, $hiatus->id, $this->plan($review, false), 'Fictional pending activation');
        self::assertFalse($result['result']['student_active']);
        self::assertFalse($h->sis->admissions->student($hiatus->studentId)->active);
    }

    public function testHoldsAreReviewedAsEffectsWithoutPrivateNotesAndRemainInForce(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $hiatus = $this->hiatus($h);
        $person = $f->admissions->student($hiatus->studentId)->personId;
        $f->sql->insert('sis_restrictions', ['id' => EntityId::generate()->value, 'person_id' => $person->value,
            'code' => 'PRIVATE-MEDICAL', 'severity' => 100, 'starts_on' => '2026-08-01', 'ends_on' => null,
            'blocks_staff' => 1]);
        $holds = $f->sql->rows('sis_restrictions');
        $f->access->staff = false;
        $f->access->grants = ['sis:registration:manage'];
        $service = $h->returns();
        $review = $service->review($hiatus->studentId, $hiatus->id, new DateTimeImmutable('2026-08-10'));
        self::assertTrue($review['staff_registration_blocked']);
        self::assertTrue($review['self_registration_blocked']);
        self::assertStringNotContainsString('PRIVATE-MEDICAL', json_encode($review));
        $service->commit($hiatus->studentId, $hiatus->id, $this->plan($review), 'Fictional return with a separate hold');
        self::assertSame($holds, $f->sql->rows('sis_restrictions'));
    }

    public function testStaleHoldProgramAndHiatusReviewsCannotBeApplied(): void
    {
        foreach (['hold', 'program', 'hiatus'] as $change) {
            $h = new HiatusFixture();
            $f = $h->sis;
            $hiatus = $this->hiatus($h);
            $service = $h->returns();
            $review = $service->review($hiatus->studentId, $hiatus->id, new DateTimeImmutable('2026-08-10'));
            if ($change === 'hold') {
                $f->sql->insert('sis_restrictions', ['id' => EntityId::generate()->value,
                    'person_id' => $f->admissions->student($hiatus->studentId)->personId->value,
                    'code' => 'ADVISING', 'severity' => 99, 'starts_on' => '2026-08-01', 'ends_on' => null,
                    'blocks_staff' => 1]);
            } elseif ($change === 'program') {
                $f->sql->update('sis_programs', ['title' => 'Changed reviewed program'], ['id' => $f->program->value]);
            } else {
                $h->history->end(
                    $hiatus->studentId,
                    $hiatus->id,
                    EntityId::generate(),
                    new DateTimeImmutable('2026-08-05'),
                    $hiatus->revision(),
                    'Fictional correction'
                );
            }
            try {
                $service->commit($hiatus->studentId, $hiatus->id, $this->plan($review), 'Fictional stale return');
                self::fail('An unreviewed ' . $change . ' change was accepted.');
            } catch (ConcurrentModificationException) {
                self::assertFalse($f->admissions->student($hiatus->studentId)->active);
                self::assertNull($h->repository->returned($hiatus->id));
            }
        }
    }

    public function testFutureAndSameStartReturnDatesAreRejected(): void
    {
        foreach (['2026-08-01', '2026-08-11'] as $date) {
            $h = new HiatusFixture();
            $hiatus = $this->hiatus($h);
            try {
                $h->returns()->review($hiatus->studentId, $hiatus->id, new DateTimeImmutable($date));
                self::fail('Invalid return date accepted.');
            } catch (ValidationException) {
                self::assertFalse($h->sis->admissions->student($hiatus->studentId)->active);
            }
        }
    }

    public function testPrivateReturnReadsAndWritesRequireRegistrarPermissionAndMatchingStudent(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $hiatus = $this->hiatus($h);
        $service = $h->returns();
        $review = $service->review($hiatus->studentId, $hiatus->id, new DateTimeImmutable('2026-08-10'));
        $plan = $this->plan($review);
        $service->commit($hiatus->studentId, $hiatus->id, $plan, 'Fictional return');
        $other = $f->student();
        try {
            $service->saved($other->id, $hiatus->id, $plan->operation);
            self::fail('A return case moved to another student.');
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $f->access->staff = false;
        $f->access->grants = ['sis:student:read'];
        foreach (['review', 'commit', 'saved'] as $action) {
            try {
                match ($action) {
                    'review' => $service->review($hiatus->studentId, $hiatus->id, $plan->on),
                    'commit' => $service->commit($hiatus->studentId, $hiatus->id, $plan, 'Fictional return'),
                    'saved' => $service->saved($hiatus->studentId, $hiatus->id, $plan->operation),
                };
                self::fail('An owner or student reader accessed private return records.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testReturnRollsBackWhenAuditFailsAfterActivityChange(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $hiatus = $this->hiatus($h);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function (EntityId $actor, string $action, EntityId $subject): void {
            if ($action === 'student.hiatus_returned') {
                throw new RuntimeException('Fictional audit failure');
            }
        });
        $service = $h->returns($audit);
        $review = $service->review($hiatus->studentId, $hiatus->id, new DateTimeImmutable('2026-08-10'));
        try {
            $service->commit($hiatus->studentId, $hiatus->id, $this->plan($review), 'Fictional failed return');
            self::fail('Audit failure was ignored.');
        } catch (RuntimeException $error) {
            self::assertSame('Fictional audit failure', $error->getMessage());
        }
        self::assertFalse($f->admissions->student($hiatus->studentId)->active);
        self::assertNull($h->repository->find($hiatus->id)->dates->end);
        self::assertNull($h->repository->returned($hiatus->id));
    }
}
