<?php

declare(strict_types=1);

use Codefy\Framework\Auth\Rbac\Rbac;
use Codefy\Framework\Auth\Rbac\RbacLoader;
use Codefy\Framework\Auth\Rbac\Resource\BaseStorageResource;
use Domain\Person\ValueObject\InstitutionalId;
use PHPUnit\Framework\TestCase;
use Qubus\Config\ConfigContainer;

final class PersonIdentityPermissionTest extends TestCase
{
    public function testSeedAdminReceivesIdAssignmentAndOrdinaryRolesDoNot(): void
    {
        $rbacConfig = require dirname(__DIR__, 2) . '/config/rbac.php';
        $config = $this->createStub(ConfigContainer::class);
        $config->method('getConfigKey')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'rbac.permissions' => $rbacConfig['permissions'],
            'rbac.roles' => $rbacConfig['roles'],
            default => null,
        });
        $storage = new class extends BaseStorageResource {
            public function save(): void
            {
            }

            public function load(): void
            {
                $this->clear();
            }
        };
        $rbac = new Rbac($storage);
        $loader = new RbacLoader($rbac, $config);
        $loader->initRbacPermissions();
        $loader->initRbacRoles();
        self::assertTrue($rbac->getRole('admin')->checkAccess('sis:person:assign-id'));
        self::assertFalse($rbac->getRole('user')->checkAccess('sis:person:assign-id'));
        self::assertFalse($rbac->getRole('manager')->checkAccess('sis:person:assign-id'));
        self::assertTrue($rbac->getRole('user')->checkAccess('admin:profile'));
    }

    public function testInstitutionalIdIsCanonicalAndRejectsUnsafeCharacters(): void
    {
        self::assertSame('0001', new InstitutionalId('0001')->value);
        self::assertSame('STAFF-0001', new InstitutionalId(' staff-0001 ')->value);
        $this->expectException(InvalidArgumentException::class);
        new InstitutionalId("STUDENT\n0001");
    }
}
