<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentRecordService;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;

final class StudentRecordManagementTest extends TestCase
{
    private function service(SisFixture $f, ?AuditTrail $audit = null): StudentRecordService
    {
        return new StudentRecordService(
            new SqlStudentRecordRepository($f->sql),
            $f->admissions,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->catalogEnrollment()
        );
    }

    private function program(SisFixture $f, string $code = 'BA.ART'): EntityId
    {
        $id = EntityId::generate();
        $f->sql->insert('sis_programs', ['id' => $id->value, 'code' => $code, 'title' => 'Fictional program']);
        return $id;
    }

    private function assignment(StudentRecordService $service, EntityId $student, EntityId $id): array
    {
        foreach ($service->programs($student)['items'] as $row) {
            if ($row['id'] === $id->value) {
                return $row;
            }
        }
        throw new RuntimeException('Fixture assignment not found.');
    }

    public function testPrimaryProgramChangesPreserveEntryTermCreditsAndPostedFinances(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $service = $this->service($f);
        $section = $f->section(20, 500);
        $enrollment = $f->service->register($student->id, $section->id, true);
        $credits = $f->sql->rows('sis_academic_credits');
        $account = new AccountId($f->sql->rows('sis_student_accounts')[0]['id']);
        $before = $service->view($student->id);
        $program = $this->program($f);
        $assignment = $service->assign($student->id, $program, $f->term);
        self::assertSame($f->program->value, $service->view($student->id)['primary_program']['id']);
        $service->primary($student->id, $assignment, $before['revision']);
        $after = $service->view($student->id);
        self::assertSame($program->value, $after['primary_program']['id']);
        self::assertSame($before['entry_term'], $after['entry_term']);
        self::assertSame($before['institutional_id'], $after['institutional_id']);
        self::assertSame($credits, $f->sql->rows('sis_academic_credits'));
        self::assertSame(500, $f->accounts->get($account)->balance()->minor);
        self::assertSame($enrollment->id->value, $f->registration->enrollment($student->id, $section->id)->id->value);
        self::assertCount(2, array_filter($service->programs($student->id)['items'], fn ($row) => $row['status'] === 'active'));
        try {
            $service->activity($student->id, false, $before['revision']);
            self::fail('A stale student edit was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertTrue($service->view($student->id)['active']);
        }
    }

    public function testPrimaryAssignmentsCannotCloseAndStaleProgramChangesAreRejected(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $service = $this->service($f);
        $original = $service->programs($student->id)['items'][0];
        try {
            $service->programActivity($student->id, new EntityId($original['id']), false, $original['revision']);
            self::fail('The primary program was closed.');
        } catch (DomainException) {
            self::assertTrue($service->programs($student->id)['items'][0]['is_primary']);
        }
        $assigned = $service->assign($student->id, $this->program($f), $f->term);
        $row = $this->assignment($service, $student->id, $assigned);
        $service->programActivity($student->id, $assigned, false, $row['revision']);
        $closed = $this->assignment($service, $student->id, $assigned);
        self::assertSame('closed', $closed['status']);
        try {
            $service->primary($student->id, $assigned, $service->view($student->id)['revision']);
            self::fail('A closed assignment became primary.');
        } catch (DomainException) {
            self::assertFalse($closed['is_primary']);
        }
        $service->programActivity($student->id, $assigned, true, $closed['revision']);
        self::assertSame('active', $this->assignment($service, $student->id, $assigned)['status']);
        try {
            $service->programActivity($student->id, $assigned, false, $closed['revision']);
            self::fail('A stale assignment edit was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('active', $this->assignment($service, $student->id, $assigned)['status']);
        }
    }

    public function testInactiveStudentsKeepHistoryAndCanReactivateWithoutNewRecords(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $service = $this->service($f);
        $section = $f->section(20, 0);
        $f->service->register($student->id, $section->id, true);
        $service->activity($student->id, false, $service->view($student->id)['revision']);
        self::assertFalse($service->view($student->id)['active']);
        self::assertCount(1, $service->programs($student->id)['items']);
        $newSection = $f->section(20, 0);
        try {
            $f->service->register($student->id, $newSection->id, true);
            self::fail('An inactive student added a registration.');
        } catch (DomainException) {
            self::assertNull($f->registration->enrollment($student->id, $newSection->id));
        }
        self::assertTrue($f->registration->enrollment($student->id, $section->id)->status->isEnrolled());
        $service->activity($student->id, true, $service->view($student->id)['revision']);
        self::assertSame($student->id->value, $f->service->register($student->id, $newSection->id, true)->studentId->value);
    }

    public function testOwnerReadsDoNotGrantRegistrarWritesAndOtherUsersCannotRead(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $service = $this->service($f);
        $row = $service->programs($student->id)['items'][0];
        $revision = $service->view($student->id)['revision'];
        $f->access->staff = false;
        self::assertSame($student->id->value, $service->view($student->id)['id']);
        foreach (
            [fn () => $service->activity($student->id, false, $revision),
            fn () => $service->assign($student->id, $f->program, $f->term),
            fn () => $service->primary($student->id, new EntityId($row['id']), $revision),
            fn () => $service->programActivity($student->id, new EntityId($row['id']), false, $row['revision'])] as $write
        ) {
            try {
                $write();
                self::fail('Ownership granted registrar writes.');
            } catch (AccessDeniedException) {
                self::assertTrue($service->view($student->id)['active']);
            }
        }
        $f->access->actor = $f->user();
        foreach ([fn () => $service->view($student->id), fn () => $service->programs($student->id)] as $read) {
            try {
                $read();
                self::fail('A private academic record was exposed.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAssignmentMustBelongToTheTargetStudent(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $student = $f->student();
        $other = $f->student();
        $foreign = $service->programs($other->id)['items'][0];
        foreach (
            [fn () => $service->primary($student->id, new EntityId($foreign['id']), $service->view($student->id)['revision']),
            fn () => $service->programActivity($student->id, new EntityId($foreign['id']), false, $foreign['revision'])] as $write
        ) {
            try {
                $write();
                self::fail('Another student assignment was changed.');
            } catch (RecordNotFoundException) {
                self::assertSame('active', $service->programs($other->id)['items'][0]['status']);
            }
        }
    }

    public function testDuplicateProgramsRequireClosingOrReopeningTheirExistingAssignment(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $student = $f->student();
        $program = $this->program($f);
        $assigned = $service->assign($student->id, $program, $f->term);
        try {
            $service->assign($student->id, $program, $f->term);
            self::fail('The same program had two active assignments.');
        } catch (ValidationException $error) {
            self::assertSame('program_id', $error->errors[0]['field']);
        }
        $row = $this->assignment($service, $student->id, $assigned);
        $service->programActivity($student->id, $assigned, false, $row['revision']);
        try {
            $service->assign($student->id, $program, $f->term);
            self::fail('A closed program/term pair was duplicated.');
        } catch (ValidationException $error) {
            self::assertSame('program_id', $error->errors[0]['field']);
            self::assertCount(2, $service->programs($student->id)['items']);
        }
    }

    public function testProgramsArePagedAndCatalogReferencesAreVerified(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $student = $f->student();
        for ($i = 0; $i < 50; ++$i) {
            $service->assign($student->id, $this->program($f, 'FIX.' . $i), $f->term);
        }
        $first = $service->programs($student->id);
        self::assertCount(50, $first['items']);
        $second = $service->programs($student->id, new EntityId($first['next_after']));
        self::assertCount(1, $second['items']);
        self::assertNull($second['next_after']);
        self::assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        $this->expectException(RecordNotFoundException::class);
        $service->assign($student->id, EntityId::generate(), $f->term);
    }

    public function testAuditFailureRollsBackPrimaryActivityAndAssignmentWrites(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $normal = $this->service($f);
        $program = $this->program($f);
        $assigned = $normal->assign($student->id, $program, $f->term);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('fictional failure'));
        $service = $this->service($f, $audit);
        $before = $normal->view($student->id);
        foreach (
            [fn () => $service->activity($student->id, false, $before['revision']),
            fn () => $service->primary($student->id, $assigned, $before['revision']),
            fn () => $service->assign($student->id, $this->program($f, 'MORE.' . bin2hex(random_bytes(2))), $f->term)] as $write
        ) {
            try {
                $write();
                self::fail('An unaudited student change committed.');
            } catch (RuntimeException) {
                self::assertSame($before, $normal->view($student->id));
                self::assertCount(2, $normal->programs($student->id)['items']);
            }
        }
    }

    public function testBillingAcquiresEveryStudentLockBeforeReadingProgramEligibility(): void
    {
        $f = new SisFixture();
        $one = $f->student()->id;
        $two = $f->student()->id;
        $locked = [];
        $charged = [];
        $registration = $this->createMock(\Domain\Registration\Repository\RegistrationRepository::class);
        $registration->expects(self::exactly(2))->method('lockStudent')
            ->willReturnCallback(function (EntityId $id) use (&$locked): void {
                $locked[] = $id->value;
            });
        $billing = $this->createMock(\Domain\Registration\Service\RegistrationBilling::class);
        $billing->expects(self::exactly(2))->method('charge')->willReturnCallback(
            function (EntityId $id) use (&$locked, &$charged): void {
                self::assertCount(2, $locked, 'Program eligibility was read before the whole lock set was acquired.');
                $charged[] = $id->value;
            }
        );
        $service = new \Domain\Finance\Service\TermBillingService(
            $this->createStub(\Domain\Finance\Repository\BillingRuleRepository::class),
            $billing,
            $registration,
            $f->access,
            $f->transactions,
            $f->audit
        );
        $service->generate($f->term, [$two, $one]);
        $expected = [$one->value, $two->value];
        sort($expected);
        self::assertSame($expected, $locked);
        self::assertSame($expected, $charged);
    }

    public function testAProgramCannotHaveTwoActiveEntryTermsForOneStudent(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $service = $this->service($f);
        $program = $this->program($f);
        $first = $service->assign($student->id, $program, $f->term);
        $term = EntityId::generate();
        $f->sql->insert('sis_terms', ['id' => $term->value, 'code' => '27/FA', 'starts_on' => '2027-08-01',
            'ends_on' => '2027-12-20', 'add_drop_ends_on' => '2027-08-15']);
        try {
            $service->assign($student->id, $program, $term);
            self::fail('Two active assignments made the primary program ambiguous.');
        } catch (ValidationException) {
            self::assertCount(2, $service->programs($student->id)['items']);
        }
        $service->programActivity($student->id, $first, false, $this->assignment($service, $student->id, $first)['revision']);
        $service->assign($student->id, $program, $term);
        try {
            $service->programActivity($student->id, $first, true, $this->assignment($service, $student->id, $first)['revision']);
            self::fail('An earlier cohort was reopened alongside another active assignment.');
        } catch (ValidationException) {
            self::assertSame('closed', $this->assignment($service, $student->id, $first)['status']);
        }
    }
}
