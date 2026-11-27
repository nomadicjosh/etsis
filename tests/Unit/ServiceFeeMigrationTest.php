<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Migration\NullableUlidReferences;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;

final class ServiceFeeMigrationTest extends TestCase
{
    public function testPortableDefinitionsAndRepeatPreserveInstalledFactsAndAuthority(): void
    {
        require_once dirname(__DIR__) . "/Support/CampusFixture.php";
        $c = new \CampusFixture();
        $f = $c->sis;
        $student = $f->student();
        $f->service->register($student->id, $f->section(fee: 1000)->id);
        $account = new EntityId($c->service->me()["accounts"][0]["id"]);
        $c->checkout->checkout(EntityId::generate(), $account);
        $before = [];
        foreach (
            [
                "sis_history_events",
                "sis_portal_payments",
                "sis_access_permissions",
                "sis_access_implications",
                "sis_access_user_roles",
                "sis_access_role_permissions",
                "sis_transcript_requests",
            ] as $name
        ) {
            $before[$name] = $f->sql->rows($name);
        }
        foreach (
            ["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect
        ) {
            $connection = $this->createStub(Connection::class);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(
                    static fn(string $s): string => '"' . $s . '"',
                );
            foreach (
                [
                    "sis_service_fee_policies" => "definePolicies",
                    "sis_service_fees" => "defineFees",
                ] as $name => $method
            ) {
                $table = new CreateTable($name);
                \CreateSisServiceFees::$method($table);
                $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
                $sql = json_encode(
                    new $class($connection)->create($table),
                    JSON_THROW_ON_ERROR,
                );
                self::assertStringContainsString($name, $sql);
                self::assertDoesNotMatchRegularExpression(
                    "/DROP |DELETE FROM|AUTO_INCREMENT|sis_history_events|COLLATE/i",
                    $sql,
                );
            }
        }
        $migration = new \CreateSisServiceFees("fees");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter(
                    $f->connection,
                    "test_migrations",
                ),
            ]),
        );
        $migration->up();
        $migration->up();
        foreach ($before as $name => $rows) {
            self::assertSame($rows, $f->sql->rows($name));
        }
        self::assertSame([], $f->sql->rows("sis_service_fees"));
    }
    public function testPopulatedNullableConversionPreservesReferencesIndexesAndTriggers(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new \SisFixture();
        $pdo = $f->connection->pdo;
        $pdo->exec(
            "CREATE TABLE sis_fee_test_parent (id VARCHAR(26) PRIMARY KEY)",
        );
        $pdo->exec(
            "CREATE TABLE sis_fee_test_receipts (id VARCHAR(26) PRIMARY KEY, account_id VARCHAR(26) " .
            "NOT NULL REFERENCES sis_fee_test_parent(id))",
        );
        $pdo->exec(
            "CREATE INDEX fee_test_reference ON sis_fee_test_receipts(account_id)",
        );
        $pdo->exec("CREATE TABLE sis_fee_test_log (value VARCHAR(26))");
        $pdo->exec(
            "CREATE TRIGGER fee_test_insert AFTER INSERT ON sis_fee_test_receipts BEGIN INSERT INTO " .
            "sis_fee_test_log(value) VALUES (NEW.id); END",
        );
        $id = EntityId::generate()->value;
        $pdo->prepare("INSERT INTO sis_fee_test_parent VALUES (?)")->execute([
            $id,
        ]);
        $pdo->prepare(
            "INSERT INTO sis_fee_test_receipts VALUES (?, ?)",
        )->execute([$id, $id]);
        $before = $f->sql->rows("sis_fee_test_receipts");
        NullableUlidReferences::apply($f->connection, "account_id", [
            "sis_fee_test_receipts",
        ]);
        NullableUlidReferences::apply($f->connection, "account_id", [
            "sis_fee_test_receipts",
        ]);
        self::assertSame($before, $f->sql->rows("sis_fee_test_receipts"));
        $pdo->prepare(
            "INSERT INTO sis_fee_test_receipts VALUES (?, NULL)",
        )->execute([EntityId::generate()->value]);
        self::assertCount(2, $f->sql->rows("sis_fee_test_log"));
        self::assertSame(
            [],
            $pdo->query("PRAGMA foreign_key_check")->fetchAll(),
        );
        self::assertNotEmpty(
            $pdo
                ->query(
                    "SELECT name FROM sqlite_master WHERE name='fee_test_reference'",
                )
                ->fetchColumn(),
        );
    }
}
