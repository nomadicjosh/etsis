<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\RegistrationWindow;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Service\PrerequisiteService;
use Domain\Registration\Service\RegistrationService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use DomainException;
use Infrastructure\Persistence\EventSourcedRegistrationBilling;
use Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository;
use Infrastructure\Persistence\Repository\SqlRestrictionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class RegistrationCompletionBoundaryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function service(
        SisFixture $s,
        ?AuditTrail $audit = null,
        ?RegistrationRepository $repository = null,
        ?PrerequisiteService $prerequisites = null,
    ): RegistrationService {
        $billing = new EventSourcedRegistrationBilling(
            $s->accounts,
            $s->sql,
            new EventSourcedBillingRuleRepository(
                $s->sql,
                \EventStoreFixture::framework($s->connection->queryBuilder()),
                $s->transactions,
                \EventStoreFixture::financialWriter($s->connection->queryBuilder()),
            ),
            $s->admissions,
            $s->primaryProgramLedger()
        );
        return new RegistrationService(
            $repository ?? $s->registration,
            $s->admissions,
            $s->access,
            $s->transactions,
            $billing,
            $audit ?? $s->audit,
            $s->clock,
            $s->drops,
            $s->academicCredits(),
            $s->enrollmentLedger(),
            $s->activityLedger(),
            $s->primaryProgramLedger(),
            $s->workPursuits(),
            $prerequisites ?? $s->prerequisites(),
            $s->schedules(),
            $s->corequisites(),
            $s->windowPolicies(),
            $s->waitlistGuard($audit),
            $s->graduateGate(),
            $s->enrollmentNotifications(),
        );
    }

    private function audit(SisFixture $s, callable $after): AuditTrail
    {
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::once())->method('record')->willReturnCallback(
            static function (EntityId $actor, string $action, EntityId $subject) use ($s, $after): void {
                $s->audit->record($actor, $action, $subject);
                $after();
            }
        );
        return $audit;
    }

    private function repository(SisFixture $s, string $hooked, callable $after): RegistrationRepository
    {
        $proxy = $this->createStub(RegistrationRepository::class);
        foreach (get_class_methods(RegistrationRepository::class) as $method) {
            $proxy->method($method)->willReturnCallback(static function (...$arguments) use (
                $s,
                $method,
                $hooked,
                $after
            ): mixed {
                $result = $s->registration->$method(...$arguments);
                if ($method === $hooked) {
                    $after();
                }
                return $result;
            });
        }
        return $proxy;
    }

    private function facts(SisFixture $s): array
    {
        $facts = [];
        foreach (
            ['sis_history_events', 'sis_history_operations', 'sis_history_streams',
            'sis_registration_streams', 'sis_registration_decisions', 'sis_academic_credit_streams',
            'sis_work_pursuit_scopes', 'sis_enrollments', 'sis_academic_credits', 'sis_student_accounts',
            'sis_registration_batches', 'sis_audit', 'sis_students', 'sis_persons'] as $table
        ) {
            $facts[$table] = $s->sql->rows($table);
        }
        return $facts;
    }

    public function testPolicyPublicationDuringCompletionRollsBackEnrollmentCreditChargeAndTheNestedPolicyChange(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $window = $s->registration->window($s->term);
        $pending = $s->windowPolicies()->review($s->term, $window, 0, 'Fictional policy approval.');
        $before = $this->facts($s);
        $priorWindow = $s->sql->rows('sis_reg_windows');
        $audit = $this->audit($s, static function () use ($s, $pending): void {
            $s->windowPolicies()->publish($s->term, $pending['review_token']);
        });
        try {
            $this->service($s, $audit)->register($student->id, $section->id, true);
            self::fail('Registration completed against a policy that changed during its final audit.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
            self::assertSame($priorWindow, $s->sql->rows('sis_reg_windows'));
        }
    }

    public static function staffChannels(): iterable
    {
        yield 'staff entering another student explicitly' => [true, false];
        yield 'staff entering another student without a browser mode flag' => [false, false];
        yield 'staff entering their own record explicitly' => [true, true];
    }

    #[DataProvider('staffChannels')]
    public function testPermissionLossAfterChargeAndAuditRollsBackAllRegistrationEffects(
        bool $explicit,
        bool $owner,
    ): void {
        $s = new SisFixture();
        $student = $s->student($owner ? $s->access->actor : $s->user());
        $section = $s->section();
        $before = $this->facts($s);
        $audit = $this->audit($s, static function () use ($s): void {
            $s->access->staff = false;
        });
        try {
            $this->service($s, $audit)->register($student->id, $section->id, $explicit);
            self::fail('Registration committed after its required staff authority was removed.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testActorChangeDuringTheFinalAuditRollsBackItsOriginalActorsChargeAndDecision(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $other = $s->user();
        $before = $this->facts($s);
        $audit = $this->audit($s, static function () use ($s, $other): void {
            $s->access->actor = $other;
        });
        try {
            $this->service($s, $audit)->register($student->id, $section->id, true);
            self::fail('A different actor completed registration.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testSelfServiceWindowClosingDuringTheFinalAuditRollsBackSeatCreditChargeAndAudit(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $s->access->staff = false;
        $end = $s->clock->time->modify('+1 minute');
        $s->registration->configureWindow($s->term, new RegistrationWindow(new DateRange(
            $s->clock->time->modify('-1 minute'),
            $end
        ), 5));
        $audit = $this->audit($s, static function () use ($s, $end): void {
            $s->clock->time = $end->modify('+1 second');
        });
        $before = $this->facts($s);
        try {
            $this->service($s, $audit)->register($student->id, $section->id);
            self::fail('Registration completed after its self-service deadline.');
        } catch (DomainException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testTheExistingInclusiveDeadlineStillAllowsCompletionExactlyAtTheEnd(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $s->access->staff = false;
        $end = $s->clock->time->modify('+1 minute');
        $s->registration->configureWindow($s->term, new RegistrationWindow(new DateRange(
            $s->clock->time->modify('-1 minute'),
            $end
        ), 5));
        $audit = $this->audit($s, static function () use ($s, $end): void {
            $s->clock->time = $end;
        });
        $enrollment = $this->service($s, $audit)->register($student->id, $section->id);
        self::assertSame(\Domain\Registration\Enum\RegistrationStatus::SelfRegistered, $enrollment->status);
        self::assertCount(1, $s->sql->rows('sis_enrollments'));
        self::assertCount(1, $s->sql->rows('sis_student_accounts'));
    }

    public static function registrationChannels(): iterable
    {
        yield 'student self-service' => [false];
        yield 'authorized staff' => [true];
    }

    #[DataProvider('registrationChannels')]
    public function testDatedHoldStartingDuringConfirmationIsCheckedInTheSameChannelPolicy(bool $byStaff): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $s->access->staff = $byStaff;
        new SqlRestrictionRepository($s->sql)->add(new Restriction(
            EntityId::generate(),
            $student->personId,
            'SCHEDULED',
            100,
            new DateRange(new DateTimeImmutable('2026-08-11')),
            true
        ));
        $audit = $this->audit($s, static function () use ($s): void {
            $s->clock->time = new DateTimeImmutable('2026-08-11T10:00:00-07:00');
        });
        $before = $this->facts($s);
        try {
            $this->service($s, $audit)->register($student->id, $section->id, $byStaff);
            self::fail('A newly active blocking hold was ignored.');
        } catch (DomainException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testStaffRegistrationKeepsItsExistingWindowAndHoldExceptions(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        new SqlRestrictionRepository($s->sql)->add(new Restriction(
            EntityId::generate(),
            $student->personId,
            'SELF.ONLY',
            100,
            new DateRange(new DateTimeImmutable('2026-08-11')),
            false
        ));
        $audit = $this->audit($s, static function () use ($s): void {
            $s->clock->time = new DateTimeImmutable('2026-09-01T10:00:00-07:00');
        });
        $enrollment = $this->service($s, $audit)->register($student->id, $section->id, true);
        self::assertSame(\Domain\Registration\Enum\RegistrationStatus::StaffRegistered, $enrollment->status);
        self::assertCount(1, $s->sql->rows('sis_academic_credits'));
    }

    public function testIdentityChangeWhileWaitingForLocksCannotUseTheFormerPersonsHoldLock(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $other = new \Domain\Person\Person(
            EntityId::generate(),
            $s->user(),
            'Fictional',
            'Replacement',
            new \Domain\Person\ValueObject\InstitutionalId('REPLACEMENT')
        );
        $s->admissions->addPerson($other);
        $section = $s->section();
        $proxy = $this->repository($s, 'lockStudent', static function () use ($s, $student, $other): void {
            $s->sql->update('sis_students', ['person_id' => $other->id->value], ['id' => $student->id->value]);
        });
        $before = $this->facts($s);
        try {
            $this->service($s, repository: $proxy)->register($student->id, $section->id, true);
            self::fail('Registration continued with an unlocked replacement person.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testChangedAccountLinkAtTheFinalAuditCannotChangeTheReviewedAuthority(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $other = $s->user();
        $audit = $this->audit($s, static function () use ($s, $student, $other): void {
            $s->sql->update('sis_persons', ['owner_user_id' => $other->value], ['id' => $student->personId->value]);
        });
        $before = $this->facts($s);
        try {
            $this->service($s, $audit)->register($student->id, $section->id);
            self::fail('A changed account link completed the original authorization.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testAcceptedRetryKeepsItsOriginalResultAfterTheDeadlineAndANewHoldWithoutChargingAgain(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $s->access->staff = false;
        $original = $s->service->register($student->id, $section->id);
        $s->clock->time = new DateTimeImmutable('2026-09-01T10:00:00-07:00');
        new SqlRestrictionRepository($s->sql)->add(new Restriction(
            EntityId::generate(),
            $student->personId,
            'NEW.HOLD',
            100,
            new DateRange(new DateTimeImmutable('2026-09-01')),
            true
        ));
        $before = $this->facts($s);
        self::assertSame($original->id->value, $s->service->register($student->id, $section->id)->id->value);
        self::assertSame($before, $this->facts($s));
    }

    public function testAnAcceptedRetryStillRequiresCurrentAuthorityAfterItsHistoryIsRead(): void
    {
        $s = new SisFixture();
        $student = $s->student($s->user());
        $section = $s->section();
        $s->service->register($student->id, $section->id, true);
        $proxy = $this->repository($s, 'enrollment', static function () use ($s): void {
            $s->access->staff = false;
        });
        $before = $this->facts($s);
        try {
            $this->service($s, repository: $proxy)->register($student->id, $section->id, true);
            self::fail('An accepted retry returned a private result after losing authority.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testEligibilityUsesTheClockAfterPrerequisiteHistoryVerification(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $prior = $s->section(faculty: $s->access->actor);
        $enrollment = $s->service->register($student->id, $prior->id);
        $grade = new \Domain\Gradebook\GradeDefinition(EntityId::generate(), 'P', 0, true, false);
        $s->legacyGrade($grade);
        $review = $s->academicCredits()->review($student->id, $prior->id, $enrollment->id, $grade->id, 0);
        $s->academicCredits()->commit($student->id, $prior->id, $review['review_token']);
        $next = $s->section();
        $s->registration->addPrerequisite($next->courseId, $prior->courseId);
        $end = $s->clock->time->modify('+1 minute');
        $s->registration->configureWindow($s->term, new RegistrationWindow(new DateRange(
            $s->clock->time->modify('-1 minute'),
            $end
        ), 5));
        $s->access->staff = false;
        $repository = $this->createMock(\Domain\DegreeAudit\Repository\AcademicInputRepository::class);
        $real = new \Infrastructure\Persistence\Repository\SqlAcademicInputRepository($s->sql);
        $repository->expects(self::once())->method('inventory')->willReturnCallback(
            static function (EntityId $id) use ($s, $end, $real) {
                $s->clock->time = $end->modify('+1 second');
                return $real->inventory($id);
            }
        );
        $prerequisites = new PrerequisiteService(new \Domain\DegreeAudit\Service\AcademicInputVerifier(
            $repository,
            $s->academicCredits(),
            $s->transferCredits(),
            $s->enrollmentLedger()
        ), $s->workPursuits(), $s->prerequisitePermissionLedger());
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::never())->method('record');
        $before = $this->facts($s);
        try {
            $this->service($s, audit: $audit, prerequisites: $prerequisites)->register($student->id, $next->id);
            self::fail('Eligibility used the time before the prerequisite history read.');
        } catch (DomainException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testLateAuthorityLossForOneStudentRollsBackTheEntireReviewedGroup(): void
    {
        $s = new SisFixture();
        $first = $s->student();
        $second = $s->student();
        $section = $s->section();
        $audit = $this->createMock(AuditTrail::class);
        $calls = 0;
        $audit->expects(self::exactly(2))->method('record')->willReturnCallback(
            static function (EntityId $actor, string $action, EntityId $subject) use ($s, &$calls): void {
                $s->audit->record($actor, $action, $subject);
                if (++$calls === 2) {
                    $s->access->staff = false;
                }
            }
        );
        $batch = $s->registrationBatches(registration: $this->service($s, $audit));
        $selection = new \Domain\Registration\ValueObject\RegistrationBatchSelection(
            $section->id,
            $first->id,
            $second->id
        );
        $review = $batch->review($selection);
        $before = $this->facts($s);
        try {
            $batch->commit(
                new EntityId($review["operation_id"]),
                $selection,
                $review["revision"],
                $review["review_token"],
            );
            self::fail('A group kept earlier registrations after the final student lost authority.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->facts($s));
        }
    }
}
