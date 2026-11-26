<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class StudentCreditChoiceMigrationTest extends TestCase
{
    public function testAdditionsCompileForEverySupportedDialect(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261125000010_AddSisStudentCreditChoices.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $tables = [];
            $schema = $this->createStub(Schema::class);
            $schema->method('getColumns')->willReturn([]);
            $schema->method('alter')->willReturnCallback(static function (
                string $name,
                callable $callback
            ) use (&$tables): void {
                $table = new AlterTable($name);
                $callback($table);
                $tables[] = $table;
            });
            $connection = $this->createStub(Connection::class);
            $connection->method('getSchema')->willReturn($schema);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn (string $name): string => '"' . $name . '"'
            );
            $migration = new \AddSisStudentCreditChoices('choices');
            $migration->setObjectMap(new ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter(
                $connection,
                'migration'
            )]));
            $migration->up();
            self::assertCount(4, $tables);
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(array_map(new $compiler($connection)->alter(...), $tables), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('student_selectable_credits', $sql);
            self::assertStringContainsString('selected_credits_milli', $sql);
            self::assertStringContainsString('encrypted_credit_choice', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |UPDATE |INSERT INTO|DELETE FROM/i', $sql);
        }
    }

    public function testOldFactsRemainUnknownAndDraftModeDefaultsToFixedAcrossMigrationRetries(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261125000010_AddSisStudentCreditChoices.php';
        $connection = new Sqlite(['dsn' => 'sqlite::memory:', 'driver' => 'pdo_sqlite']);
        foreach (
            ['sis_sections',
            'sis_section_versions',
            'sis_enrollment_section_versions',
            'sis_waitlist_entries'] as $name
        ) {
            $connection->pdo->exec('CREATE TABLE ' . $name . ' (id VARCHAR(26) PRIMARY KEY, original_value INTEGER)');
            $connection->pdo->exec(
                "INSERT INTO " . $name . " (id, original_value) VALUES ('00000000000000000000000001', 2500)"
            );
        }
        $migration = new \AddSisStudentCreditChoices('choices');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter(
            $connection,
            'migration'
        )]));
        $migration->up();
        $migration->up();
        foreach (
            ['sis_sections' => 'student_selectable_credits', 'sis_section_versions' => 'student_selectable_credits',
            'sis_enrollment_section_versions' => 'selected_credits_milli',
                'sis_waitlist_entries' => 'encrypted_credit_choice'] as $table => $column
        ) {
            $row = $connection->pdo->query('SELECT * FROM ' . $table)->fetch(\PDO::FETCH_ASSOC);
            self::assertSame(2500, $row['original_value']);
            self::assertSame($table === 'sis_sections' ? 0 : null, $row[$column]);
        }
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
