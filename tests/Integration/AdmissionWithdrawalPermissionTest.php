<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Access\Enum\GrantKind;
use Domain\Access\Service\AccessService;
use Domain\Access\Service\BootstrapAccess;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use SisFixture;

final class AdmissionWithdrawalPermissionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function migrate(SisFixture $f): void
    {
        $migration = new \CreateSisAdmissionWithdrawalPermission('permission');
        $migration->setObjectMap(new \ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($f->connection, 'test_migrations'),
        ]));
        $migration->up();
    }
    public function testPermissionIsProtectedIdempotentAndRequiresDeliberateDelegation(): void
    {
        $f = new SisFixture();
        $permission = $f->sql->one('sis_access_permissions', ['name' => 'sis:admissions:withdraw']);
        self::assertSame(1, (int) $permission['is_system']);
        $this->migrate($f);
        self::assertSame($permission, $f->sql->one('sis_access_permissions', ['id' => $permission['id']]));
        self::assertCount(1, $f->sql->rows('sis_access_implications', ['descendant_id' => $permission['id']]));
        self::assertSame([], $f->sql->rows('sis_access_role_permissions', ['permission_id' => $permission['id']]));
        $repo = new SqlAccessRepository($f->sql);
        new BootstrapAccess($repo, $f->transactions, $f->audit)->assignFirstAdministrator($f->access->actor);
        $access = new AccessService($repo, $f->access, $f->transactions, $f->audit);
        $user = $f->user();
        $access->assign(GrantKind::UserRole, $user, $repo->roleNamed('admin')->id, true);
        self::assertTrue($repo->allows($user->value, 'sis:admissions:manage'));
        self::assertFalse($repo->allows($user->value, 'sis:admissions:withdraw'));
        self::assertTrue($repo->allows($f->access->actor->value, 'sis:admissions:withdraw'));
    }
    public function testMigrationRejectsAnExistingCustomDefinitionWithoutChangingIt(): void
    {
        $f = new SisFixture();
        $f->sql->update('sis_access_permissions', ['is_system' => 0], ['name' => 'sis:admissions:withdraw']);
        try {
            $this->migrate($f);
            self::fail('A custom permission was silently claimed.');
        } catch (\LogicException) {
            self::assertSame(0, (int) $f->sql->one(
                'sis_access_permissions',
                ['name' => 'sis:admissions:withdraw']
            )['is_system']);
        }
    }
}
