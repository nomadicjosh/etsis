<?php

declare(strict_types=1);

namespace Tests\Unit;

use AddSisApplicationFormScopes;
use ArrayObject;
use PDO;
use PDOException;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class ApplicationFormScopeMigrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261116000007_AddSisApplicationFormScopes.php';
    }

    public function testScopeSchemaCompilesForServerDialects(): void
    {
        $dialects = ['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL', 'sqlsrv' => 'SQLServer', 'oci' => 'Oracle'];
        foreach ($dialects as $driver => $dialect) {
            $statements = [];
            $pdo = $this->createStub(PDO::class);
            $pdo->method('getAttribute')->willReturn($driver);
            $connection = $this->createStub(Connection::class);
            $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $compiler = new $class($connection);
            $schema = $this->createMock(Schema::class);
            $schema->expects(self::exactly(2))->method('alter')->willReturnCallback(
                static function (string $name, callable $define) use ($compiler, &$statements): void {
                    $table = new AlterTable($name);
                    $define($table);
                    array_push($statements, ...$compiler->alter($table));
                }
            );
            $schema->expects(self::once())->method('create')->willReturnCallback(
                static function (string $name, callable $define) use ($compiler, &$statements): void {
                    $table = new CreateTable($name);
                    $define($table);
                    array_push($statements, ...$compiler->create($table));
                }
            );
            $connection->method('getSchema')->willReturn($schema);
            $m = new AddSisApplicationFormScopes('forms');
            $m->setObjectMap(new ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration')]));
            $m->up();
            $sql = json_encode($statements, JSON_THROW_ON_ERROR);
            $parts = [
                'program_id', 'academic_level_id', 'sis_level_forms', 'saf_level_idx', 'slf_form_fk', 'slf_level_uq',
            ];
            foreach ($parts as $part) {
                self::assertStringContainsString($part, $sql, $dialect);
            }
            self::assertDoesNotMatchRegularExpression('/DROP TABLE|DELETE FROM|UPDATE /i', $sql);
            self::assertMatchesRegularExpression('/(?:MODIFY|ALTER COLUMN|ALTER).*program_id/i', $sql, $dialect);
        }
    }

    public function testSqliteConversionPreservesDefinitionsBindingsAndProtectedSubmissions(): void
    {
        $connection = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $pdo = $connection->pdo;
        $pdo->exec('CREATE TABLE sis_programs (id VARCHAR(26) PRIMARY KEY)');
        $pdo->exec('CREATE TABLE sis_catalog_classifications (id VARCHAR(26) PRIMARY KEY)');
        $pdo->exec('CREATE TABLE sis_application_forms (id VARCHAR(26) PRIMARY KEY, '
            . 'program_id VARCHAR(26) NOT NULL REFERENCES sis_programs(id), name VARCHAR(191), '
            . 'fields_json TEXT, status VARCHAR(16), version INT)');
        $pdo->exec('CREATE TABLE sis_program_forms (id VARCHAR(26) PRIMARY KEY, '
            . 'form_id VARCHAR(26) REFERENCES sis_application_forms(id))');
        $pdo->exec('CREATE TABLE sis_form_submissions (id VARCHAR(26) PRIMARY KEY, '
            . 'form_id VARCHAR(26) REFERENCES sis_application_forms(id), '
            . 'encrypted_answers TEXT, fingerprint VARCHAR(64))');
        $pdo->exec("INSERT INTO sis_programs VALUES ('program')");
        $pdo->exec("INSERT INTO sis_application_forms VALUES "
            . "('form', 'program', 'Earlier form', '[]', 'published', 4)");
        $pdo->exec("INSERT INTO sis_program_forms VALUES ('binding', 'form')");
        $pdo->exec("INSERT INTO sis_form_submissions VALUES "
            . "('receipt', 'form', 'fictional-ciphertext', 'fictional-fingerprint')");
        $before = [];
        foreach (['sis_application_forms', 'sis_program_forms', 'sis_form_submissions'] as $table) {
            $before[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        }
        $m = new AddSisApplicationFormScopes('forms');
        $m->setObjectMap(new ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration')]));
        $m->up();
        foreach ($before as $table => $rows) {
            $columns = $table === 'sis_application_forms' ? 'id, program_id, name, fields_json, status, version' : '*';
            self::assertSame($rows, $pdo->query('SELECT ' . $columns . ' FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC));
        }
        self::assertSame('1', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC));
        $pdo->exec("INSERT INTO sis_catalog_classifications VALUES ('level')");
        $pdo->exec("INSERT INTO sis_application_forms VALUES ('shared', NULL, 'level', 'Shared', '[]', 'draft', 0)");
        $pdo->exec("INSERT INTO sis_level_forms VALUES ('default', 'level', 'shared')");
        $this->expectException(PDOException::class);
        $pdo->exec("INSERT INTO sis_level_forms VALUES ('invalid', 'missing-level', 'shared')");
    }
}
