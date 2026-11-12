<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";
require_once dirname(__DIR__, 2) .
    "/database/migrations/20261111000001_CreateSisCourseAssistancePermissions.php";

require_once dirname(__DIR__, 2) .
    "/database/migrations/20261111000002_CreateSisCourseTextAssistancePermissions.php";

require_once dirname(__DIR__, 2) .
    "/database/migrations/20261111000003_CreateSisSectionAssistancePermission.php";

require_once dirname(__DIR__, 2) .
    "/database/migrations/20261111000004_CreateSisDegreeAssistancePermission.php";

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;

final class CourseAssistancePermissionTest extends TestCase
{
    private function migrate(SisFixture $f): void
    {
        $migration = new CreateSisCourseAssistancePermissions("assistance");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($f->connection, "test_migrations"),
            ]),
        );
        $migration->up();
    }
    public function testSectionPermissionIsProtectedIndependentAndIdempotent(): void
    {
        $f = new SisFixture();
        $migration = new CreateSisSectionAssistancePermission("section-assistance");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($f->connection, "test_migrations"),
            ]),
        );
        $before = $f->sql->rows("sis_access_permissions");
        $migration->up();
        self::assertSame($before, $f->sql->rows("sis_access_permissions"));
        $permission = $f->sql->rows("sis_access_permissions", ["name" => "sis:ai:section:readiness"], 1)[0];
        self::assertSame(1, (int) $permission["is_system"]);
        $implications = $f->sql->rows("sis_access_implications", ["descendant_id" => $permission["id"]]);
        self::assertCount(1, $implications);
        self::assertSame($permission["id"], $implications[0]["ancestor_id"]);
        $f->sql->update("sis_access_permissions", ["is_system" => 0], ["id" => $permission["id"]]);
        $this->expectException(LogicException::class);
        $migration->up();
    }
    public function testDegreePermissionIsProtectedIndependentAndIdempotent(): void
    {
        $f = new SisFixture();
        $migration = new CreateSisDegreeAssistancePermission("degree-assistance");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($f->connection, "test_migrations"),
            ]),
        );
        $before = $f->sql->rows("sis_access_permissions");
        $migration->up();
        self::assertSame($before, $f->sql->rows("sis_access_permissions"));
        $permission = $f->sql->rows("sis_access_permissions", ["name" => "sis:ai:degree:readiness"], 1)[0];
        self::assertSame(1, (int) $permission["is_system"]);
        $implications = $f->sql->rows("sis_access_implications", ["descendant_id" => $permission["id"]]);
        self::assertCount(1, $implications);
        self::assertSame($permission["id"], $implications[0]["ancestor_id"]);
        $f->sql->update("sis_access_permissions", ["is_system" => 0], ["id" => $permission["id"]]);
        $this->expectException(LogicException::class);
        $migration->up();
    }
    private function migrateText(SisFixture $f): void
    {
        $migration = new CreateSisCourseTextAssistancePermissions("text-assistance");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($f->connection, "test_migrations"),
            ]),
        );
        $migration->up();
    }
    public function testTextMigrationRejectsCustomDefinitionWithoutPromotingIt(): void
    {
        $f = new SisFixture();
        $f->sql->update("sis_access_permissions", ["is_system" => 0], ["name" => "sis:ai:course:expand"]);
        $before = $f->sql->rows("sis_access_permissions");
        try {
            $this->migrateText($f);
            self::fail();
        } catch (LogicException) {
            self::assertSame($before, $f->sql->rows("sis_access_permissions"));
        }
    }
    public function testMigrationIsIdempotentAndCreatesOnlyIndependentProtectedPermissions(): void
    {
        $f = new SisFixture();
        $before = $f->sql->rows("sis_access_permissions");
        $this->migrate($f);
        $this->migrateText($f);
        self::assertSame($before, $f->sql->rows("sis_access_permissions"));
        foreach (["readiness", "description", "rewrite", "expand"] as $action) {
            $permission = $f->sql->rows(
                "sis_access_permissions",
                ["name" => "sis:ai:course:" . $action],
                1,
            )[0];
            self::assertSame(1, (int) $permission["is_system"]);
            $implications = $f->sql->rows("sis_access_implications", ["descendant_id" => $permission["id"]]);
            self::assertCount(1, $implications);
            self::assertSame($permission["id"], $implications[0]["ancestor_id"]);
        }
    }
    public function testConflictingCustomDefinitionIsNotSilentlyPromoted(): void
    {
        $f = new SisFixture();
        $f->sql->update("sis_access_permissions", ["is_system" => 0], ["name" => "sis:ai:course:readiness"]);
        $this->expectException(LogicException::class);
        $this->migrate($f);
    }
}
