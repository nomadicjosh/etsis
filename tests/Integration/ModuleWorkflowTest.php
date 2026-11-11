<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Finance\Enum\EntryType;
use Domain\Finance\LedgerLine;
use Domain\Finance\Service\LedgerService;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Gradebook\Assignment;
use Domain\Gradebook\GradeDefinition;
use Domain\Gradebook\Service\GradebookService;
use Domain\HumanResources\Service\TimesheetService;
use Domain\Scheduling\Service\BookingService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Service\TransferService;
use Domain\Transfer\TransferEquivalency;
use Infrastructure\Persistence\PdoTransactionalEventStore;
use Infrastructure\Persistence\Repository\EventSourcedJournalRepository;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlGradebookRepository;
use Infrastructure\Persistence\Repository\SqlTimeRepository;
use Infrastructure\Persistence\Repository\SqlTransferRepository;
use PHPUnit\Framework\TestCase;

final class ModuleWorkflowTest extends TestCase
{
    private function gradebook(SisFixture $f): GradebookService
    {
        return new GradebookService(
            new SqlGradebookRepository($f->sql),
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->academicCredits(),
        );
    }

    public function testBookingsCannotApproveEnclosingConflictsAndCancelledSlotsAreReleased(): void
    {
        $f = new SisFixture();
        $service = new BookingService(
            new SqlBookingRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
        );
        $room = $service->addRoom("LAB-1", 30);
        $first = $service->request($room, "Class", [
            new TimeSlot(
                new DateTimeImmutable("2026-08-10T10:00:00Z"),
                new DateTimeImmutable("2026-08-10T11:00:00Z"),
            ),
        ]);
        $service->approve($first->id);
        $other = $service->request($room, "Workshop", [
            new TimeSlot(
                new DateTimeImmutable("2026-08-10T09:00:00Z"),
                new DateTimeImmutable("2026-08-10T12:00:00Z"),
            ),
        ]);
        try {
            $service->approve($other->id);
            self::fail("An enclosing conflict was approved.");
        } catch (DomainException) {
            self::assertSame("requested", $service->get($other->id)->status);
        }
        $service->cancel($first->id);
        self::assertSame("approved", $service->approve($other->id)->status);
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $service->cancel($other->id);
    }

    public function testTimesheetsPreventSpoofingOverlapsAndSelfApproval(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $service = new TimesheetService(
            new SqlTimeRepository($f->sql),
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
        );
        $employee = $service->addEmployee($student->personId, "Library Assistant");
        $work = new TimeSlot(
            new DateTimeImmutable("2026-08-10T08:00:00Z"),
            new DateTimeImmutable("2026-08-10T12:00:00Z"),
        );
        $entry = $service->submit($employee, $work);
        try {
            $service->decide($entry->id, true);
            self::fail("A timesheet owner approved their own entry.");
        } catch (DomainException) {
            self::assertSame("pending", $service->get($entry->id)->status);
        }
        try {
            $service->submit($employee, $work);
            self::fail("An overlapping shift was accepted.");
        } catch (DomainException) {
            self::assertCount(1, $f->sql->rows("sis_timesheets"));
        }
        $f->access->actor = $f->user();
        $service->decide($entry->id, true);
        self::assertSame("approved", $service->get($entry->id)->status);
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $service->submit(
            $employee,
            new TimeSlot(
                new DateTimeImmutable("2026-08-11T08:00:00Z"),
                new DateTimeImmutable("2026-08-11T12:00:00Z"),
            ),
        );
    }

    public function testFacultyAssignmentAndStaleGradesAreEnforced(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $faculty = $f->user();
        $section = $f->section(faculty: $faculty);
        $enrollment = $f->service->register($student->id, $section->id);
        $gradebook = $this->gradebook($f);
        $grade = new GradeDefinition(EntityId::generate(), "A", 4000, true, true);
        $f->legacyGrade($grade);
        $assignment = new Assignment(
            EntityId::generate(),
            $section->id,
            "Problem Set",
            10000,
            new DateTimeImmutable("2026-08-20"),
        );
        $f->access->actor = $faculty;
        $f->access->staff = false;
        $gradebook->addAssignment($assignment);
        $gradebook->score($assignment->id, $student->id, 0, 0);
        self::assertSame(0, $gradebook->scores($student->id, $section->id)[0]["earned_hundredths"]);
        $gradebook->finalGrade($student->id, $section->id, $grade->id, 0);
        $credit = $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value]);
        self::assertSame(3000, $credit["completed_milli"]);
        self::assertSame(12000, $credit["grade_points_milli"]);
        try {
            $gradebook->finalGrade($student->id, $section->id, $grade->id, 0);
            self::fail("A stale grade update was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame(
                1,
                $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value])["version"],
            );
        }
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $gradebook->score($assignment->id, $student->id, 10000, 1);
    }

    public function testTransferCreditIsAtomicIdempotentAndCanSatisfyPrerequisite(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $course = $f->section();
        $advanced = $f->section();
        $f->registration->addPrerequisite($advanced->courseId, $course->courseId);
        try {
            $f->service->register($student->id, $advanced->id);
            self::fail("An unmet prerequisite was ignored.");
        } catch (DomainException) {
            self::assertCount(0, $f->sql->rows("sis_enrollments"));
        }
        $grade = new GradeDefinition(EntityId::generate(), "TR", 0, true, false);
        $f->legacyGrade($grade);
        $service = new TransferService(
            new SqlTransferRepository($f->sql, $f->gradeCatalog()),
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository(
                $f->sql,
                $f->gradeCatalog(),
            ),
            $f->transferCredits(),
        );
        $institution = $service->addInstitution("EXT-1", "Example College");
        $external = $service->addExternalCourse($institution, "CS101", "Introduction");
        $equivalency = new TransferEquivalency(
            EntityId::generate(),
            $institution,
            $external,
            $course->courseId,
            new DateRange(new DateTimeImmutable("2020-01-01")),
            2,
            3000,
            "TR",
        );
        $service->equate($equivalency);
        $revision = \Domain\Transfer\ValueObject\TransferRevision::of(
            "equivalencies",
            new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository(
                $f->sql,
                $f->gradeCatalog(),
            )->get("equivalencies", $equivalency->id),
        );
        $on = new DateTimeImmutable("2026-05-01");
        $courseVersion = $f->publishCourse($equivalency->institutionalCourseId);
        $credit = $service->award($student->id, $equivalency->id, $institution, $f->term, $on, 2, $revision, $courseVersion);
        self::assertSame(
            $credit->value,
            $service->award($student->id, $equivalency->id, $institution, $f->term, $on, 2, $revision, $courseVersion)->value,
        );
        self::assertCount(1, $f->sql->rows("sis_transfer_awards"));
        self::assertSame("N", $f->service->register($student->id, $advanced->id)->status->value);
        self::assertSame("TR", $service->transcript($student->id)[0]["grade_code"]);
    }

    public function testGeneralLedgerIsBalancedEventSourcedAndReversible(): void
    {
        $f = new SisFixture();
        $repository = new EventSourcedJournalRepository(
            $f->sql,
            EventStoreFixture::framework($f->connection->queryBuilder()),
            $f->transactions,
        );
        $service = new LedgerService($repository, $f->access);
        $receivable = $service->addAccount("AR", "Student Receivable", "USD");
        $revenue = $service->addAccount("REV", "Tuition Revenue", "USD");
        try {
            $service->post("BAD-1", new DateTimeImmutable("2026-08-10"), [
                new LedgerLine($receivable, new Money(1000, "USD")),
                new LedgerLine($revenue, new Money(-900, "USD")),
            ]);
            self::fail("An unbalanced journal was accepted.");
        } catch (DomainException) {
            self::assertCount(0, $f->sql->rows("sis_journals"));
        }
        $journal = $service->post("J-1", new DateTimeImmutable("2026-08-10"), [
            new LedgerLine($receivable, new Money(1000, "USD")),
            new LedgerLine($revenue, new Money(-1000, "USD")),
        ]);
        self::assertSame(2, count($service->get($journal->aggregateId())->lines()));
        $service->reverse($journal->aggregateId());
        $service->reverse($journal->aggregateId());
        self::assertTrue($service->get($journal->aggregateId())->isReversed());
        self::assertCount(2, $f->sql->rows("sis_history_events"));
    }

    public function testStudentAccountCorrectionsAppendEventsWithoutDeletingHistory(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $account = StudentAccount::open(new AccountId(), $student->id, $f->term, "USD");
        $operation = EntityId::generate();
        $account->post($operation, EntryType::Charge, new Money(10000, "USD"), $f->access->actor);
        $f->accounts->save($account);
        $account = $f->accounts->get($account->aggregateId());
        $account->reverse($operation, $f->access->actor);
        $account->reverse($operation, $f->access->actor);
        $f->accounts->save($account);
        self::assertSame(0, $f->accounts->get($account->aggregateId())->balance()->minor);
        self::assertCount(3, $f->sql->rows("sis_history_events"));
    }

    public function testTermFeesApplyOnceAcrossRegistrationAndBatchBillingAndCanBeRetired(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $db = $f->connection->queryBuilder();
        $rules = new \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository(
            $f->sql,
            EventStoreFixture::framework($db),
            $f->transactions,
        );
        $billing = new \Infrastructure\Persistence\EventSourcedRegistrationBilling(
            $f->accounts,
            $f->sql,
            $rules,
            $f->admissions,
            $f->primaryProgramLedger(),
        );
        $service = new \Domain\Finance\Service\TermBillingService(
            $rules,
            $billing,
            $f->registration,
            $f->access,
            $f->transactions,
            $f->audit,
        );
        $rule = $service->define($f->term, $f->program, "Registration Fee", "fee", new Money(2500, "USD"));
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $locator = $f->sql->one("sis_student_accounts", ["student_id" => $student->id->value]);
        $id = new AccountId($locator["id"]);
        self::assertSame(12500, $f->accounts->get($id)->balance()->minor);
        $service->generate($f->term, [$student->id]);
        self::assertSame(12500, $f->accounts->get($id)->balance()->minor);
        self::assertCount(2, $f->accounts->get($id)->entries());
        $service->retire($rule->aggregateId());
        $other = $f->student();
        $freeSection = $f->section(fee: 0);
        $f->service->register($other->id, $freeSection->id);
        self::assertCount(0, $f->sql->rows("sis_student_accounts", ["student_id" => $other->id->value]));
        self::assertSame(12500, $f->accounts->get($id)->balance()->minor);
    }
}
