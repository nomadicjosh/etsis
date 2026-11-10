<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

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
    private SisFixture $f;
    private Sqlite $connection;
    private PdoAdmissionsRepository $repository;
    private AdmissionsService $service;
    private EntityId $owner;
    private EntityId $program;
    private EntityId $term;
    private StudentAccess $access;

    protected function setUp(): void
    {
        $this->f = new SisFixture();
        $this->connection = $this->f->connection;
        $this->repository = $this->f->admissions;
        $this->owner = $this->f->access->actor;
        $this->program = $this->f->program;
        $this->term = $this->f->term;
        $this->access = $this->f->access;
        $this->service = $this->f->admissionsService();
    }

    public function testAcceptedApplicationCreatesStudentAndProgramAtomically(): void
    {
        $person = $this->service->createPerson($this->owner, 'Alex', 'Example');
        $application = $this->service->apply($person->id, $this->program, $this->term);
        $this->f->legacyAdmissionDecision($application->id, true);
        $student = $this->f->matriculate($application->id);
        self::assertSame($this->program->value, $this->service->student($student->id)->programId->value);
        self::assertSame(ApplicationStatus::Matriculated, $this->repository->application($application->id)->status);
        self::assertSame(1, (int) $this->connection->pdo->query('SELECT COUNT(*) FROM sis_student_programs')->fetchColumn());
    }

    public function testSubmittedApplicationCannotMatriculate(): void
    {
        $person = $this->service->createPerson($this->owner, 'Alex', 'Example');
        $application = $this->service->apply($person->id, $this->program, $this->term);
        $this->expectException(\Domain\Shared\Exception\ValidationException::class);
        $this->f->matriculate($application->id);
    }

    public function testStaleDecisionCannotOverwriteAcceptance(): void
    {
        $person = $this->service->createPerson($this->owner, 'Alex', 'Example');
        $application = $this->service->apply($person->id, $this->program, $this->term);
        $this->f->legacyAdmissionDecision($application->id, true);
        $this->expectException(ConcurrentModificationException::class);
        $this->repository->saveDecision($application->decide(false), 0);
    }

    public function testDuplicateStudentRollsBackApplicationTransition(): void
    {
        $person = $this->service->createPerson($this->owner, 'Alex', 'Example');
        $first = $this->service->apply($person->id, $this->program, $this->term);
        $second = $this->service->apply($person->id, $this->program, $this->term);
        $this->f->legacyAdmissionDecision($first->id, true);
        $this->f->legacyAdmissionDecision($second->id, true);
        $this->f->matriculate($first->id);
        try {
            $this->f->matriculate($second->id);
            self::fail('Duplicate student was accepted.');
        } catch (\Domain\Shared\Exception\ValidationException) {
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
        $this->f->legacyAdmissionDecision($application->id, true);
        $student = $this->f->matriculate($application->id);
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
            $this->f->decisionLedger(),
        )->student($student->id);
    }

    public function testMigrationCanRollbackAndReapply(): void
    {
        $connection = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $connection->pdo->exec('CREATE TABLE users (user_id VARCHAR(36) PRIMARY KEY)');
        $migration = new CreateSisAdmissionsTables('20261106000000');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($connection, 'test_migrations'),
        ]));
        $migration->up();
        $migration->down();
        $migration->up();
        self::assertSame(0, (int) $connection->pdo->query('SELECT COUNT(*) FROM sis_students')->fetchColumn());
    }

    public function testFinancialHistoryReplaysAndRejectsConcurrentRefunds(): void
    {
        $person = $this->service->createPerson($this->owner, 'Alex', 'Example');
        $application = $this->service->apply($person->id, $this->program, $this->term);
        $this->f->legacyAdmissionDecision($application->id, true);
        $student = $this->f->legacyStudent($application->id);
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
