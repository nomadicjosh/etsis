<?php

declare(strict_types=1);

namespace Tests\Integration;

use ArrayObject;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;
use SisFixture;

final class OfficeMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    public function testOfficeDefinitionsCompileForAllFiveFrameworkDatabasesWithoutAnotherJournal(): void
    {
        $f = new SisFixture();
        $definitions = [];
        $schema = $this->createStub(Schema::class);
        $schema->method("hasTable")->willReturn(false);
        $schema
            ->method("create")
            ->willReturnCallback(static function (
                string $name,
                callable $callback,
            ) use (&$definitions): void {
                $table = new CreateTable($name);
                $callback($table);
                $definitions[$name] = $table;
            });
        $connection = $this->createStub(Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection
            ->method("queryBuilder")
            ->willReturn($f->connection->queryBuilder());
        $migration = new \CreateSisOffices("office-compiler");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter(
                    $connection,
                    "fixture",
                ),
            ]),
        );
        $before = $f->sql->rows("sis_history_events");
        $migration->up();
        self::assertSame(
            [
                "sis_offices",
                "sis_office_delegations",
                "sis_restriction_offices",
            ],
            array_keys($definitions),
        );
        $driverCases = ["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"];
        foreach ($driverCases as $driver) {
            $dialect = $this->createStub(Connection::class);
            $dialect
                ->method("quoteIdentifier")
                ->willReturnCallback(
                    static fn(string $name): string => '"' . $name . '"',
                );
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $commands = [];
            foreach ($definitions as $definition) {
                array_push(
                    $commands,
                    ...new $class($dialect)->create($definition),
                );
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            $nameCases = [
                "sod_user_scope",
                "sro_office_page",
                "sod_user_fk",
                "sro_hold_fk",
            ];
            foreach ($nameCases as $name) {
                self::assertStringContainsString($name, $sql);
            }
            self::assertStringNotContainsString("sis_history_events", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|UPDATE |INSERT INTO/i",
                $sql,
            );
        }
        self::assertSame($before, $f->sql->rows("sis_history_events"));
    }

    public function testProtectedPermissionIsSeededAndGrantedOnlyThroughExplicitExistingAuthority(): void
    {
        $f = new SisFixture();
        $row = $f->sql->one("sis_access_permissions", [
            "name" => "sis:restrictions:manage",
        ]);
        self::assertSame(1, (int) $row["is_system"]);
        $access = new SqlAccessRepository($f->sql);
        $user = $f->user();
        self::assertFalse(
            $access->allows($user->value, "sis:restrictions:manage"),
        );
        $role = $f->sql->one("sis_access_roles", ["name" => "admin"]);
        $f->sql->insert("sis_access_user_roles", [
            "id" => EntityId::generate()->value,
            "user_id" => $user->value,
            "role_id" => $role["id"],
        ]);
        self::assertTrue(
            $access->allows($user->value, "sis:restrictions:manage"),
        );
    }
    public function testCurrentOfficeReadsReturnFullRowsWithoutAddingALimitAfterTheLockClause(): void
    {
        $f = new SisFixture();
        $f->transactions->run(function () use ($f): void {
            $row = $f->sql->current('sis_offices', $f->office->value);
            self::assertSame('Fictional Registrar', $row['name']);
            self::assertNull($f->sql->current('sis_offices', EntityId::generate()->value));
        });
        foreach (['mysql', 'pgsql', 'oci', 'sqlsrv', 'sqlite'] as $driver) {
            $sql = \Infrastructure\Persistence\SqlRowLock::select('offices', 'id', $driver, allColumns: true);
            self::assertStringStartsWith('SELECT * FROM offices', $sql);
            self::assertStringNotContainsString('LIMIT', $sql);
            if (in_array($driver, ['mysql', 'pgsql', 'oci'], true)) {
                self::assertStringEndsWith('FOR UPDATE', $sql);
            } elseif ($driver === 'sqlsrv') {
                self::assertStringContainsString('UPDLOCK, ROWLOCK', $sql);
            }
        }
    }
}
