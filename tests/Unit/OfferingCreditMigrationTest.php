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

final class OfferingCreditMigrationTest extends TestCase
{
    public function testRetainedPolicyColumnsCannotBeDropped(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261123000003_AddSisOfferingCreditPolicies.php';
        $this->expectException(\RuntimeException::class);
        new \AddSisOfferingCreditPolicies('credits')->down();
    }

    public function testNullablePoliciesAndOfferingChoicesCompileForFiveDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261123000003_AddSisOfferingCreditPolicies.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema->method('getColumns')->willReturn([]);
            $schema->method('alter')->willReturnCallback(static function (
                string $name,
                callable $callback,
            ) use (&$definitions): void {
                $table = new AlterTable($name);
                $callback($table);
                $definitions[] = $table;
            });
            $connection = $this->createStub(Connection::class);
            $connection->method('getSchema')->willReturn($schema);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn (string $name): string => '"' . $name . '"',
            );
            $migration = new \AddSisOfferingCreditPolicies('credits');
            $migration->setObjectMap(new ArrayObject([
                'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
            ]));
            $migration->up();
            self::assertCount(4, $definitions);
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(array_map(new $class($connection)->alter(...), $definitions), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('credit_policy', $sql);
            self::assertStringContainsString('offering_credits_milli', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |UPDATE |INSERT INTO|DELETE FROM|DEFAULT/i', $sql);
        }
    }

    public function testPopulatedOldRowsKeepTheirValuesAndNewFieldsRemainUnrecordedAcrossRetries(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261123000003_AddSisOfferingCreditPolicies.php';
        $connection = new Sqlite(['dsn' => 'sqlite::memory:', 'driver' => 'pdo_sqlite']);
        foreach (['sis_courses', 'sis_course_versions', 'sis_sections', 'sis_section_versions'] as $table) {
            $connection->pdo->exec('CREATE TABLE ' . $table . ' (id VARCHAR(26) PRIMARY KEY, original_value INTEGER)');
            $connection->pdo->exec('INSERT INTO ' . $table .
                " (id, original_value) VALUES ('00000000000000000000000001', 3500)");
        }
        $migration = new \AddSisOfferingCreditPolicies('credits');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
        ]));
        $migration->up();
        $migration->up();
        foreach (
            ['sis_courses' => 'credit_policy', 'sis_course_versions' => 'credit_policy',
            'sis_sections' => 'offering_credits_milli', 'sis_section_versions' => 'credits_milli'] as $table => $field
        ) {
            $row = $connection->pdo->query('SELECT * FROM ' . $table)->fetch(\PDO::FETCH_ASSOC);
            self::assertSame(3500, $row['original_value']);
            self::assertNull($row[$field]);
        }
    }
}
