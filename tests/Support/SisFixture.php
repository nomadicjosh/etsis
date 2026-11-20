<?php

declare(strict_types=1);

require_once __DIR__ . "/EventStoreFixture.php";

use Domain\Admissions\Service\AdmissionsService;
use Domain\Catalog\Course;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\RegistrationWindow;
use Domain\Registration\Section;
use Domain\Registration\Service\RegistrationService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Student;
use Infrastructure\Persistence\DatabaseUnitOfWork;
use Infrastructure\Persistence\EventSourcedRegistrationBilling;
use Infrastructure\Persistence\PdoTransactionalEventStore;
use Infrastructure\Persistence\Repository\EventSourcedAccountRepository;
use Infrastructure\Persistence\Repository\PdoAdmissionsRepository;
use Infrastructure\Persistence\Repository\SqlRegistrationRepository;
use Infrastructure\Persistence\SqlAuditTrail;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;

final class FixtureAccess implements StudentAccess
{
    public bool $staff = true;
    /** @var list<string> */
    public array $grants = [];

    public function __construct(public EntityId $actor)
    {
    }

    public function authenticatedUserId(): EntityId
    {
        return $this->actor;
    }

    public function requirePermission(string $permission): EntityId
    {
        if (!$this->staff && !in_array($permission, $this->grants, true)) {
            throw new AccessDeniedException("Access denied.");
        }

        return $this->actor;
    }

    public function requireOwnerOrPermission(?EntityId $ownerUserId, string $permission): EntityId
    {
        if (
            !$this->staff &&
            !in_array($permission, $this->grants, true) &&
            $this->actor->value !== $ownerUserId?->value
        ) {
            throw new AccessDeniedException("Access denied.");
        }

        return $this->actor;
    }
}

final class FixtureClock implements Clock
{
    public DateTimeImmutable $time;

    public function __construct()
    {
        $this->time = new DateTimeImmutable("2026-08-10T10:00:00-07:00");
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

final class SisFixture
{
    public Sqlite $connection;
    public SqlRecords $sql;
    public DatabaseUnitOfWork $transactions;
    public FixtureAccess $access;
    public FixtureClock $clock;
    public EntityId $office;
    public SqlAuditTrail $audit;
    public PdoAdmissionsRepository $admissions;
    public SqlRegistrationRepository $registration;
    public EventSourcedAccountRepository $accounts;
    public RegistrationService $service;
    public \Domain\Registration\Service\RegistrationBilling $registrationBilling;
    public \Domain\Registration\Service\CourseDropService $drops;
    public \Infrastructure\Communications\DefuseMessageCipher $dropCipher;
    public \Domain\Registration\Service\DropPolicyService $dropPolicies;
    public EntityId $term;
    public EntityId $program;

    public function publishDropPolicy(
        EntityId $term,
        int $version,
        \Domain\Registration\ValueObject\DropWindows $windows,
    ): array
    {
        $review = $this->dropPolicies->review(
            $term,
            $version,
            $windows,
            'Fictional institutional policy review.',
        );
        return $this->dropPolicies->commit($term, $review['review_token']);
    }

    public function saveAccount(\Domain\Finance\StudentAccount $account): void
    {
        $actor = $this->access->actor;
        foreach ($account->getRecordedEvents() as $event) {
            if (isset($event->payload()['actor_id'])) {
                $actor = new EntityId($event->payload()['actor_id']);
                break;
            }
        }
        $this->accounts->save($account, new \Domain\Shared\EventSourcing\EventContext(
            $actor,
            $this->clock->now(),
            EntityId::generate(),
            reason: 'Fictional attributed account command.'
        ));
    }

    public function finance(): \Domain\Finance\Service\StudentAccountService
    {
        return new \Domain\Finance\Service\StudentAccountService(
            $this->accounts,
            $this->admissions,
            $this->access,
            $this->transactions,
            $this->financialCommands(),
            $this->audit
        );
    }

    public function financialCommands(): \Domain\Finance\Service\FinancialCommandPolicy
    {
        return new \Domain\Finance\Service\FinancialCommandPolicy($this->access, $this->clock);
    }

    public function billingBatches(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Registration\Repository\RegistrationRepository $registration = null,
        ?\Domain\Finance\Service\TermFeeBilling $billing = null,
    ): \Domain\Finance\Service\BillingBatchService {
        $billing ??= $this->termFees();
        return new \Domain\Finance\Service\BillingBatchService(
            $billing,
            new \Infrastructure\Persistence\Repository\SqlBillingBatchRepository($this->sql, $this->dropCipher),
            $this->admissions,
            $registration ?? $this->registration,
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($this->sql),
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->clock,
            $this->dropCipher,
            $this->financialCommands()
        );
    }

    public function termFees(): \Domain\Finance\Service\TermFeeBilling
    {
        $db = $this->connection->queryBuilder();
        return new \Infrastructure\Persistence\EventSourcedRegistrationBilling(
            $this->accounts,
            $this->sql,
            new \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository(
                $this->sql,
                EventStoreFixture::framework($db),
                $this->transactions,
                EventStoreFixture::financialWriter($db)
            ),
            $this->admissions,
            $this->primaryProgramLedger()
        );
    }

    /**
     * @param list<EntityId> $students
     * @return array<string,mixed>
     */
    public function bill(EntityId $term, array $students): array
    {
        $service = $this->billingBatches();
        return $service->confirm($service->review($term, $students, 'Fictional reviewed term billing.')['review_token']);
    }

    private ?EntityId $fixtureBuilding = null;

    public function building(): EntityId
    {
        return $this->fixtureBuilding ??= new \Domain\Scheduling\Service\BuildingService(
            new \Infrastructure\Persistence\Repository\SqlBuildingRepository($this->sql),
            $this->access, $this->transactions, $this->audit
        )->create('FIXTURE', 'Fictional campus building');
    }

    public function room(string $code, int $capacity): EntityId
    {
        return new \Domain\Scheduling\Service\RoomService(
            new \Infrastructure\Persistence\Repository\SqlRoomRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlBookingRepository($this->sql),
            $this->access, $this->transactions, $this->audit, $this->clock,
            new \Infrastructure\Persistence\Repository\SqlBuildingRepository($this->sql)
        )->create($code, 'Fictional room', $this->building(), $capacity);
    }

    public function standingLedger(): \Domain\Student\Service\StudentStandingLedger
    {
        return new \Domain\Student\Service\StudentStandingLedger(
            new \Infrastructure\Persistence\Repository\SqlStudentStandingRepository($this->sql),
            EventStoreFixture::history($this->connection->queryBuilder()),
        );
    }

    public function standingService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Advising\Service\AdvisorProgressAccess $advisors = null,
    ): \Domain\Student\Service\StudentStandingService {
        $advisors ??= new class implements \Domain\Advising\Service\AdvisorProgressAccess {
            public function requireAdvisor(EntityId $student, EntityId $pursuit): EntityId
            {
                throw new AccessDeniedException();
            }
        };
        return new \Domain\Student\Service\StudentStandingService(
            new \Infrastructure\Persistence\Repository\SqlStudentStandingRepository($this->sql),
            $this->admissions,
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            $this->programStatusLedger(),
            $this->primaryProgramLedger(),
            EventStoreFixture::history($this->connection->queryBuilder()),
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->clock,
            new \Domain\Shared\Service\ReviewProof($this->dropCipher, $this->clock),
            $advisors,
            $this->standingLedger(),
        );
    }

    public function __construct(
        ?Sqlite $connection = null,
        bool $versionedGrades = true,
        bool $initialize = true,
        ?EntityId $actor = null,
    ) {
        $this->connection = $connection ?? new Sqlite(["driver" => "pdo_sqlite", "dsn" => "sqlite::memory:"]);
        if ($initialize) {
            $this->connection->pdo->exec(
                "CREATE TABLE users (user_id VARCHAR(36) PRIMARY KEY, email VARCHAR(191), " .
                    "role VARCHAR(80), first_name VARCHAR(191), last_name VARCHAR(191))",
            );
            foreach (glob(dirname(__DIR__, 2) . "/database/migrations/*.php") as $path) {
                $name = basename($path);
                if (!$versionedGrades && str_contains($name, "CreateSisVersionedGrades")) {
                    continue;
                }
                if (
                    !str_contains($name, "CreateEventStoreTable") &&
                    !str_contains($name, "CreateSis") &&
                    !str_contains($name, "AddSis") &&
                    !str_contains($name, "CreateCampus")
                ) {
                    continue;
                }
                require_once $path;
                $class = preg_replace('/^\d+_|\.php$/', "", $name);
                $migration = new $class("1");
                $migration->setObjectMap(
                    new ArrayObject([
                        "phpmig.adapter" => new DbalMigrationAdapter($this->connection, "test_migrations"),
                    ]),
                );
                $migration->up();
            }
        }
        $db = $this->connection->queryBuilder();
        $this->sql = new SqlRecords($db);
        $this->transactions = new DatabaseUnitOfWork($db);
        $this->access = new FixtureAccess($actor ?? $this->user());
        if ($initialize) {
            // Earlier workflow fixtures deliberately exercise institutions without mandatory offer acceptance.
            $this->sql->update('sis_admission_policy', ['require_acceptance' => 0],
                ['id' => \Domain\Admissions\Repository\AdmissionPolicyRepository::ID]);
        }
        $this->clock = new FixtureClock();
        if ($initialize) {
            $this->office = EntityId::generate();
            new \Infrastructure\Persistence\Repository\SqlOfficeRepository($this->sql)->saveOffice([
                'id' => $this->office->value,
                'code' => 'REG',
                'name' => 'Fictional Registrar',
                'active' => 1,
                'revision' => 0,
            ], true);
        } else {
            $office = $this->sql->rows('sis_offices', ['code' => 'REG'], 1)[0]
                ?? throw new RuntimeException('The shared fictional registrar office is missing.');
            $this->office = new EntityId($office['id']);
        }
        $this->audit = new SqlAuditTrail($this->sql);
        $this->admissions = new PdoAdmissionsRepository($db);
        $this->registration = new SqlRegistrationRepository(
            $this->sql,
            $this->clock,
            $this->transactions,
            $this->restrictionLedger()
        );
        $this->accounts = new EventSourcedAccountRepository(
            EventStoreFixture::framework($db),
            $db,
            EventStoreFixture::financialWriter($db),
        );
        $rules = new \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository(
            $this->sql,
            EventStoreFixture::framework($db),
            $this->transactions,
            \EventStoreFixture::financialWriter($db),
        );
        $billing = new EventSourcedRegistrationBilling(
            $this->accounts,
            $this->sql,
            $rules,
            $this->admissions,
            $this->primaryProgramLedger(),
        );
        $dropPolicies = new \Infrastructure\Persistence\Repository\SqlDropPolicyRepository(
            $this->sql,
            $this->gradeCatalog(),
        );
        $this->dropPolicies = new \Domain\Registration\Service\DropPolicyService(
            $dropPolicies,
            $this->access,
            $this->transactions,
            $this->audit,
            $this->academicCredits(),
            EventStoreFixture::history($db),
            $this->dropCipher,
            $this->clock,
        );
        $this->registrationBilling = $billing;
        $this->drops = $this->dropService($billing);
        $this->service = new RegistrationService(
            $this->registration,
            $this->admissions,
            $this->access,
            $this->transactions,
            $billing,
            $this->audit,
            $this->clock,
            $this->drops,
            $this->academicCredits(),
            $this->enrollmentLedger(),
            $this->activityLedger(),
            $this->primaryProgramLedger(),
            $this->workPursuits(),
            new \Domain\Registration\Service\PrerequisiteService($this->inputVerifier(), $this->workPursuits()),
            $this->schedules(),
            $this->corequisites(),
            $this->windowPolicies(),
        );
        if (!$initialize) {
            $this->term = new EntityId($this->sql->rows("sis_terms", [], 1)[0]["id"]);
            $this->program = new EntityId($this->sql->rows("sis_programs", [], 1)[0]["id"]);
            return;
        }
        $this->term = EntityId::generate();
        $this->program = EntityId::generate();
        $this->sql->insert("sis_terms", [
            "id" => $this->term->value,
            "code" => "2026FA",
            "starts_on" => "2026-08-01",
            "ends_on" => "2026-12-20",
            "add_drop_ends_on" => "2026-08-15",
        ]);
        $this->sql->insert("sis_programs", [
            "id" => $this->program->value,
            "code" => "BSCS",
            "title" => "Computer Science",
        ]);
        $this->transactions->run(
            fn() => $this->registration->configureWindow(
                $this->term,
                new RegistrationWindow(
                    new DateRange(new DateTimeImmutable("2026-08-01"), new DateTimeImmutable("2026-08-31")),
                    5,
                ),
            ),
        );
    }

    /** Older already-recorded grade fixture; deliberately has no invented publication history. */
    public function legacyGrade(\Domain\Gradebook\GradeDefinition $grade): void
    {
        $this->sql->insert("sis_grades", [
            "id" => $grade->id->value,
            "code" => $grade->code,
            "points_milli" => $grade->pointsMilli,
            "passes" => (int) $grade->passes,
            "counts_gpa" => (int) $grade->countsGpa,
        ]);
    }
    public function bookingSections(): \Domain\Scheduling\Repository\BookingSectionRepository
    {
        return new \Infrastructure\Persistence\Repository\SqlBookingSectionRepository(
            $this->sql,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->sql),
            new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
                $this->sql, $this->transactions, $this->clock,
                new \Infrastructure\EventSourcing\ProtectedEventCodec(
                    EventStoreFixture::config($this->connection->queryBuilder())
                ),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry()
            )
        );
    }

    public function gradingScales(): \Domain\Gradebook\Service\GradingScaleService
    {
        if (!isset($this->dropCipher)) {
            $c = \Qubus\Config\Collection::factory([]);
            $c->setConfigKey("app", [
                "crypto_key" => \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString(),
            ]);
            $this->dropCipher = new \Infrastructure\Communications\DefuseMessageCipher($c);
        }
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $this->sql,
            $this->transactions,
            $this->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($this->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        return new \Domain\Gradebook\Service\GradingScaleService(
            new \Infrastructure\Persistence\Repository\SqlGradingScaleRepository($this->sql),
            $history,
            $this->access,
            $this->transactions,
            $this->audit,
            $this->dropCipher,
            $this->clock,
        );
    }
    public function financialEvents(): array
    {
        return $this->sql->fetch(
            "SELECT e.* FROM sis_history_events e JOIN sis_history_streams s ON s.id = e.stream_id " .
                "WHERE s.stream_type IN ('student_account','billing_rule','journal') ORDER BY e.id",
        );
    }
    public function windowPolicies(): \Domain\Registration\Service\RegistrationWindowService
    {
        return new \Domain\Registration\Service\RegistrationWindowService(
            new \Infrastructure\Persistence\Repository\SqlRegistrationWindowRepository($this->sql),
            EventStoreFixture::history($this->connection->queryBuilder()), $this->access, $this->transactions,
            $this->audit, $this->clock, $this->dropCipher,
        );
    }
    public function corequisites(): \Domain\Registration\Service\CoRequisiteService
    {
        return new \Domain\Registration\Service\CoRequisiteService(
            new \Infrastructure\Persistence\Repository\SqlCoRequisiteRepository($this->sql),
            $this->inputVerifier(), $this->workPursuits(), $this->enrollmentLedger(), $this->registration,
        );
    }
    public function enrollmentLedger(): \Domain\Registration\Service\EnrollmentLedger
    {
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $this->sql,
            $this->transactions,
            $this->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($this->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        return new \Domain\Registration\Service\EnrollmentLedger(
            new \Infrastructure\Persistence\Repository\SqlEnrollmentHistoryRepository($this->sql),
            $history,
            $this->access,
            $this->clock,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->sql),
        );
    }
    public function gradebook(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Gradebook\Service\GradebookService {
        return new \Domain\Gradebook\Service\GradebookService(
            new \Infrastructure\Persistence\Repository\SqlGradebookRepository($this->sql),
            $this->registration,
            $this->admissions,
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->academicCredits(),
        );
    }

    public function academicCredits(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\AcademicRecord\Service\InstitutionalCreditLedger {
        $grades = $this->gradeCatalog();
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $this->sql,
            $this->transactions,
            $this->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($this->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        return new \Domain\AcademicRecord\Service\InstitutionalCreditLedger(
            new \Infrastructure\Persistence\Repository\SqlAcademicCreditRepository($this->sql),
            $history,
            $grades,
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->sql),
            $this->workPursuitLedger(),
            new \Infrastructure\Persistence\Repository\SqlGradeChangeRepository($this->sql, $this->dropCipher),
            new \Domain\Gradebook\Service\CourseworkGradeService(
                new \Infrastructure\Persistence\Repository\SqlGradebookRepository($this->sql),
            ),
        );
    }
    public function workPursuitLedger(): \Domain\AcademicRecord\Service\WorkPursuitLedger
    {
        return new \Domain\AcademicRecord\Service\WorkPursuitLedger(
            new \Infrastructure\Persistence\Repository\SqlWorkPursuitRepository($this->sql),
            new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
                $this->sql, $this->transactions, $this->clock,
                new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($this->connection->queryBuilder())),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
            ), $this->transactions, $this->access, $this->clock,
        );
    }
    public function workPursuits(?\Domain\Audit\Repository\AuditTrail $audit = null): \Domain\AcademicRecord\Service\WorkPursuitService
    {
        return new \Domain\AcademicRecord\Service\WorkPursuitService(
            $this->workPursuitLedger(), $this->inputVerifier(),
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            $this->admissions, $this->primaryProgramLedger(), $this->programStatusLedger(),
            $this->access, $this->transactions, $this->dropCipher, $this->clock, $audit ?? $this->audit,
        );
    }
    /** Explicit registrar attribution for fictional older current records; no grade or award history is invented. */
    public function attributeWork(EntityId $student, EntityId $credit, EntityId $assignment): void
    {
        $service = $this->workPursuits();
        $review = $service->review($student, $credit, $assignment, 'attribute', 'Fictional source evidence identifies this pursuit.');
        $service->commit($student, $credit, $review['review_token']);
    }
    public function schedules(): \Domain\Registration\Service\RegistrationScheduleService
    {
        $history = EventStoreFixture::history($this->connection->queryBuilder());
        $versions = new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->sql);
        $sections = new \Infrastructure\Persistence\Repository\SqlBookingSectionRepository($this->sql, $versions, $history);
        $plans = new \Infrastructure\Persistence\Repository\SqlSectionMeetingPlanRepository($this->sql, $sections);
        return new \Domain\Registration\Service\RegistrationScheduleService(
            new \Infrastructure\Persistence\Repository\SqlRegistrationScheduleRepository($this->sql),
            new \Domain\Registration\Service\PublishedSectionMeetingPlanReader($plans, $history));
    }

    public function prerequisites(): \Domain\Registration\Service\PrerequisiteService
    {
        return new \Domain\Registration\Service\PrerequisiteService($this->inputVerifier(), $this->workPursuits());
    }
    /** Authorized fixture command boundary for the single verified prerequisite implementation. */
    public function prerequisitesMet(EntityId $student, EntityId $course, ?array $required = null): bool
    {
        return $this->transactions->run(function () use ($student, $course, $required): bool {
            $this->registration->lockStudent($student);
            $assignment = $this->workPursuits()->activePursuit($student);
            return new \Domain\Registration\Service\PrerequisiteService($this->inputVerifier(), $this->workPursuits())
                ->evaluate($student, $assignment, $required ?? $this->registration->requiredCourses($course))->isMet();
        });
    }
    public function inputVerifier(): \Domain\DegreeAudit\Service\AcademicInputVerifier
    {
        return new \Domain\DegreeAudit\Service\AcademicInputVerifier(
            new \Infrastructure\Persistence\Repository\SqlAcademicInputRepository($this->sql),
            $this->academicCredits(), $this->transferCredits(), $this->enrollmentLedger(),
        );
    }
    public function academicInputs(
        \Domain\DegreeAudit\Service\StudentRequirementsService $requirements,
    ): \Domain\DegreeAudit\Service\AcademicInputService {
        return new \Domain\DegreeAudit\Service\AcademicInputService(
            $this->inputVerifier(),
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            $requirements, $this->transactions, $this->workPursuits(),
        );
    }
    public function academicDates(?\Domain\Audit\Repository\AuditTrail $audit = null): \Domain\AcademicRecord\Service\AcademicDetailsService
    {
        return new \Domain\AcademicRecord\Service\AcademicDetailsService(
            $this->academicCredits(),
            new \Infrastructure\Persistence\Repository\SqlAcademicCreditRepository($this->sql),
            new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
                $this->sql, $this->transactions, $this->clock,
                new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($this->connection->queryBuilder())),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
            ),
            $this->transactions, $this->access, $this->dropCipher, $this->clock, $audit ?? $this->audit,
        );
    }
    public function academicCourses(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\AcademicRecord\Service\AcademicCourseService {
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $this->sql,
            $this->transactions,
            $this->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($this->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        return new \Domain\AcademicRecord\Service\AcademicCourseService(
            $this->academicCredits(),
            new \Infrastructure\Persistence\Repository\SqlAcademicCreditRepository($this->sql),
            new \Domain\Catalog\Service\PublishedCourseReader($history),
            $this->gradeCatalog(),
            $history,
            $this->transactions,
            $this->access,
            $this->dropCipher,
            $this->clock,
            $audit ?? $this->audit,
            new \Infrastructure\Persistence\Repository\SqlCourseSearchRepository($this->sql),
        );
    }

    public function activityLedger(): \Domain\Student\Service\StudentActivityLedger
    {
        return new \Domain\Student\Service\StudentActivityLedger(
            new \Infrastructure\Persistence\Repository\SqlStudentActivityRepository($this->sql),
            new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
                $this->sql,
                $this->transactions,
                $this->clock,
                new \Infrastructure\EventSourcing\ProtectedEventCodec(
                    EventStoreFixture::config($this->connection->queryBuilder()),
                ),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
            ),
            $this->access,
            $this->clock,
        );
    }
    public function programAssignmentService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Student\Service\StudentProgramAssignmentService {
        return new \Domain\Student\Service\StudentProgramAssignmentService(
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            $this->admissions,
            $this->programStatusLedger(),
            $this->primaryProgramLedger(),
            $this->activityLedger(),
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql),
            $this->programEntryContext(),
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock,
        );
    }
    public function assignProgram(
        \Domain\Shared\ValueObject\EntityId $student,
        \Domain\Shared\ValueObject\EntityId $program,
        \Domain\Shared\ValueObject\EntityId $term,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Shared\ValueObject\EntityId {
        $s = $this->admissions->student($student);
        $revision = hash(
            "sha256",
            json_encode(
                [
                    $s->id->value,
                    $s->personId->value,
                    $s->programId->value,
                    $s->entryTermId->value,
                    $s->active,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        $service = $this->programAssignmentService($audit);
        $review = $service->review(
            $student,
            $program,
            $term,
            $revision,
            "Fictional program assignment reason.",
        );
        return new \Domain\Shared\ValueObject\EntityId(
            $service->commit($student, $review["review_token"])["assignment_id"],
        );
    }
    public function primaryProgramLedger(): \Domain\Student\Service\StudentPrimaryProgramLedger
    {
        return new \Domain\Student\Service\StudentPrimaryProgramLedger(
            new \Infrastructure\Persistence\Repository\SqlStudentPrimaryProgramRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            $this->programStatusLedger(),
            new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
                $this->sql,
                $this->transactions,
                $this->clock,
                new \Infrastructure\EventSourcing\ProtectedEventCodec(
                    EventStoreFixture::config($this->connection->queryBuilder()),
                ),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
            ),
            $this->access,
            $this->clock,
        );
    }
    public function primaryProgramService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Student\Service\StudentPrimaryProgramService {
        return new \Domain\Student\Service\StudentPrimaryProgramService(
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            $this->admissions,
            $this->primaryProgramLedger(),
            $this->programStatusLedger(),
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock,
        );
    }
    public function programStatusLedger(): \Domain\Student\Service\StudentProgramStatusLedger
    {
        return new \Domain\Student\Service\StudentProgramStatusLedger(
            new \Infrastructure\Persistence\Repository\SqlStudentProgramStatusRepository($this->sql),
            new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
                $this->sql,
                $this->transactions,
                $this->clock,
                new \Infrastructure\EventSourcing\ProtectedEventCodec(
                    EventStoreFixture::config($this->connection->queryBuilder()),
                ),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
            ),
            $this->access,
            $this->clock,
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql),
        );
    }
    public function specializations(
        \Domain\Shared\EventSourcing\HistoricalEventStore $history,
        ?\Domain\Advising\Service\AdvisorProgressAccess $advisors = null,
    ): \Domain\Student\Service\StudentSpecializationService {
        return new \Domain\Student\Service\StudentSpecializationService(
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            $this->admissions,
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql),
            $this->programStatusLedger(),
            new \Domain\Catalog\Service\PublishedProgramReader(
                $history, new \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository($this->sql),
            ),
            new \Infrastructure\Persistence\Repository\SqlStudentSpecializationRepository($this->sql),
            $history, $this->access, $this->transactions, $this->audit, $this->dropCipher, $this->clock,
            new \Infrastructure\Persistence\Repository\SqlRequirementsRepository($this->sql),
            new \Domain\DegreeAudit\Service\PublishedRequirementsReader($history),
            $advisors,
        );
    }
    public function programStatusService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Student\Service\StudentProgramStatusService {
        return new \Domain\Student\Service\StudentProgramStatusService(
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            $this->admissions,
            $this->programStatusLedger(),
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock,
            $this->primaryProgramLedger(),
        );
    }
    public function activityService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Student\Service\StudentActivityService {
        return new \Domain\Student\Service\StudentActivityService(
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            $this->admissions,
            $this->activityLedger(),
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock,
        );
    }
    public function transferCredits(): \Domain\Transfer\Service\TransferCreditLedger
    {
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $this->sql,
            $this->transactions,
            $this->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($this->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        return new \Domain\Transfer\Service\TransferCreditLedger(
            new \Infrastructure\Persistence\Repository\SqlTransferCreditRepository($this->sql),
            $history,
            $this->gradeCatalog(),
            $this->access,
            $this->transactions,
            $this->clock,
            new \Domain\Catalog\Service\PublishedCourseReader($history),
            $this->workPursuitLedger(),
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            $this->primaryProgramLedger(), $this->admissions, $this->programStatusLedger(),
        );
    }
    public function coursePublications(): \Domain\Catalog\Service\CoursePublicationService
    {
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $this->sql,
            $this->transactions,
            $this->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($this->connection->queryBuilder())),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        return new \Domain\Catalog\Service\CoursePublicationService(
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository($this->sql),
            $history,
            $this->access,
            $this->transactions,
            $this->audit,
            $this->dropCipher,
            $this->clock,
            new \Infrastructure\Persistence\Repository\SqlCourseTextProjection($this->sql),
            new \Infrastructure\Persistence\Repository\SqlSubjectRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlClassificationRepository($this->sql),
        );
    }
    /** Deliberate fictional publication setup, never a production default selection. */
    public function publishCourse(EntityId $course): EntityId
    {
        $service = $this->coursePublications();
        $review = $service->review($course, "Fictional accepted course definition.");
        return new EntityId($service->publish($course, $review["review_token"])["operation_id"]);
    }
    public function gradeCatalog(): \Domain\Gradebook\Service\AcademicGradeCatalog
    {
        return new \Domain\Gradebook\Service\AcademicGradeCatalog(
            new \Infrastructure\Persistence\Repository\SqlAcademicGradeRepository($this->sql),
            $this->gradingScales(),
        );
    }
    /** @param list<array<string,mixed>> $grades
     * @return array<string,mixed>
     */
    public function gradeScale(array $grades, ?EntityId $program = null): array
    {
        $s = $this->gradingScales();
        $r = $s->review(
            $program,
            new \Domain\Gradebook\ValueObject\GradingScaleDefinition(["grades" => $grades]),
            "Fictional approved grade setup",
        );
        return $s->commit($program, $r["review_token"]);
    }
    public function dropService(
        ?\Domain\Registration\Service\RegistrationBilling $billing = null,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Registration\Service\CourseDropService {
        if (!isset($this->dropCipher)) {
            $config = \Qubus\Config\Collection::factory([]);
            $config->setConfigKey("app", [
                "crypto_key" => \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString(),
            ]);
            $this->dropCipher = new \Infrastructure\Communications\DefuseMessageCipher($config);
        }
        $billing ??= new EventSourcedRegistrationBilling(
            $this->accounts,
            $this->sql,
            new \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository(
                $this->sql,
                EventStoreFixture::framework($this->connection->queryBuilder()),
                $this->transactions,
                \EventStoreFixture::financialWriter($this->connection->queryBuilder()),
            ),
            $this->admissions,
            $this->primaryProgramLedger(),
        );
        return new \Domain\Registration\Service\CourseDropService(
            new \Infrastructure\Persistence\Repository\SqlCourseDropRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlDropPolicyRepository(
                $this->sql,
                $this->gradeCatalog(),
            ),
            $this->dropPolicies,
            $this->registration,
            $this->admissions,
            $billing,
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->clock,
            $this->dropCipher,
            $this->academicCredits(),
            $this->enrollmentLedger(),
            new \Infrastructure\Persistence\Repository\SqlRegistrationBatchRepository($this->sql),
            new \Domain\Shared\Service\ReviewProof($this->dropCipher, $this->clock),
            $this->corequisites(),
        );
    }

    public function calendar(): \Domain\AcademicCalendar\Service\AcademicCalendarService
    {
        return new \Domain\AcademicCalendar\Service\AcademicCalendarService(
            new \Infrastructure\Persistence\Repository\SqlAcademicCalendarRepository($this->sql),
            $this->access,
            $this->transactions,
            $this->audit,
            $this->dropCipher,
            $this->clock,
        );
    }

    public function catalogEnrollment(): \Domain\Catalog\Service\CatalogEnrollment
    {
        return new \Domain\Catalog\Service\InitialCatalogEnrollment(
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql),
            $this->audit,
            $this->clock,
        );
    }
    public function studentCatalogs(?\Domain\Audit\Repository\AuditTrail $audit = null): \Domain\Student\Service\StudentCatalogService
    {
        return new \Domain\Student\Service\StudentCatalogService(
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            $this->access, $this->transactions, $audit ?? $this->audit, $this->dropCipher,
            $this->programStatusLedger(),
            new \Domain\Catalog\Service\PublishedProgramReader(
                EventStoreFixture::history($this->connection->queryBuilder()),
                new \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository($this->sql),
            ),
            new \Domain\Shared\Service\ReviewProof($this->dropCipher, $this->clock),
            $this->corequisites(),
        );
    }

    public function user(): EntityId
    {
        $id = EntityId::generate();
        $this->sql->insert("users", ["user_id" => $id->value]);

        return $id;
    }

    public function applicationRevisionService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Admissions\Service\ApplicationRevisionService {
        return new \Domain\Admissions\Service\ApplicationRevisionService(
            $this->admissions,
            new \Infrastructure\Persistence\Repository\SqlApplicationFormRepository(
                $this->sql,
            ),
            $this->applicationRevisions(),
            $this->admissionPolicy(),
            $this->withdrawalRepository(),
            new \Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository(
                $this->sql,
            ),
            $this->decisionLedger(),
            $this->applicationContext(),
            $this->access,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository(
                $this->sql,
            ),
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock,
        );
    }
    public function applicationRevisions(): \Domain\Admissions\Repository\ApplicationRevisionRepository
    {
        return new \Infrastructure\Persistence\Repository\SqlApplicationRevisionRepository(
            $this->sql,
            $this->dropCipher,
        );
    }
    public function applicationContext(): \Domain\Admissions\Service\ApplicationContext
    {
        return new \Domain\Admissions\Service\ApplicationContext(
            new \Infrastructure\Persistence\Repository\SqlApplicationFormRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            $this->dropCipher,
            $this->applicationRevisions(),
        );
    }
    public function submissionService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Admissions\Repository\AdmissionsRepository $repository = null,
    ): \Domain\Admissions\Service\ApplicationSubmissionService {
        return new \Domain\Admissions\Service\ApplicationSubmissionService(
            $repository ?? $this->admissions,
            new \Infrastructure\Persistence\Repository\SqlApplicationFormRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql),
            $this->applicationContext(),
            $this->decisionLedger(),
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
        );
    }
    /** Current-state fixture for an application predating tracked submission. */
    public function legacyApplication(
        EntityId $person,
        EntityId $program,
        EntityId $term,
    ): \Domain\Admissions\AdmissionApplication {
        $a = new \Domain\Admissions\AdmissionApplication(EntityId::generate(), $person, $program, $term);
        $this->admissions->addApplication($a);
        return $a;
    }
    public function decisionLedger(): \Domain\Admissions\Service\AdmissionDecisionLedger
    {
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $this->sql,
            $this->transactions,
            $this->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($this->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        return new \Domain\Admissions\Service\AdmissionDecisionLedger(
            $history,
            $this->access,
            $this->clock,
            $this->applicationContext(),
            new \Infrastructure\Persistence\Repository\SqlSubmissionProjection($this->sql),
        );
    }
    public function admissionEvidenceService(): \Domain\Admissions\Service\AdmissionEvidenceService
    {
        return new \Domain\Admissions\Service\AdmissionEvidenceService(
            new \Infrastructure\Persistence\Repository\SqlAdmissionDocumentRepository($this->sql),
            new \Domain\Admissions\Service\AdmissionDocumentContent(
                $this->dropCipher,
                new \Infrastructure\Admissions\PrivateAdmissionDocumentStorage($this->dropCipher)
            ),
            $this->clock
        );
    }
    public function reviewStateService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Admissions\Repository\AdmissionsRepository $repository = null,
        ?\Domain\Access\Repository\AccessRepository $permissions = null,
    ): \Domain\Admissions\Service\AdmissionReviewStateService {
        return new \Domain\Admissions\Service\AdmissionReviewStateService(
            $repository ?? $this->admissions,
            $this->applicationContext(),
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql),
            $this->decisionLedger(),
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock,
            $permissions ?? new \Infrastructure\Persistence\Repository\SqlAccessRepository($this->sql),
            $this->withdrawalRepository()
        );
    }
    public function readinessService(): \Domain\Admissions\Service\AdmissionReadinessService
    {
        return new \Domain\Admissions\Service\AdmissionReadinessService(
            new \Infrastructure\Persistence\Repository\SqlAdmissionCaseworkRepository(
                $this->sql,
                $this->dropCipher,
            ),
            $this->admissionPolicy(),
        );
    }
    public function correctionService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Admissions\Repository\AdmissionsRepository $repository = null,
        ?\Domain\Admissions\Service\AdmissionEvidenceService $evidence = null,
    ): \Domain\Admissions\Service\AdmissionCorrectionService {
        return new \Domain\Admissions\Service\AdmissionCorrectionService(
            $repository ?? $this->admissions,
            $this->applicationContext(),
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql),
            $this->decisionLedger(),
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock,
            $evidence ?? $this->admissionEvidenceService(),
            $this->readinessService(),
        );
    }
    public function decisionService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Admissions\Repository\AdmissionsRepository $repository = null,
        ?\Domain\Admissions\Service\AdmissionEvidenceService $evidence = null,
    ): \Domain\Admissions\Service\AdmissionDecisionService {
        return new \Domain\Admissions\Service\AdmissionDecisionService(
            $repository ?? $this->admissions,
            $this->applicationContext(),
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql),
            $this->decisionLedger(),
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock,
            $evidence ?? $this->admissionEvidenceService(),
            $this->applicationRevisions(),
            $this->readinessService(),
        );
    }
    /** Explicit current-state decision fixture predating tracked acceptance/rejection. */
    public function legacyAdmissionDecision(EntityId $id, bool $accept): void
    {
        $a = $this->admissions->application($id);
        $this->admissions->saveDecision($a->decide($accept), $a->version);
    }
    public function admissionsService(): AdmissionsService
    {
        return new AdmissionsService(
            $this->admissions,
            $this->access,
            $this->transactions,
            $this->audit,
            new \Infrastructure\Identity\RandomInstitutionalIdGenerator(),
            $this->decisionLedger(),
            $this->submissionService(),
            $this->profileLedger(),
            new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository($this->sql),
        );
    }

    public function employmentLedger(): \Domain\HumanResources\Service\EmploymentLedger
    {
        return new \Domain\HumanResources\Service\EmploymentLedger(new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $this->sql,
            $this->transactions,
            $this->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($this->connection->queryBuilder())),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry()
        ), $this->clock);
    }
    public function restrictionLedger(): \Domain\Student\Service\RestrictionLedger
    {
        return new \Domain\Student\Service\RestrictionLedger(
            new \Infrastructure\Persistence\Repository\SqlRestrictionRepository($this->sql),
            new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
                $this->sql,
                $this->transactions,
                $this->clock,
                new \Infrastructure\EventSourcing\ProtectedEventCodec(
                    EventStoreFixture::config($this->connection->queryBuilder())
                ),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry()
            ),
            $this->clock
        );
    }
    public function officeScope(): \Domain\Office\Service\OfficeScope
    {
        return new \Domain\Office\Service\OfficeScope(
            new \Infrastructure\Persistence\Repository\SqlOfficeRepository($this->sql), $this->access, $this->clock
        );
    }
    public function imports(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Import\Repository\CatalogImportRepository $repository = null,
    ): \Domain\Import\Service\MappedImportService {
        return new \Domain\Import\Service\MappedImportService(
            new \Infrastructure\Import\LeagueCourseCsvMapper(),
            $repository ?? new \Infrastructure\Persistence\Repository\SqlCatalogImportRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository($this->sql),
            $this->access, $this->transactions, $audit ?? $this->audit, $this->dropCipher, $this->clock,
        );
    }
    public function restrictions(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Student\Service\RestrictionService {
        return new \Domain\Student\Service\RestrictionService(
            new \Infrastructure\Persistence\Repository\SqlRestrictionRepository($this->sql),
            $this->admissions, $this->access, $this->transactions, $audit ?? $this->audit,
            $this->dropCipher, $this->clock, $this->restrictionLedger(), $this->officeScope(),
            new \Infrastructure\Persistence\Repository\SqlOfficeRepository($this->sql),
        );
    }
    public function positions(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\HumanResources\Repository\PositionRepository $rows = null,
        ?\Domain\Shared\EventSourcing\HistoricalEventStore $history = null
    ): \Domain\HumanResources\Service\PositionService {
        $repository = $rows ?? new \Infrastructure\Persistence\Repository\SqlPositionRepository($this->sql, $this->dropCipher);
        return new \Domain\HumanResources\Service\PositionService(
            $this->timesheetService(), $repository,
            new \Infrastructure\Persistence\Repository\SqlHrLibraryRepository($this->sql),
            $this->access, $this->clock, new \Infrastructure\Persistence\SqlActorDirectory($this->sql),
            $this->employmentDatesLedger(), $this->positionLedger($audit, $repository, $history)
        );
    }
    public function positionLedger(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\HumanResources\Repository\PositionRepository $rows = null,
        ?\Domain\Shared\EventSourcing\HistoricalEventStore $history = null
    ): \Domain\HumanResources\Service\PositionLedger {
        return new \Domain\HumanResources\Service\PositionLedger(
            $rows ?? new \Infrastructure\Persistence\Repository\SqlPositionRepository($this->sql, $this->dropCipher),
            $history ?? new \Infrastructure\EventSourcing\SqlHistoricalEventStore($this->sql, $this->transactions,
                $this->clock, new \Infrastructure\EventSourcing\ProtectedEventCodec(
                    EventStoreFixture::config($this->connection->queryBuilder())),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry()),
            $audit ?? $this->audit, $this->clock
        );
    }
    public function employmentDatesLedger(): \Domain\HumanResources\Service\EmploymentDatesLedger
    {
        return new \Domain\HumanResources\Service\EmploymentDatesLedger(
            new \Infrastructure\Persistence\Repository\SqlEmploymentDatesRepository($this->sql, $this->dropCipher),
            new \Infrastructure\EventSourcing\SqlHistoricalEventStore($this->sql, $this->transactions, $this->clock,
                new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($this->connection->queryBuilder())),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry()),
            $this->clock
        );
    }
    public function employmentDates(?\Domain\Audit\Repository\AuditTrail $audit = null): \Domain\HumanResources\Service\EmploymentDatesService
    {
        return new \Domain\HumanResources\Service\EmploymentDatesService(
            $this->timesheetService(), $this->employmentDatesLedger(), $this->positions(),
            $this->access, $audit ?? $this->audit, $this->clock
        );
    }
    public function hrLibrary(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\HumanResources\Repository\HrLibraryRepository $rows = null,
        ?\Domain\Access\Repository\AccessRepository $roles = null
    ): \Domain\HumanResources\Service\HrLibraryService {
        return new \Domain\HumanResources\Service\HrLibraryService(
            $rows ?? new \Infrastructure\Persistence\Repository\SqlHrLibraryRepository($this->sql),
            $this->access,
            $roles ?? new \Infrastructure\Persistence\Repository\SqlAccessRepository($this->sql),
            $this->transactions,
            $audit ?? $this->audit
        );
    }
    public function timesheetService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\HumanResources\Repository\TimeRepository $repository = null,
        ?\Domain\Audit\Repository\ActorDirectory $actors = null
    ): \Domain\HumanResources\Service\TimesheetService {
        return new \Domain\HumanResources\Service\TimesheetService(
            $repository ?? new \Infrastructure\Persistence\Repository\SqlTimeRepository($this->sql, $this->dropCipher),
            $this->admissions,
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->employmentLedger(),
            new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($this->sql),
            $this->profileLedger(),
            $this->clock,
            $actors ?? new \Infrastructure\Persistence\SqlActorDirectory($this->sql),
            $this->employmentDatesLedger(),
            $this->positionLedger()
        );
    }
    public function profileLedger(): \Domain\Person\Service\ProfileLedger
    {
        return new \Domain\Person\Service\ProfileLedger(new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $this->sql, $this->transactions, $this->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($this->connection->queryBuilder())),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        ), $this->clock);
    }
    public function registrationService(): \Domain\Person\Service\PersonRegistrationService
    {
        $columns = array_column($this->connection->pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (['username', 'token', 'middle_name', 'password', 'created_on'] as $column) {
            if (!in_array($column, $columns, true)) { $this->connection->pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ' VARCHAR(191)'); }
        }
        $db = $this->connection->queryBuilder();
        $links = new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository($this->sql, $this->admissions,
            new \Infrastructure\Identity\RandomInstitutionalIdGenerator());
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore($this->sql, $this->transactions, $this->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($db)),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry());
        $users = new \Infrastructure\Persistence\Repository\EventSourcedUserRepository(EventStoreFixture::framework($db),
            new \Infrastructure\Projection\ExpressiveDbalUserProjection($db,
                new \Infrastructure\Persistence\Repository\SqlAccessRepository($this->sql), $links), $db, $history);
        return new \Domain\Person\Service\PersonRegistrationService($this->admissions, $links, $users, $this->profileLedger(),
            new \Infrastructure\Persistence\Repository\SqlAccountSetupRepository($this->sql), $this->access,
            $this->transactions, $this->audit, $this->clock, $this->dropCipher, 'https://fictional.example.invalid');
    }
    public function programEntryContext(
        ?\Domain\Catalog\Service\CatalogEnrollment $initializer = null,
    ): \Domain\Student\Service\ProgramEntryContext {
        return new \Domain\Student\Service\ProgramEntryContext(
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql),
            $initializer ?? $this->catalogEnrollment(),
            new \Domain\Catalog\Service\PublishedProgramReader(
                new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
                    $this->sql, $this->transactions, $this->clock,
                    new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($this->connection->queryBuilder())),
                    new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
                ),
                new \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository($this->sql),
            ),
        );
    }
    public function entryTerms(): \Domain\Admissions\Service\AdmissionEntryTerms
    {
        return new \Domain\Admissions\Service\AdmissionEntryTerms(
            new \Infrastructure\Persistence\Repository\SqlAcademicCalendarRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql)
        );
    }
    public function deferralService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null
    ): \Domain\Admissions\Service\AdmissionDeferralService {
        return new \Domain\Admissions\Service\AdmissionDeferralService(
            $this->admissions,
            $this->decisionLedger(),
            $this->applicationContext(),
            $this->entryTerms(),
            $this->withdrawalRepository(),
            new \Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository($this->sql),
            $this->access,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($this->sql),
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock
        );
    }
    public function withdrawalRepository(): \Domain\Admissions\Repository\AdmissionWithdrawalRepository
    {
        return new \Infrastructure\Persistence\Repository\SqlAdmissionWithdrawalRepository(
            $this->sql,
            $this->dropCipher
        );
    }
    public function withdrawalService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null
    ): \Domain\Admissions\Service\AdmissionWithdrawalService {
        return new \Domain\Admissions\Service\AdmissionWithdrawalService(
            $this->admissions,
            $this->withdrawalRepository(),
            $this->admissionPolicy(),
            $this->decisionLedger(),
            $this->applicationContext(),
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql),
            $this->access,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($this->sql),
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock,
            new \Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository($this->sql)
        );
    }
    public function admissionPolicyService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null
    ): \Domain\Admissions\Service\AdmissionPolicyService {
        return new \Domain\Admissions\Service\AdmissionPolicyService(
            $this->admissionPolicy(),
            $this->access,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($this->sql),
            $this->transactions,
            $audit ?? $this->audit
        );
    }
    public function admissionPolicy(): \Domain\Admissions\Repository\AdmissionPolicyRepository
    {
        return new \Infrastructure\Persistence\Repository\SqlAdmissionPolicyRepository($this->sql);
    }
    public function offerService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null
    ): \Domain\Admissions\Service\AdmissionOfferService {
        return new \Domain\Admissions\Service\AdmissionOfferService(
            $this->admissions,
            $this->decisionLedger(),
            $this->admissionPolicy(),
            $this->access,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($this->sql),
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock,
            $this->entryTerms(),
            new \Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository($this->sql),
            $this->withdrawalRepository()
        );
    }
    public function matriculationService(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Catalog\Service\CatalogEnrollment $initializer = null,
        ?\Domain\Admissions\Repository\AdmissionsRepository $repository = null,
    ): \Domain\Admissions\Service\MatriculationService {
        $db = $this->connection->queryBuilder();
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $this->sql,
            $this->transactions,
            $this->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($db)),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        return new \Domain\Admissions\Service\MatriculationService(
            $repository ?? $this->admissions,
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($this->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($this->sql),
            $this->programEntryContext($initializer),
            $this->programStatusLedger(),
            $this->activityLedger(),
            $this->primaryProgramLedger(),
            $history,
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->dropCipher,
            $this->clock,
            $this->decisionLedger(),
            new \Domain\Admissions\Service\AdmissionOfferGate(
                policy: $this->admissionPolicy(),
                ledger: $this->decisionLedger(),
                admissions: $repository ?? $this->admissions,
                clock: $this->clock, entryTerms: $this->entryTerms(),
                requests: $this->withdrawalRepository()
            ),
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($this->sql),
        );
    }
    public function matriculate(EntityId $application): Student
    {
        $service = $this->matriculationService();
        $review = $service->review($application, "Fictional confirmed matriculation");
        $result = $service->commit($application, $review["review_token"]);
        return $this->admissions->student(new EntityId($result["student_id"]));
    }
    /** Explicit current-state fixture for records predating tracked matriculation. No invented events. */
    public function legacyStudent(EntityId $application): Student
    {
        $a = $this->admissions->application($application);
        $student = $a->matriculate(EntityId::generate());
        $p = new \Domain\Student\StudentProgram(
            EntityId::generate(),
            $student->id,
            $a->programId,
            $a->entryTermId,
        );
        $this->transactions->run(function () use ($a, $student, $p): void {
            $this->admissions->projectMatriculation($a, $student, $p);
            $this->catalogEnrollment()->initialize($p, $this->access->actor);
        });
        return $student;
    }
    /** Explicit older current-state fixture; no historical creation is invented. */
    public function legacyPerson(EntityId $owner, string $given, string $family): \Domain\Person\Person
    {
        $p = new \Domain\Person\Person(EntityId::generate(), $owner, $given, $family,
            new \Domain\Person\ValueObject\InstitutionalId('T-' . bin2hex(random_bytes(6))));
        $this->admissions->addPerson($p);
        return $p;
    }

    public function sectionChanges(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Registration\Service\SectionChangeService {
        return new \Domain\Registration\Service\SectionChangeService(
            $this->registration,
            $this->admissions,
            $this->registrationBatches(),
            $this->service,
            $this->drops,
            new \Infrastructure\Persistence\Repository\SqlDropPolicyRepository(
                $this->sql,
                $this->gradeCatalog(),
            ),
            $this->dropPolicies,
            new \Infrastructure\Persistence\Repository\SqlSectionChangeSearch($this->sql),
            $this->registrationBilling,
            new \Infrastructure\Persistence\Repository\SqlRegistrationBatchRepository($this->sql),
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->clock,
            new \Domain\Shared\Service\ReviewProof($this->dropCipher, $this->clock),
        );
    }

    public function registrationBatches(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Registration\Repository\RegistrationRepository $repository = null,
        ?\Domain\Registration\Repository\RegistrationBatchRepository $receipts = null,
        ?\Domain\Registration\Service\PrerequisiteService $prerequisites = null,
        ?\Domain\Registration\Service\RegistrationService $registration = null,
    ): \Domain\Registration\Service\RegistrationBatchService {
        return new \Domain\Registration\Service\RegistrationBatchService(
            $receipts ?? new \Infrastructure\Persistence\Repository\SqlRegistrationBatchRepository($this->sql),
            $repository ?? $this->registration,
            $this->admissions,
            $registration ?? $this->service,
            $this->access,
            $this->transactions,
            $audit ?? $this->audit,
            $this->clock,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->sql),
            $this->workPursuits(),
            $prerequisites ?? $this->prerequisites(),
            $this->schedules(),
            $this->registrationBilling,
            new \Domain\Shared\Service\ReviewProof($this->dropCipher, $this->clock),
            $this->corequisites(),
            $this->windowPolicies(),
        );
    }

    /** Indexed read fixture only. No earlier enrollment/profile decisions are invented. */
    public function worksheetStudent(string $name, string $status = "A"): EntityId
    {
        $person = $this->sql->rows("sis_persons")[0];
        $person["id"] = EntityId::generate()->value;
        $person["owner_user_id"] = null;
        $person["given_name"] = $name;
        $person["family_name"] = "Worksheet";
        $this->sql->insert("sis_persons", $person);
        $identifier = $this->sql->rows("sis_person_identifiers")[0];
        $identifier["id"] = EntityId::generate()->value;
        $identifier["person_id"] = $person["id"];
        $identifier["institutional_id"] = "TEST-" . $person["id"];
        $this->sql->insert("sis_person_identifiers", $identifier);
        $student = $this->sql->rows("sis_students")[0];
        $student["id"] = EntityId::generate()->value;
        $student["person_id"] = $person["id"];
        $this->sql->insert("sis_students", $student);
        $enrollment = $this->sql->rows("sis_enrollments")[0];
        $enrollment["id"] = EntityId::generate()->value;
        $enrollment["student_id"] = $student["id"];
        $enrollment["status"] = $status;
        $this->sql->insert("sis_enrollments", $enrollment);
        return new EntityId($student["id"]);
    }

    public function student(?EntityId $owner = null): Student
    {
        $service = new AdmissionsService(
            $this->admissions,
            $this->access,
            $this->transactions,
            $this->audit,
            new \Infrastructure\Identity\RandomInstitutionalIdGenerator(),
            $this->decisionLedger(),
            $this->submissionService(),
            $this->profileLedger(),
            new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository($this->sql),
        );
        if (
            $owner === null &&
            $this->sql->rows("sis_persons", ["owner_user_id" => $this->access->actor->value], 1) !== []
        ) {
            $owner = $this->user();
        }
        $person = $this->legacyPerson($owner ?? $this->access->actor, "Alex", "Example");
        $application = $this->legacyApplication($person->id, $this->program, $this->term);
        $this->legacyAdmissionDecision($application->id, true);

        return $this->legacyStudent($application->id);
    }

    public function reportLifecycles(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Reporting\Repository\ReportCaptureSource $capture = null,
        ?\Domain\Communications\Service\MessageCipher $cipher = null,
    ): \Domain\Reporting\Service\ReportLifecycleService {
        $source = new \Infrastructure\Reporting\SqlRelationalReportSource($this->sql);
        return new \Domain\Reporting\Service\ReportLifecycleService(
            new \Infrastructure\Persistence\Repository\SqlReportRepository($this->sql, $this->clock),
            new \Infrastructure\Persistence\Repository\SqlRelationalReportRepository($this->sql),
            $source,
            $capture ?? new \Infrastructure\Reporting\SqlReportCaptureSource($this->connection->queryBuilder(), $source),
            new \Infrastructure\Persistence\Repository\SqlReportLifecycleRepository($this->sql),
            $this->access, $this->transactions, $audit ?? $this->audit, $cipher ?? $this->dropCipher, $this->clock
        );
    }

    public function section(int $capacity = 20, int $fee = 10000, ?EntityId $faculty = null): Section
    {
        $course = new Course(EntityId::generate(), "CS-" . bin2hex(random_bytes(3)), "Computing", 3000);
        $this->registration->addCourse($course);
        $section = new Section(
            EntityId::generate(),
            $course->id,
            $this->term,
            $faculty,
            $course->code . "-01",
            $capacity,
            true,
            true,
            new Money($fee, "USD"),
        );
        $this->registration->addSection($section);

        return $section;
    }
}
