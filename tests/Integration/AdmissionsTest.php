<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/EventStoreFixture.php';

use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Service\AdmissionsService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\PdoAdmissionsRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;

require_once dirname(__DIR__, 2) . '/database/migrations/20261106000000_CreateSisAdmissionsTables.php';
require_once dirname(__DIR__, 2) . '/database/migrations/20261106000002_CreateSisWorkflowTables.php';
require_once dirname(__DIR__, 2) . '/database/migrations/20261106000004_CreateSisPersonIdentifiersTable.php';

final class AdmissionsTest extends TestCase
{
    private Sqlite $connection;
    private PdoAdmissionsRepository $repository;
    private AdmissionsService $service;
    private EntityId $owner;
    private EntityId $program;
    private EntityId $term;
    private StudentAccess $access;

    protected function setUp(): void
    {
        $this->connection = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $this->connection->pdo->exec('CREATE TABLE users (user_id VARCHAR(36) PRIMARY KEY)');
        $migration = new CreateSisAdmissionsTables('20261106000000');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($this->connection, 'test_migrations'),
        ]));
        $migration->up();
        $workflows = new CreateSisWorkflowTables('3');
        $workflows->setObjectMap($migration->getObjectMap());
        $workflows->up();
        $identities = new CreateSisPersonIdentifiersTable('5');
        $identities->setObjectMap($migration->getObjectMap());
        $identities->up();
        $this->repository = new PdoAdmissionsRepository($this->connection->queryBuilder());
        $this->owner = EntityId::generate();
        $this->program = EntityId::generate();
        $this->term = EntityId::generate();
        $this->connection->pdo->prepare('INSERT INTO users VALUES (?)')->execute([$this->owner->value]);
        $this->connection->pdo->prepare('INSERT INTO sis_programs VALUES (?, ?, ?)')
            ->execute([$this->program->value, 'BSCS', 'Computer Science']);
        $this->connection->pdo->prepare('INSERT INTO sis_terms VALUES (?, ?, ?, ?, ?)')
            ->execute([$this->term->value, '2026FA', '2026-08-01', '2026-12-20', '2026-08-15']);
        $this->access = $this->createStub(StudentAccess::class);
        $this->access->method('requirePermission')->willReturn($this->owner);
        $this->access->method('authenticatedUserId')->willReturn($this->owner);
        $this->access->method('requireOwnerOrPermission')->willReturn($this->owner);
        $this->service = new AdmissionsService(
            $this->repository,
            $this->access,
            new \Infrastructure\Persistence\DatabaseUnitOfWork($this->connection->queryBuilder()),
            new \Infrastructure\Persistence\SqlAuditTrail(new \Infrastructure\Persistence\SqlRecords($this->connection->queryBuilder())),
            new \Infrastructure\Identity\RandomInstitutionalIdGenerator(),
            $this->createStub(\Domain\Catalog\Service\CatalogEnrollment::class)
        );
    }

    public function testAcceptedApplicationCreatesStudentAndProgramAtomically(): void
    {
        $person = $this->service->createPerson($this->owner, 'Alex', 'Example');
        $application = $this->service->apply($person->id, $this->program, $this->term);
        $this->service->decide($application->id, true);
        $student = $this->service->matriculate($application->id);
        self::assertSame($this->program->value, $this->service->student($student->id)->programId->value);
        self::assertSame(ApplicationStatus::Matriculated, $this->repository->application($application->id)->status);
        self::assertSame(1, (int) $this->connection->pdo->query('SELECT COUNT(*) FROM sis_student_programs')->fetchColumn());
    }

    public function testSubmittedApplicationCannotMatriculate(): void
    {
        $person = $this->service->createPerson($this->owner, 'Alex', 'Example');
        $application = $this->service->apply($person->id, $this->program, $this->term);
        $this->expectException(DomainException::class);
        $this->service->matriculate($application->id);
    }

    public function testStaleDecisionCannotOverwriteAcceptance(): void
    {
        $person = $this->service->createPerson($this->owner, 'Alex', 'Example');
        $application = $this->service->apply($person->id, $this->program, $this->term);
        $this->service->decide($application->id, true);
        $this->expectException(ConcurrentModificationException::class);
        $this->repository->saveDecision($application->decide(false), 0);
    }

    public function testDuplicateStudentRollsBackApplicationTransition(): void
    {
        $person = $this->service->createPerson($this->owner, 'Alex', 'Example');
        $first = $this->service->apply($person->id, $this->program, $this->term);
        $second = $this->service->apply($person->id, $this->program, $this->term);
        $this->service->decide($first->id, true);
        $this->service->decide($second->id, true);
        $this->service->matriculate($first->id);
        try {
            $this->service->matriculate($second->id);
            self::fail('Duplicate student was accepted.');
        } catch (PDOException) {
            self::assertSame(ApplicationStatus::Accepted, $this->repository->application($second->id)->status);
            self::assertSame(1, (int) $this->connection->pdo->query('SELECT COUNT(*) FROM sis_students')->fetchColumn());
        }
    }

    public function testPersonCanExistBeforeLoginAccount(): void
    {
        $person = $this->service->createPerson(null, 'Alex', 'Example');
        self::assertNull($this->repository->person($person->id)->ownerUserId);
    }

    public function testPrivateReadChecksOwner(): void
    {
        $person = $this->service->createPerson($this->owner, 'Alex', 'Example');
        $application = $this->service->apply($person->id, $this->program, $this->term);
        $this->service->decide($application->id, true);
        $student = $this->service->matriculate($application->id);
        $denied = $this->createMock(StudentAccess::class);
        $denied->method('authenticatedUserId')->willReturn(EntityId::generate());
        $denied->expects(self::once())->method('requireOwnerOrPermission')->with($this->owner, 'sis:student:read')
            ->willThrowException(new AccessDeniedException());
        $this->expectException(AccessDeniedException::class);
        new AdmissionsService(
            $this->repository,
            $denied,
            new \Infrastructure\Persistence\DatabaseUnitOfWork($this->connection->queryBuilder()),
            new \Infrastructure\Persistence\SqlAuditTrail(new \Infrastructure\Persistence\SqlRecords($this->connection->queryBuilder())),
            new \Infrastructure\Identity\RandomInstitutionalIdGenerator(),
            $this->createStub(\Domain\Catalog\Service\CatalogEnrollment::class)
        )->student($student->id);
    }

    public function testMigrationCanRollbackAndReapply(): void
    {
        $migration = new CreateSisAdmissionsTables('20261106000000');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($this->connection, 'test_migrations'),
        ]));
        $migration->down();
        $migration->up();
        self::assertSame(0, (int) $this->connection->pdo->query('SELECT COUNT(*) FROM sis_students')->fetchColumn());
    }

    public function testFinancialHistoryReplaysAndRejectsConcurrentRefunds(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20230901025725_CreateEventStoreTable.php';
        require_once dirname(__DIR__, 2) . '/database/migrations/20261106000001_CreateSisStudentAccountsTable.php';
        foreach ([new CreateEventStoreTable('1'), new CreateSisStudentAccountsTable('2')] as $migration) {
            $migration->setObjectMap(new ArrayObject([
                'phpmig.adapter' => new DbalMigrationAdapter($this->connection, 'test_migrations'),
            ]));
            $migration->up();
        }
        $person = $this->service->createPerson($this->owner, 'Alex', 'Example');
        $application = $this->service->apply($person->id, $this->program, $this->term);
        $this->service->decide($application->id, true);
        $student = $this->service->matriculate($application->id);
        require_once dirname(__DIR__, 2) . '/database/migrations/20261108000002_CreateSisHistoricalEventTables.php';
        $historyMigration = new CreateSisHistoricalEventTables('history');
        $historyMigration->setObjectMap($migration->getObjectMap());
        $historyMigration->up();
        $db = $this->connection->queryBuilder();
        $store = EventStoreFixture::framework($db);
        $accounts = new \Infrastructure\Persistence\Repository\EventSourcedAccountRepository($store, $db);
        $finance = new \Domain\Finance\Service\StudentAccountService($accounts, $this->repository, $this->access);
        $account = $finance->open($student->id, $this->term, 'USD');
        $id = $account->aggregateId();
        $operation = EntityId::generate();
        $finance->post($id, $operation, \Domain\Finance\Enum\EntryType::Payment, new \Domain\Finance\ValueObject\Money(20000, 'USD'));
        $finance->post($id, $operation, \Domain\Finance\Enum\EntryType::Payment, new \Domain\Finance\ValueObject\Money(20000, 'USD'));
        self::assertSame(-20000, $finance->account($id)->balance()->minor);
        self::assertSame(2, (int) $this->connection->pdo->query('SELECT COUNT(*) FROM sis_history_events')->fetchColumn());
        $first = $accounts->get($id);
        $stale = $accounts->get($id);
        $first->post(
            EntityId::generate(),
            \Domain\Finance\Enum\EntryType::Refund,
            new \Domain\Finance\ValueObject\Money(15000, 'USD'),
            $this->owner
        );
        $accounts->save($first);
        $stale->post(
            EntityId::generate(),
            \Domain\Finance\Enum\EntryType::Refund,
            new \Domain\Finance\ValueObject\Money(15000, 'USD'),
            $this->owner
        );
        $rejected = false;
        try {
            $accounts->save($stale);
        } catch (\Exception) {
            $rejected = true;
            self::assertSame(-5000, $finance->account($id)->balance()->minor);
            self::assertSame(3, (int) $this->connection->pdo->query('SELECT COUNT(*) FROM sis_history_events')->fetchColumn());
        }
        self::assertTrue($rejected, 'Concurrent refund must be rejected.');
        try {
            $finance->open($student->id, $this->term, 'USD');
            self::fail('Duplicate account was accepted.');
        } catch (PDOException) {
            self::assertSame(3, (int) $this->connection->pdo->query('SELECT COUNT(*) FROM sis_history_events')->fetchColumn());
        }
    }


    public function testActualRouterInjectsRequestIntoAdmissionsController(): void
    {
        $injector = \Qubus\Injector\Config\InjectorFactory::create([
            \Qubus\Injector\Injector::STANDARD_ALIASES => [
                \Psr\Http\Message\ResponseFactoryInterface::class => \Qubus\Routing\Factories\ResponseFactory::class,
                \Qubus\Routing\Interfaces\MiddlewareResolver::class => \Qubus\Routing\Route\InjectorMiddlewareResolver::class,
            ],
        ]);
        $container = new \Qubus\Injector\Psr11\Container($injector);
        $container->share(new \Application\Http\Controller\SisAdmissionsController($this->service));
        $router = new \Qubus\Routing\Router(new \Qubus\Routing\Route\RouteCollector(), $container);
        $register = require dirname(__DIR__, 2) . '/routes/web/sis.php';
        $register($router);
        $request = new \Qubus\Http\ServerRequest([], [], '/sis/persons/', 'POST');
        $request = $request->withParsedBody(['given_name' => 'Alex', 'family_name' => 'Example']);
        $response = $router->match($request);
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Alex', $this->repository->person(new EntityId($data['id']))->givenName);
    }
}
