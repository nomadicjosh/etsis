<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";
require_once dirname(__DIR__, 2) .
    "/database/migrations/20261111000001_CreateSisCourseAssistancePermissions.php";

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
    public function testMigrationIsIdempotentAndCreatesOnlyIndependentProtectedPermissions(): void
    {
        $f = new SisFixture();
        $before = $f->sql->rows("sis_access_permissions");
        $this->migrate($f);
        self::assertSame($before, $f->sql->rows("sis_access_permissions"));
        foreach (["readiness", "description"] as $action) {
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
