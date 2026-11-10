<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Access\Enum\DefinitionKind;
use Domain\Access\Enum\GrantKind;
use Domain\Access\Service\AccessService;
use Domain\Access\Service\BootstrapAccess;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;

final class AdmissionCorrectionPermissionTest extends TestCase
{
    private function migrate(SisFixture $f): void
    {
        $m = new CreateSisAdmissionCorrectionPermission("permission");
        $m->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($f->connection, "test_migrations"),
            ]),
        );
        $m->up();
    }
    public function testUpgradeRegistersOnlyTheProtectedPermissionAndItsSelfImplication(): void
    {
        $f = new SisFixture();
        $p = $f->sql->one("sis_access_permissions", ["name" => "sis:admissions:correct"]);
        $q = $f->sql->quote(...);
        $f->sql->execute(
            "DELETE FROM " .
                $q("sis_access_implications") .
                " WHERE " .
                $q("ancestor_id") .
                " = ? OR " .
                $q("descendant_id") .
                " = ?",
            [$p["id"], $p["id"]],
        );
        $f->sql->execute("DELETE FROM " . $q("sis_access_permissions") . " WHERE " . $q("id") . " = ?", [
            $p["id"],
        ]);
        $this->migrate($f);
        $p = $f->sql->one("sis_access_permissions", ["name" => "sis:admissions:correct"]);
        self::assertSame(1, (int) $p["is_system"]);
        new EntityId($p["id"]);
        self::assertCount(1, $f->sql->rows("sis_access_implications", ["descendant_id" => $p["id"]]));
        self::assertSame([], $f->sql->rows("sis_access_role_permissions", ["permission_id" => $p["id"]]));
        self::assertSame([], $f->sql->rows("sis_access_user_permissions", ["permission_id" => $p["id"]]));
        $this->migrate($f);
        self::assertSame($p, $f->sql->one("sis_access_permissions", ["name" => "sis:admissions:correct"]));
    }
    public function testRegularAdministratorsRequireAnExplicitGrantAndSystemDefinitionsStayProtected(): void
    {
        $f = new SisFixture();
        $repo = new SqlAccessRepository($f->sql);
        new BootstrapAccess($repo, $f->transactions, $f->audit)->assignFirstAdministrator($f->access->actor);
        $access = new AccessService($repo, $f->access, $f->transactions, $f->audit);
        $user = $f->user();
        $access->assign(GrantKind::UserRole, $user, $repo->roleNamed("admin")->id, true);
        self::assertTrue($repo->allows($f->access->actor->value, "sis:admissions:correct"));
        self::assertTrue($repo->allows($user->value, "sis:admissions:manage"));
        self::assertFalse($repo->allows($user->value, "sis:admissions:correct"));
        $p = new EntityId($f->sql->one("sis_access_permissions", ["name" => "sis:admissions:correct"])["id"]);
        $access->assign(GrantKind::UserPermission, $user, $p, true);
        self::assertTrue($repo->allows($user->value, "sis:admissions:correct"));
        $access->assign(GrantKind::UserPermission, $user, $p, false);
        self::assertFalse($repo->allows($user->value, "sis:admissions:correct"));
        $this->expectException(DomainException::class);
        $access->delete(DefinitionKind::Permission, $p);
    }
    public function testMigrationDoesNotConvertAConflictingCustomPermissionIntoSystemAuthority(): void
    {
        $f = new SisFixture();
        $p = $f->sql->one("sis_access_permissions", ["name" => "sis:admissions:correct"]);
        $f->sql->update("sis_access_permissions", ["is_system" => 0], ["id" => $p["id"]]);
        try {
            $this->migrate($f);
            self::fail("A custom definition was claimed as a system permission.");
        } catch (LogicException) {
            self::assertSame(
                0,
                (int) $f->sql->one("sis_access_permissions", ["id" => $p["id"]])["is_system"],
            );
            self::assertSame([], $f->sql->rows("sis_access_role_permissions", ["permission_id" => $p["id"]]));
        }
    }
}
