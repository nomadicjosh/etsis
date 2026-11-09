<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Registration\Enum\RegistrationStatus;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use PHPUnit\Framework\TestCase;

final class RegistrationWorkflowTest extends TestCase
{
    public function testEnrollmentCreditChargeAndAuditAreOneTransactionAndRetryIsIdempotent(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $f->access->staff = false;
        $enrollment = $f->service->register($student->id, $section->id);
        self::assertSame(RegistrationStatus::SelfRegistered, $enrollment->status);
        self::assertSame(
            $enrollment->id->value,
            $f->service->register($student->id, $section->id)->id->value,
        );
        self::assertCount(1, $f->sql->rows("sis_enrollments"));
        self::assertCount(1, $f->sql->rows("sis_academic_credits"));
        self::assertCount(1, $f->sql->rows("sis_audit", ["action" => "registration.created"]));
        $locator = $f->sql->one("sis_student_accounts", ["student_id" => $student->id->value]);
        $account = $f->accounts->get(new \Domain\Finance\ValueObject\AccountId($locator["id"]));
        self::assertSame(10000, $account->balance()->minor);
    }

    public function testCapacityAndRegistrationHoldAreEnforcedWithoutPartialWrites(): void
    {
        $f = new SisFixture();
        $first = $f->student();
        $second = $f->student();
        $section = $f->section(1);
        $f->service->register($first->id, $section->id, true);
        try {
            $f->service->register($second->id, $section->id, true);
            self::fail("A full section accepted a student.");
        } catch (DomainException) {
            self::assertCount(1, $f->sql->rows("sis_enrollments"));
        }
        $other = $f->section();
        new \Infrastructure\Persistence\Repository\SqlRestrictionRepository($f->sql)->add(
            new Restriction(
                EntityId::generate(),
                $second->personId,
                "NOREG",
                100,
                new DateRange(new DateTimeImmutable("2026-01-01")),
                true,
            ),
        );
        $this->expectException(DomainException::class);
        $f->service->register($second->id, $other->id, true);
    }

    public function testFinancialFailureRollsBackEnrollmentAndCredit(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $account = \Domain\Finance\StudentAccount::open(
            new \Domain\Finance\ValueObject\AccountId(),
            $student->id,
            $f->term,
            "EUR",
        );
        $f->accounts->save($account);
        try {
            $f->service->register($student->id, $section->id, true);
            self::fail("Currency mismatch must reject registration.");
        } catch (DomainException) {
            self::assertCount(0, $f->sql->rows("sis_enrollments"));
            self::assertCount(0, $f->sql->rows("sis_academic_credits"));
            self::assertCount(1, $f->sql->rows("sis_history_events"));
        }
    }

    public function testOrdinaryDropCannotClearAnAlreadyPostedFinalGrade(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $enrollment = $f->service->register($student->id, $section->id);
        $grades = new \Infrastructure\Persistence\Repository\SqlGradebookRepository(
            $f->sql,
            $f->gradeCatalog(),
        );
        $grade = new \Domain\Gradebook\GradeDefinition(EntityId::generate(), "B", 3000, true, true);
        $f->legacyGrade($grade);
        $r = $f->academicCredits()->review($student->id, $section->id, $enrollment->id, $grade->id, 0);
        $f->academicCredits()->commit($student->id, $section->id, $r["review_token"]);
        $before = $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value]);
        $events = $f->sql->rows("sis_history_events");
        try {
            $f->service->drop($student->id, $section->id);
            self::fail("A drop erased a posted final grade.");
        } catch (\Domain\Shared\Exception\ValidationException) {
            self::assertSame(
                $before,
                $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value]),
            );
            self::assertSame(
                $enrollment->status,
                $f->registration->enrollment($student->id, $section->id)->status,
            );
            self::assertSame($events, $f->sql->rows("sis_history_events"));
        }
    }

    public function testLateDropPreservesWithdrawalAndZeroCompletedCredits(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $f->clock->time = new DateTimeImmutable("2026-08-20T10:00:00-07:00");
        $drop = $f->service->drop($student->id, $section->id);
        self::assertSame(RegistrationStatus::Withdrawn, $drop->status);
        $credit = $f->sql->one("sis_academic_credits", ["source_id" => $drop->id->value]);
        self::assertSame(0, $credit["completed_milli"]);
        self::assertSame(3000, $credit["attempted_milli"]);
        self::assertSame("W", $credit["status"]);
    }

    public function testLateDropKeepsItsChargeAndRetakingTheSameCourseInAnotherTermCreatesANewTermCharge(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $firstSection = $f->section();
        $firstEnrollment = $f->service->register($student->id, $firstSection->id, true);
        $firstAccountRow = $f->sql->one("sis_student_accounts", [
            "student_id" => $student->id->value,
            "term_id" => $f->term->value,
        ]);
        $firstAccountId = new \Domain\Finance\ValueObject\AccountId($firstAccountRow["id"]);
        $firstEntries = $f->accounts->get($firstAccountId)->entries();
        $firstEvents = $f->financialEvents();

        $f->clock->time = new DateTimeImmutable("2026-08-20T10:00:00-07:00");
        $f->service->drop($student->id, $firstSection->id);
        self::assertSame(10000, $f->accounts->get($firstAccountId)->balance()->minor);
        self::assertSame($firstEntries, $f->accounts->get($firstAccountId)->entries());
        self::assertSame($firstEvents, $f->financialEvents());
        $firstCredit = $f->sql->one("sis_academic_credits", ["source_id" => $firstEnrollment->id->value]);

        $nextTerm = EntityId::generate();
        $f->sql->insert("sis_terms", [
            "id" => $nextTerm->value,
            "code" => "2027SP",
            "starts_on" => "2027-01-11",
            "ends_on" => "2027-05-14",
            "add_drop_ends_on" => "2027-01-25",
        ]);
        $f->transactions->run(
            fn() => $f->registration->configureWindow(
                $nextTerm,
                new \Domain\Registration\RegistrationWindow(
                    new DateRange(new DateTimeImmutable("2027-01-01"), new DateTimeImmutable("2027-01-31")),
                    5,
                ),
            ),
        );
        $retakeSection = new \Domain\Registration\Section(
            EntityId::generate(),
            $firstSection->courseId,
            $nextTerm,
            null,
            $firstSection->code . ".RETAKE",
            20,
            true,
            true,
            $firstSection->fee,
        );
        $f->registration->addSection($retakeSection);
        $f->clock->time = new DateTimeImmutable("2027-01-15T10:00:00-08:00");
        $retake = $f->service->register($student->id, $retakeSection->id, true);
        self::assertNotSame($firstEnrollment->id->value, $retake->id->value);
        $nextAccountRow = $f->sql->one("sis_student_accounts", [
            "student_id" => $student->id->value,
            "term_id" => $nextTerm->value,
        ]);
        self::assertNotSame($firstAccountRow["id"], $nextAccountRow["id"]);
        $nextAccountId = new \Domain\Finance\ValueObject\AccountId($nextAccountRow["id"]);
        self::assertSame(10000, $f->accounts->get($nextAccountId)->balance()->minor);
        self::assertSame($retake->id->value, $f->accounts->get($nextAccountId)->entries()[0]["operation_id"]);
        self::assertSame($firstEntries, $f->accounts->get($firstAccountId)->entries());
        self::assertSame(
            $firstCredit,
            $f->sql->one("sis_academic_credits", ["source_id" => $firstEnrollment->id->value]),
        );
        self::assertCount(2, $f->sql->rows("sis_student_accounts", ["student_id" => $student->id->value]));
        self::assertCount(2, $f->sql->rows("sis_academic_credits", ["student_id" => $student->id->value]));
        $events = $f->sql->rows("sis_history_events");
        self::assertSame(
            $retake->id->value,
            $f->service->register($student->id, $retakeSection->id, true)->id->value,
        );
        self::assertSame($events, $f->sql->rows("sis_history_events"));
    }

    public function testStudentCannotRegisterSomeoneElse(): void
    {
        $f = new SisFixture();
        $student = $f->student($f->user());
        $section = $f->section();
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $f->service->register($student->id, $section->id);
    }

    public function testModuleRoutesInvokeReadonlyControllersWithTheRealRouter(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $container = new \Qubus\Injector\Psr11\Container(
            \Qubus\Injector\Config\InjectorFactory::create([
                \Qubus\Injector\Injector::STANDARD_ALIASES => [
                    \Psr\Http\Message\ResponseFactoryInterface::class =>
                        \Qubus\Routing\Factories\ResponseFactory::class,
                    \Qubus\Routing\Interfaces\MiddlewareResolver::class =>
                        \Qubus\Routing\Route\InjectorMiddlewareResolver::class,
                ],
            ]),
        );
        $catalog = new \Domain\Registration\Service\RegistrarCatalogService(
            $f->registration,
            $f->access,
            $f->transactions,
            $f->audit,
        );
        $container->share(new \Application\Http\Controller\SisRegistrationController($f->service, $catalog));
        $router = new \Qubus\Routing\Router(new \Qubus\Routing\Route\RouteCollector(), $container);
        $register = require dirname(__DIR__, 2) . "/routes/web/sis-workflows.php";
        $register($router);
        $request = new \Qubus\Http\ServerRequest([], [], "/sis/registrations/", "POST");
        $response = $router->match(
            $request->withParsedBody([
                "student_id" => $student->id->value,
                "section_id" => $section->id->value,
            ]),
        );
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            "N",
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)["status"],
        );
    }

    public function testEndingARestrictionPreservesItsHistory(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $restriction = new Restriction(
            EntityId::generate(),
            $student->personId,
            "NOREG",
            100,
            new DateRange(new DateTimeImmutable("2026-01-01")),
            true,
        );
        new \Infrastructure\Persistence\Repository\SqlRestrictionRepository($f->sql)->add($restriction);
        $cipher = $this->createStub(\Domain\Communications\Service\MessageCipher::class);
        $holds = new \Domain\Student\Service\RestrictionService(
            new \Infrastructure\Persistence\Repository\SqlRestrictionRepository($f->sql),
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            $cipher,
            $f->clock,
        );
        $holds->end(
            $restriction->id,
            new DateTimeImmutable("2026-08-09"),
            $holds->view($restriction->id)["revision"],
        );
        $section = $f->section();
        self::assertSame("A", $f->service->register($student->id, $section->id, true)->status->value);
        self::assertCount(1, $f->sql->rows("sis_restrictions"));
        self::assertSame(
            "2026-08-09",
            $f->sql->one("sis_restrictions", ["id" => $restriction->id->value])["ends_on"],
        );
    }

    public function testWorkflowMigrationCanRollbackAndReapply(): void
    {
        $f = new SisFixture();
        $migration = new CreateSisWorkflowTables("3");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $f->connection,
                    "test_migrations",
                ),
            ]),
        );
        $migration->down();
        $migration->up();
        self::assertCount(0, $f->sql->rows("sis_enrollments"));
        self::assertCount(0, $f->sql->rows("sis_campaigns"));
    }

    public function testCaughtNestedFailureStillRollsBackTheOuterTransaction(): void
    {
        $f = new SisFixture();
        try {
            $f->transactions->run(function () use ($f): void {
                try {
                    $f->transactions->run(function () use ($f): void {
                        $f->section();
                        throw new DomainException("Fictional nested failure.");
                    });
                } catch (DomainException) {
                    // Even when application code catches it, the joined unit cannot commit partial work.
                }
            });
            self::fail("A rollback-only transaction was committed.");
        } catch (\Domain\Shared\Exception\TransactionAbortedException) {
            self::assertCount(0, $f->sql->rows("sis_courses"));
            self::assertCount(0, $f->sql->rows("sis_sections"));
        }
        $f->transactions->run(fn() => $f->section());
        self::assertCount(1, $f->sql->rows("sis_courses"));
    }
}
