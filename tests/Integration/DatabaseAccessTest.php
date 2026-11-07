<?php

declare(strict_types=1);

use Application\Http\Controller\SisAccessController;
use Application\Http\Middleware\SisPrivacyMiddleware;
use Codefy\Framework\Auth\Repository\AuthUserRepository;
use Codefy\Framework\Http\Middleware\Auth\UserCookieDecryptMiddleware;
use Codefy\Framework\Http\RequestContext;
use Domain\Access\AccessDefinition;
use Domain\Access\Enum\DefinitionKind;
use Domain\Access\Enum\GrantKind;
use Domain\Access\Service\AccessService;
use Domain\Access\Service\BootstrapAccess;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\UserAuth;
use Infrastructure\Security\GateStudentAccess;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Handlers\CallableRequestHandler;

require_once dirname(__DIR__) . '/Support/SisFixture.php';

final class DatabaseAccessTest extends TestCase
{
    private SisFixture $fixture;
    private SqlAccessRepository $repository;
    private AccessService $service;
    private UserAuth $gate;

    protected function setUp(): void
    {
        $this->fixture = $f = new SisFixture();
        $this->repository = new SqlAccessRepository($f->sql);
        $bootstrap = new BootstrapAccess($this->repository, $f->transactions, $f->audit);
        $bootstrap->assignFirstAdministrator($f->access->actor);
        $auth = $this->createStub(AuthUserRepository::class);
        $auth->method('find')->willReturnCallback(static fn () =>
            (object) $f->sql->one('users', ['user_id' => $f->access->actor->value]));
        $this->gate = new UserAuth($this->repository, $auth);
        RequestContext::set((new ServerRequest([], [], '/sis/access/roles/', 'GET'))
            ->withAttribute(UserCookieDecryptMiddleware::USER_COOKIE, 'fictional-test-token'));
        $this->service = new AccessService($this->repository, new GateStudentAccess($this->gate),
            $f->transactions, $f->audit);
    }

    protected function tearDown(): void
    {
        RequestContext::clear();
    }

    private function permission(string $name): EntityId
    {
        return new EntityId($this->fixture->sql->one('sis_access_permissions', ['name' => $name])['id']);
    }

    public function testSuperIsUnrestrictedAndRegularAdministratorsCannotPromoteThemselves(): void
    {
        $super = $this->fixture->access->actor;
        self::assertTrue($this->gate->can('future:module:operation'));
        $adminUser = $this->fixture->user();
        $this->service->assign(GrantKind::UserRole, $adminUser, $this->repository->roleNamed('admin')->id, true);
        self::assertTrue($this->repository->allows($adminUser->value, 'sis:student:read'));
        self::assertFalse($this->repository->allows($adminUser->value, 'future:module:operation'));
        self::assertFalse($this->repository->allows($adminUser->value, AccessService::MANAGE));
        $this->service->assign(GrantKind::UserPermission, $adminUser, $this->permission(AccessService::MANAGE), true);
        $this->fixture->access->actor = $adminUser;
        foreach ([[$adminUser, true], [$super, false]] as [$subject, $grant]) {
            try {
                $this->service->assign(GrantKind::UserRole, $subject, $this->repository->roleNamed('super')->id, $grant);
                self::fail('An ordinary access administrator changed a super assignment.');
            } catch (AccessDeniedException) {
                self::assertFalse($this->repository->isSuper($adminUser->value));
                self::assertTrue($this->repository->isSuper($super->value));
            }
        }
    }

    public function testLegacyInstitutionalRolesAreProtectedAndDoNotGrantCampusWideStudentAccess(): void
    {
        foreach (['faculty', 'parent', 'student', 'staff', 'user', 'manager'] as $name) {
            $role = $this->repository->roleNamed($name);
            self::assertTrue($role->isSystem);
            $user = $this->fixture->user();
            $this->service->assign(GrantKind::UserRole, $user, $role->id, true);
            self::assertTrue($this->repository->allows($user->value, 'admin:dashboard'));
            self::assertFalse($this->repository->allows($user->value, 'sis:student:read'));
            self::assertFalse($this->repository->allows($user->value, 'sis:gradebook:manage'));
            self::assertFalse($this->repository->allows($user->value, AccessService::MANAGE));
        }
    }

    public function testExistingUserEditorCannotTakeOverOrDeleteASuperAccount(): void
    {
        $super = $this->fixture->access->actor;
        $admin = $this->fixture->user();
        $this->service->assign(GrantKind::UserRole, $admin, $this->repository->roleNamed('admin')->id, true);
        $access = new \Domain\User\Service\UserAccountAccess($this->repository,
            new GateStudentAccess($this->gate), $this->fixture->transactions);
        $ran = false;
        $operation = static function () use (&$ran): void { $ran = true; };
        $this->fixture->access->actor = $admin;
        try {
            $access->update($super, $operation);
            self::fail('Ordinary admin edited a super account.');
        } catch (AccessDeniedException) {
            self::assertFalse($ran);
        }
        try {
            $access->delete($super, $operation);
            self::fail('Super account deleted with its role intact.');
        } catch (DomainException) {
            self::assertFalse($ran);
        }
        $access->update($admin, $operation);
        self::assertTrue($ran);
    }

    public function testRoleAndIndividualGrantsAreAdditiveAndRevocationsAreImmediate(): void
    {
        $user = $this->fixture->user();
        $role = $this->service->create(DefinitionKind::Role, 'registrar', 'Registrar');
        $custom = $this->service->create(DefinitionKind::Permission, 'sis:records:export');
        $read = $this->permission('sis:student:read');
        $this->service->assign(GrantKind::RolePermission, $role->id, $read, true);
        $this->service->assign(GrantKind::RolePermission, $role->id, $custom->id, true);
        $this->service->assign(GrantKind::UserRole, $user, $role->id, true);
        $this->service->assign(GrantKind::UserPermission, $user, $read, true);
        self::assertTrue($this->repository->allows($user->value, 'sis:student:read'));
        self::assertTrue($this->repository->allows($user->value, 'sis:records:export'));
        $this->service->assign(GrantKind::UserPermission, $user, $read, false);
        self::assertTrue($this->repository->allows($user->value, 'sis:student:read'));
        $this->service->assign(GrantKind::UserRole, $user, $role->id, false);
        self::assertFalse($this->repository->allows($user->value, 'sis:student:read'));
        self::assertFalse($this->repository->allows($user->value, 'sis:records:export'));
        $this->service->assign(GrantKind::UserPermission, $user, $custom->id, true);
        $this->service->assign(GrantKind::UserPermission, $user, $custom->id, true);
        self::assertCount(1, $this->service->assignments(GrantKind::UserPermission, $user));
        self::assertTrue($this->repository->allows($user->value, 'sis:records:export'));
        self::assertFalse($this->repository->allows($user->value, "sis:records:export' OR 1=1 --"));
        self::assertFalse($this->repository->allows(EntityId::generate()->value, 'sis:records:export'));
    }

    public function testSystemDefinitionsAndSystemRoleContentsCannotBeChanged(): void
    {
        $role = $this->repository->roleNamed('admin');
        $permission = $this->permission('sis:student:read');
        foreach ([[DefinitionKind::Role, $role->id], [DefinitionKind::Permission, $permission]] as [$kind, $id]) {
            foreach (['delete', 'update'] as $action) {
                try {
                    if ($action === 'delete') {
                        $this->service->delete($kind, $id);
                    } else {
                        $this->service->updateDescription($kind, $id, 'Changed');
                    }
                    self::fail('System definition was editable.');
                } catch (DomainException $e) {
                    self::assertStringContainsString('System roles and permissions', $e->getMessage());
                }
            }
        }
        foreach ([true, false] as $grant) {
            try {
                $this->service->assign(GrantKind::RolePermission, $role->id, $permission, $grant);
                self::fail('System role contents were editable.');
            } catch (DomainException) {
                self::assertTrue($this->gate->can('sis:student:read'));
            }
        }
    }

    public function testCustomDefinitionsCanBeDescribedAndDeletedWithoutAffectingSystemDefinitions(): void
    {
        $user = $this->fixture->user();
        $role = $this->service->create(DefinitionKind::Role, 'custom-office');
        $permission = $this->service->create(DefinitionKind::Permission, 'office:export');
        $this->service->assign(GrantKind::RolePermission, $role->id, $permission->id, true);
        $this->service->assign(GrantKind::UserRole, $user, $role->id, true);
        $this->service->assign(GrantKind::UserPermission, $user, $permission->id, true);
        $this->service->updateDescription(DefinitionKind::Role, $role->id, 'New description');
        self::assertSame('New description', $this->repository->definition(DefinitionKind::Role, $role->id)->description);
        $this->service->delete(DefinitionKind::Permission, $permission->id);
        self::assertFalse($this->repository->allows($user->value, 'office:export'));
        self::assertSame([], $this->service->assignments(GrantKind::RolePermission, $role->id));
        $this->service->delete(DefinitionKind::Role, $role->id);
        self::assertSame([], $this->service->assignments(GrantKind::UserRole, $user));
        self::assertTrue($this->gate->can(AccessService::MANAGE));
    }

    public function testLastAdministratorIsProtectedAndARevokedAdministratorCannotWriteAgain(): void
    {
        $actor = $this->fixture->access->actor;
        $admin = $this->repository->roleNamed('super');
        $before = count($this->fixture->audit->forSubject($actor));
        try {
            $this->service->assign(GrantKind::UserRole, $actor, $admin->id, false);
            self::fail('Removed final administrator.');
        } catch (DomainException) {
            self::assertTrue($this->gate->can(AccessService::MANAGE));
            self::assertCount($before, $this->fixture->audit->forSubject($actor));
        }
        $other = $this->fixture->user();
        $this->service->assign(GrantKind::UserRole, $other, $admin->id, true);
        $this->service->assign(GrantKind::UserRole, $actor, $admin->id, false);
        self::assertFalse($this->gate->can(AccessService::MANAGE));
        $this->expectException(AccessDeniedException::class);
        $this->service->create(DefinitionKind::Role, 'unauthorized');
    }

    public function testLegacyRoleColumnAndOwnershipCannotAuthorizeAccessManagement(): void
    {
        $user = $this->fixture->user();
        $this->fixture->sql->update('users', ['role' => 'admin'], ['user_id' => $user->value]);
        $this->fixture->access->actor = $user;
        self::assertFalse($this->gate->can(AccessService::MANAGE));
        foreach (['read', 'create', 'assign'] as $operation) {
            try {
                match ($operation) {
                    'read' => $this->service->definitions(DefinitionKind::Role),
                    'create' => $this->service->create(DefinitionKind::Role, 'intruder'),
                    'assign' => $this->service->assign(GrantKind::UserPermission, $user,
                        $this->permission(AccessService::MANAGE), true),
                };
                self::fail('Management accepted an unprivileged identity.');
            } catch (AccessDeniedException) {
                self::assertFalse($this->repository->allows($user->value, AccessService::MANAGE));
            }
        }
    }

    public function testMigrationImportsLegacyRolesOnceAndDoesNotRestoreRevokedAccess(): void
    {
        $f = $this->fixture;
        $user = $f->user();
        $f->sql->update('users', ['role' => 'super'], ['user_id' => $user->value]);
        $migration = new CreateSisAccessTables('access');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
            new DbalMigrationAdapter($f->connection, 'test_migrations')]));
        $migration->down();
        $migration->up();
        self::assertTrue($this->repository->allows($user->value, AccessService::MANAGE));
        $admin = $this->repository->roleNamed('super');
        $this->repository->assign(GrantKind::UserRole, $user, $admin->id, false);
        $migration->up();
        self::assertFalse($this->repository->allows($user->value, AccessService::MANAGE));
        self::assertCount(8, $this->repository->definitions(DefinitionKind::Role, null, 100));
    }

    public function testListsAreBoundedAndStableAndDuplicateNamesAreRejected(): void
    {
        $first = $this->service->definitions(DefinitionKind::Permission, limit: 2);
        $next = $this->service->definitions(DefinitionKind::Permission, $first[1]->id, 2);
        self::assertCount(2, $first);
        self::assertCount(2, $next);
        self::assertGreaterThan($first[1]->id->value, $next[0]->id->value);
        $this->expectException(DomainException::class);
        $this->service->create(DefinitionKind::Role, 'admin');
    }

    public function testBootstrapCannotAddAnotherAdministrator(): void
    {
        $bootstrap = new BootstrapAccess($this->repository, $this->fixture->transactions, $this->fixture->audit);
        $this->expectException(DomainException::class);
        $bootstrap->assignFirstAdministrator($this->fixture->user());
    }

    public function testGateDeniesMissingAuthenticationAndRejectedSessions(): void
    {
        RequestContext::clear();
        self::assertFalse($this->gate->can('sis:student:read'));
        RequestContext::set(new ServerRequest([], [], '/sis/access/roles/', 'GET'));
        self::assertFalse($this->gate->can('sis:student:read'));
        RequestContext::set(RequestContext::get()->withAttribute(UserCookieDecryptMiddleware::USER_COOKIE, 'fictional')
            ->withHeader(\Codefy\Framework\Http\Middleware\Auth\UserAuthorizationMiddleware::HEADER_HTTP_STATUS_CODE,
                'not_authorized'));
        self::assertFalse($this->gate->can('sis:student:read'));
    }

    public function testNewAccountsReceiveOnlyTheDefaultRoleAndLegacyRoleEventsCannotEscalate(): void
    {
        $f = $this->fixture;
        foreach (['username', 'token', 'middle_name', 'password', 'created_on'] as $column) {
            $f->connection->pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ' VARCHAR(191)');
        }
        $id = new \Domain\User\ValueObject\UserId();
        $event = \Domain\User\Event\UserWasCreated::withData(
            $id,
            new \Domain\User\ValueObject\Username('fictional'),
            new \Domain\User\ValueObject\UserToken(),
            new \Qubus\ValueObjects\Person\Name(new \Qubus\ValueObjects\StringLiteral\StringLiteral('Alex'),
                new \Qubus\ValueObjects\StringLiteral\StringLiteral(''),
                new \Qubus\ValueObjects\StringLiteral\StringLiteral('Example')),
            new \Qubus\ValueObjects\Web\EmailAddress('fictional@example.invalid'),
            new \Domain\User\ValueObject\UserRole('admin'),
            new \Qubus\ValueObjects\StringLiteral\StringLiteral('fictional-password-hash'),
            new DateTimeImmutable(),
        );
        $projection = new \Infrastructure\Projection\ExpressiveDbalUserProjection(
            $f->connection->queryBuilder(), $this->repository,
            new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository($f->sql, $f->admissions,
                new \Infrastructure\Identity\RandomInstitutionalIdGenerator()),
        );
        $projection->projectWhenUserWasCreated($event);
        self::assertTrue($this->repository->allows((string) $id, 'admin:dashboard'));
        self::assertFalse($this->repository->allows((string) $id, AccessService::MANAGE));
        $projection->projectWhenRoleWasChanged(\Domain\User\Event\RoleWasChanged::withData(
            $id, new \Domain\User\ValueObject\UserRole('admin'),
        ));
        self::assertFalse($this->repository->allows((string) $id, AccessService::MANAGE));
    }

    public function testLargeRolesUseOneIndexedExistenceQueryWithoutHydratingPermissions(): void
    {
        $connection = new AccessTestConnection(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $f = new SisFixture($connection);
        $repository = new SqlAccessRepository($f->sql);
        $role = new AccessDefinition(EntityId::generate(), 'large-office');
        $repository->add(DefinitionKind::Role, $role);
        $f->transactions->run(function () use ($repository, $role, $f): void {
            for ($i = 0; $i < 2500; ++$i) {
                $permission = new AccessDefinition(EntityId::generate(), 'large:permission:' . $i);
                $repository->add(DefinitionKind::Permission, $permission);
                $repository->assign(GrantKind::RolePermission, $role->id, $permission->id, true);
            }
            $repository->assign(GrantKind::UserRole, $f->access->actor, $role->id, true);
        });
        $pdo = $connection->pdo;
        self::assertInstanceOf(AccessTestPdo::class, $pdo);
        $pdo->prepares = 0;
        self::assertTrue($repository->allows($f->access->actor->value, 'large:permission:2499'));
        self::assertSame(1, $pdo->prepares);
        $query = $pdo->lastQuery;
        $plan = $pdo->prepare('EXPLAIN QUERY PLAN ' . $query);
        $plan->execute([$f->access->actor->value, 'large:permission:2499']);
        $details = array_column($plan->fetchAll(PDO::FETCH_ASSOC), 'detail');
        self::assertNotEmpty($details);
        foreach ($details as $detail) {
            self::assertDoesNotMatchRegularExpression('/SCAN (?:p|d|a|r|c)\b/', $detail);
        }
        self::assertFalse($repository->allows($f->access->actor->value, 'large:permission:missing'));
        $storage = new \Infrastructure\Security\Rbac\SqlStorageResource($f->sql,
            new AccessService($repository, $f->access, $f->transactions, $f->audit));
        $pdo->prepares = 0;
        $storage->load();
        $storage->save();
        self::assertSame(0, $pdo->prepares);
        $storedRole = $storage->getRole('large-office');
        self::assertNotNull($storedRole);
        $pdo->prepares = 0;
        self::assertTrue($storedRole->checkAccess('large:permission:2499'));
        self::assertSame(1, $pdo->prepares);
        self::assertFalse($storedRole->checkAccess('large:permission:missing'));
        $pdo->prepares = 0;
        $directory = $repository->directory(DefinitionKind::Permission, 'large:permission:', null, 51,
            GrantKind::RolePermission, $role->id, false);
        self::assertCount(51, $directory);
        self::assertSame(1, $pdo->prepares);
        self::assertSame(1, (int) $directory[0]['assigned']);
        self::assertSame(1, (int) $directory[0]['effective']);

    }

    public function testAccessDirectoryPagesDefinitionsAndReadsDirectAndInheritedState(): void
    {
        $f = $this->fixture;
        $user = $f->user();
        $admin = $this->repository->roleNamed('admin');
        $this->service->assign(GrantKind::UserRole, $user, $admin->id, true);
        $read = $this->permission('sis:student:read');
        $page = $this->service->directory(DefinitionKind::Permission, 'sis:student:read', null, 50,
            GrantKind::UserPermission, $user);
        self::assertCount(1, $page['items']);
        self::assertFalse($page['items'][0]['assigned']);
        self::assertTrue($page['items'][0]['effective']);
        $this->service->assign(GrantKind::UserPermission, $user, $read, true);
        $page = $this->service->directory(DefinitionKind::Permission, 'sis:student:read', null, 50,
            GrantKind::UserPermission, $user, true);
        self::assertTrue($page['items'][0]['assigned']);
        $this->service->assign(GrantKind::UserPermission, $user, $read, false);
        self::assertSame([], $this->service->directory(DefinitionKind::Permission, 'sis:student:read', null, 50,
            GrantKind::UserPermission, $user, true)['items']);
        self::assertTrue($this->repository->allows($user->value, 'sis:student:read'));
        for ($i = 0; $i < 55; ++$i) {
            $this->service->create(DefinitionKind::Role, 'paged-office-' . $i);
        }
        $first = $this->service->directory(DefinitionKind::Role, 'paged-office-');
        $second = $this->service->directory(DefinitionKind::Role, 'paged-office-', new EntityId($first['next_after']));
        self::assertCount(50, $first['items']);
        self::assertCount(5, $second['items']);
        self::assertNull($second['next_after']);
        self::assertCount(55, array_unique(array_column([...$first['items'], ...$second['items']], 'id')));
        self::assertSame([], $this->service->directory(DefinitionKind::Role, '%')['items']);
        self::assertSame([], $this->service->directory(DefinitionKind::Role, "' OR 1=1 --")['items']);
        $protected = $this->service->directory(DefinitionKind::Permission, '', null, 50,
            GrantKind::RolePermission, $admin->id);
        self::assertFalse($protected['items'][0]['assignable']);
    }

    public function testAccessDirectoryCannotAuthorizeSuperAssignmentsForDelegatedManagers(): void
    {
        $f = $this->fixture;
        $manager = $f->user();
        $this->service->assign(GrantKind::UserPermission, $manager, $this->permission(AccessService::MANAGE), true);
        $f->access->actor = $manager;
        $page = $this->service->directory(DefinitionKind::Role, 'super', null, 50, GrantKind::UserRole, $manager);
        self::assertCount(1, $page['items']);
        self::assertFalse($page['items'][0]['assignable']);
        $this->expectException(InvalidArgumentException::class);
        $this->service->directory(DefinitionKind::Role, '', null, 50, GrantKind::UserPermission, $manager);
    }

    public function testRealRouterAcceptsAssignmentsAndProtectsPrivateResponses(): void
    {
        $container = new \Qubus\Injector\Psr11\Container(\Qubus\Injector\Config\InjectorFactory::create([
            \Qubus\Injector\Injector::STANDARD_ALIASES => [
                \Psr\Http\Message\ResponseFactoryInterface::class => \Qubus\Routing\Factories\ResponseFactory::class,
                \Qubus\Routing\Interfaces\MiddlewareResolver::class => \Qubus\Routing\Route\InjectorMiddlewareResolver::class,
            ],
        ]));
        $container->share(new SisAccessController($this->service));
        $router = new \Qubus\Routing\Router(new \Qubus\Routing\Route\RouteCollector(), $container);
        (require dirname(__DIR__, 2) . '/routes/web/sis-access.php')($router);
        $response = $router->match((new ServerRequest([], [], '/sis/access/roles/', 'POST'))
            ->withParsedBody(['name' => 'records-office', 'description' => 'Records', 'is_system' => true]));
        self::assertSame(201, $response->getStatusCode());
        $role = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($role['is_system']);
        $read = $this->permission('sis:student:read');
        $path = '/sis/access/roles/' . $role['id'] . '/permissions/' . $read->value . '/';
        self::assertSame(204, $router->match(new ServerRequest([], [], $path, 'PUT'))->getStatusCode());
        self::assertSame(204, $router->match(new ServerRequest([], [], $path, 'DELETE'))->getStatusCode());
        $this->fixture->access->actor = $this->fixture->user();
        $response = new SisPrivacyMiddleware()->process(new ServerRequest([], [], '/sis/access/roles/', 'GET'),
            new CallableRequestHandler($router->match(...)));
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }
}

/** Captures statement structure only; fixture parameter values are never collected. */
final class AccessTestPdo extends PDO
{
    public int $prepares = 0;
    public string $lastQuery = '';

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ++$this->prepares;
        $this->lastQuery = $query;

        return parent::prepare($query, $options);
    }
}

final class AccessTestConnection extends \Qubus\Expressive\Connection\Pdo\Sqlite
{
    protected function createPdo(array $config): PDO
    {
        $pdo = new AccessTestPdo('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
