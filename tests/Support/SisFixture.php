<?php

declare(strict_types=1);

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
            throw new AccessDeniedException('Access denied.');
        }

        return $this->actor;
    }

    public function requireOwnerOrPermission(?EntityId $ownerUserId, string $permission): EntityId
    {
        if (!$this->staff && !in_array($permission, $this->grants, true) && $this->actor->value !== $ownerUserId?->value) {
            throw new AccessDeniedException('Access denied.');
        }

        return $this->actor;
    }
}

final class FixtureClock implements Clock
{
    public DateTimeImmutable $time;

    public function __construct()
    {
        $this->time = new DateTimeImmutable('2026-08-10T10:00:00-07:00');
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
    public SqlAuditTrail $audit;
    public PdoAdmissionsRepository $admissions;
    public SqlRegistrationRepository $registration;
    public EventSourcedAccountRepository $accounts;
    public RegistrationService $service;
    public EntityId $term;
    public EntityId $program;

    public function __construct(?Sqlite $connection = null)
    {
        $this->connection = $connection ?? new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $this->connection->pdo->exec('CREATE TABLE users (user_id VARCHAR(36) PRIMARY KEY, email VARCHAR(191), role VARCHAR(80), first_name VARCHAR(191), last_name VARCHAR(191))');
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') as $path) {
            $name = basename($path);
            if (!str_contains($name, 'CreateEventStoreTable') && !str_contains($name, 'CreateSis') && !str_contains($name, 'CreateCampus')) {
                continue;
            }
            require_once $path;
            $class = preg_replace('/^\d+_|\.php$/', '', $name);
            $migration = new $class('1');
            $migration->setObjectMap(new ArrayObject([
                'phpmig.adapter' => new DbalMigrationAdapter($this->connection, 'test_migrations'),
            ]));
            $migration->up();
        }
        $db = $this->connection->queryBuilder();
        $this->sql = new SqlRecords($db);
        $this->transactions = new DatabaseUnitOfWork($db);
        $this->access = new FixtureAccess($this->user());
        $this->clock = new FixtureClock();
        $this->audit = new SqlAuditTrail($this->sql);
        $this->admissions = new PdoAdmissionsRepository($db);
        $this->registration = new SqlRegistrationRepository($this->sql, $this->clock);
        $this->accounts = new EventSourcedAccountRepository(new PdoTransactionalEventStore($db), $db);
        $rules = new \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository($this->sql,
            new PdoTransactionalEventStore($db), $this->transactions);
        $billing = new EventSourcedRegistrationBilling($this->accounts, $this->sql, $rules, $this->admissions);
        $this->service = new RegistrationService($this->registration, $this->admissions, $this->access,
            $this->transactions, $billing, $this->audit, $this->clock);
        $this->term = EntityId::generate();
        $this->program = EntityId::generate();
        $this->sql->insert('sis_terms', ['id' => $this->term->value, 'code' => '2026FA',
            'starts_on' => '2026-08-01', 'ends_on' => '2026-12-20', 'add_drop_ends_on' => '2026-08-15']);
        $this->sql->insert('sis_programs', ['id' => $this->program->value, 'code' => 'BSCS', 'title' => 'Computer Science']);
        $this->transactions->run(fn () => $this->registration->configureWindow($this->term,
            new RegistrationWindow(new DateRange(new DateTimeImmutable('2026-08-01'),
                new DateTimeImmutable('2026-08-31')), 5)));
    }

    public function user(): EntityId
    {
        $id = EntityId::generate();
        $this->sql->insert('users', ['user_id' => $id->value]);

        return $id;
    }

    public function student(?EntityId $owner = null): Student
    {
        $service = new AdmissionsService($this->admissions, $this->access, $this->transactions, $this->audit,
            new \Infrastructure\Identity\RandomInstitutionalIdGenerator());
        if ($owner === null && $this->sql->rows('sis_persons', ['owner_user_id' => $this->access->actor->value], 1) !== []) {
            $owner = $this->user();
        }
        $person = $service->createPerson($owner ?? $this->access->actor, 'Alex', 'Example');
        $application = $service->apply($person->id, $this->program, $this->term);
        $service->decide($application->id, true);

        return $service->matriculate($application->id);
    }

    public function section(int $capacity = 20, int $fee = 10000, ?EntityId $faculty = null): Section
    {
        $course = new Course(EntityId::generate(), 'CS-' . bin2hex(random_bytes(3)), 'Computing', 3000);
        $this->registration->addCourse($course);
        $section = new Section(EntityId::generate(), $course->id, $this->term, $faculty, $course->code . '-01',
            $capacity, true, true, new Money($fee, 'USD'));
        $this->registration->addSection($section);

        return $section;
    }
}
