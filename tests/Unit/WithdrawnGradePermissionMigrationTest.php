<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use LogicException;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use SisFixture;

final class WithdrawnGradePermissionMigrationTest extends TestCase
{
    private function migration(SisFixture $f): \AddSisWithdrawnGradePermission
    {
        $migration = new \AddSisWithdrawnGradePermission("withdrawn-grade-duty");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($f->connection, "migration"),
            ]),
        );
        return $migration;
    }

    public function testDutyIsProtectedIdempotentAndNeverGrantedImplicitly(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $tables = [
            "sis_history_events",
            "sis_history_operations",
            "sis_access_permissions",
            "sis_access_implications",
            "sis_access_role_permissions",
            "sis_access_user_roles",
            "sis_access_user_permissions",
            "sis_grade_change_policy",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        $this->migration($f)->up();
        $this->migration($f)->up();
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        $permission = $f->sql->one("sis_access_permissions", ["name" => "sis:gradebook:closed"]);
        self::assertSame(1, $permission["is_system"]);
        self::assertSame(
            [],
            $f->sql->rows("sis_access_role_permissions", ["permission_id" => $permission["id"]]),
        );
        self::assertSame(
            [],
            $f->sql->rows("sis_access_user_permissions", ["permission_id" => $permission["id"]]),
        );
    }

    public function testConflictingCustomPermissionIsNotSilentlyConverted(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $f->sql->update("sis_access_permissions", ["is_system" => 0], ["name" => "sis:gradebook:closed"]);
        $before = [$f->sql->rows("sis_access_permissions"), $f->sql->rows("sis_access_implications")];
        try {
            $this->migration($f)->up();
            self::fail("A custom duty was replaced without review.");
        } catch (LogicException) {
            self::assertSame($before, [
                $f->sql->rows("sis_access_permissions"),
                $f->sql->rows("sis_access_implications"),
            ]);
        }
    }
}
