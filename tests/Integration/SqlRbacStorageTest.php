<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Codefy\Framework\Auth\Rbac\Rbac;
use Domain\Access\Enum\DefinitionKind;
use Domain\Access\Service\AccessService;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Security\Rbac\SqlStorageResource;
use PHPUnit\Framework\TestCase;

final class SqlRbacStorageTest extends TestCase
{
    private function storage(SisFixture $fixture): SqlStorageResource
    {
        return new SqlStorageResource($fixture->sql, new AccessService(
            new SqlAccessRepository($fixture->sql),
            $fixture->access,
            $fixture->transactions,
            $fixture->audit
        ));
    }

    public function testFrameworkReadsDatabaseDefinitionsAndChangesAreImmediate(): void
    {
        $fixture = new SisFixture();
        new \Domain\Access\Service\BootstrapAccess(
            new SqlAccessRepository($fixture->sql),
            $fixture->transactions,
            $fixture->audit
        )->assignFirstAdministrator($fixture->access->actor);
        $rbac = new Rbac($this->storage($fixture));
        $role = $rbac->addRole('example-office', 'Fictional office');
        $permission = $rbac->addPermission('example:review');
        self::assertFalse($role->checkAccess('example:review'));
        $role->addPermission($permission);
        self::assertTrue($role->checkAccess('example:review'));
        self::assertSame(['example:review'], array_keys($role->getPermissions()));
        self::assertSame(['example:review'], array_keys($role->getPermissions(true)));
        $role->removePermission('example:review');
        self::assertFalse($role->checkAccess('example:review'));
        $rbac->deletePermission('example:review');
        self::assertNull($rbac->getPermission('example:review'));
        self::assertFalse($permission->checkAccess());
        $rbac->deleteRole('example-office');
        self::assertNull($rbac->getRole('example-office'));
    }

    public function testInheritedPermissionsAndSuperUseDatabaseQueries(): void
    {
        $fixture = new SisFixture();
        $rbac = new Rbac($this->storage($fixture));
        self::assertTrue($rbac->getRole('admin')->checkAccess('sis:student:read'));
        self::assertFalse($rbac->getRole('user')->checkAccess('sis:student:read'));
        self::assertTrue($rbac->getRole('super')->checkAccess('future:permission'));
        self::assertArrayHasKey('sis:student:read', $rbac->getRole('admin')->getPermissions(true));
        self::assertArrayHasKey('sis:student:read', $rbac->getPermission('sis')->getChildren());
        self::assertNull($rbac->getRole('nonexistent'));
    }

    public function testSystemDefinitionsCannotBeChangedOrCleared(): void
    {
        $fixture = new SisFixture();
        $rbac = new Rbac($this->storage($fixture));
        foreach (
            [fn () => $rbac->deleteRole('super'), fn () => $rbac->deletePermission('sis'),
            fn () => $rbac->getRole('admin')->removePermission('sis'), fn () => $rbac->clear()] as $operation
        ) {
            try {
                $operation();
                self::fail('Protected access definitions were changed.');
            } catch (DomainException) {
                self::assertNotNull($rbac->getRole('super'));
            }
        }
    }

    public function testStorageWritesRequireAccessAdministration(): void
    {
        $fixture = new SisFixture();
        $fixture->access->staff = false;
        $rbac = new Rbac($this->storage($fixture));
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $rbac->addRole('unauthorized');
    }
}
