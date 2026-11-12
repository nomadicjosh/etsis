<?php

declare(strict_types=1);
use Infrastructure\Persistence\SqliteConnection;
use Qubus\Expressive\Schema\CreateTable;
use PHPUnit\Framework\TestCase;

final class SqliteSchemaIsolationTest extends TestCase
{
    public function testAutoIncrementTablesDoNotRemoveLaterUlidPrimaryKeys(): void
    {
        $db = new SqliteConnection(["driver" => "pdo_sqlite", "dsn" => "sqlite::memory:"]);
        $db->getSchema()->create("sequence_fixture", static function (CreateTable $t): void {
            $t->integer("id")->autoincrement();
        });
        $db->getSchema()->create("parent_fixture", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
        });
        $db->getSchema()->create("child_fixture", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("parent_id", 26)->notNull();
            $t->foreign("parent_id", "fixture_parent_fk")->references("parent_fixture", "id");
        });
        $parent = \Domain\Shared\ValueObject\EntityId::generate()->value;
        $child = \Domain\Shared\ValueObject\EntityId::generate()->value;
        $db->pdo->prepare("INSERT INTO parent_fixture (id) VALUES (?)")->execute([$parent]);
        $db->pdo
            ->prepare("INSERT INTO child_fixture (id, parent_id) VALUES (?, ?)")
            ->execute([$child, $parent]);
        self::assertSame(1, (int) $db->pdo->query("PRAGMA foreign_keys")->fetchColumn());
        self::assertSame([], $db->pdo->query("PRAGMA foreign_key_check")->fetchAll());
        try {
            $db->pdo->prepare("INSERT INTO parent_fixture (id) VALUES (?)")->execute([$parent]);
            self::fail("A duplicate primary key was accepted.");
        } catch (PDOException $e) {
            self::assertSame("23000", $e->getCode());
        }
        $this->expectException(PDOException::class);
        $db->pdo
            ->prepare("INSERT INTO child_fixture (id, parent_id) VALUES (?, ?)")
            ->execute([
                \Domain\Shared\ValueObject\EntityId::generate()->value,
                \Domain\Shared\ValueObject\EntityId::generate()->value,
            ]);
    }
}
