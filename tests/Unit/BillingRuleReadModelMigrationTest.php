<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PDO;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;
use SisFixture;

final class BillingRuleReadModelMigrationTest extends TestCase
{
    public function testActualAdditiveDefinitionsCompileAcrossTheFrameworkDatabaseDialects(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        $fixture = new SisFixture();
        foreach (
            ['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite',
            'sqlsrv' => 'SQLServer', 'oci' => 'Oracle'] as $driver => $dialect
        ) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema->method('getColumns')->willReturn(['id', 'term_id', 'program_id']);
            $schema->method('alter')->willReturnCallback(static function (
                string $name,
                callable $callback
            ) use (
                &$definitions,
            ): void {
                $table = new AlterTable($name);
                $callback($table);
                $definitions[] = $table;
            });
            $pdo = $this->createStub(PDO::class);
            $pdo->method('getAttribute')->willReturn($driver);
            $connection = $this->createStub(Connection::class);
            $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
            $connection->method('getSchema')->willReturn($schema);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $s): string => '"' . $s . '"');
            // Empty genuine SQLite data keeps the offline data check separate from SQL compilation.
            $connection->method('queryBuilder')->willReturn($fixture->connection->queryBuilder());
            $migration = new \AddSisBillingRuleReadModel('billing-rule-lookup');
            $migration->setObjectMap(new ArrayObject([
                'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
            ]));
            $migration->up();
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $compiler = new $class($connection);
            $commands = [];
            foreach ($definitions as $definition) {
                array_push($commands, ...$compiler->alter($definition));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            foreach (
                ['name', 'kind', 'amount_minor', 'currency', 'retired', 'position', 'sis_billing_rule_lookup'] as $field
            ) {
                self::assertStringContainsString($field, $sql, $dialect);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |DEFAULT|NOT NULL/i', $sql, $dialect);
            self::assertCount(7, $definitions);
        }
    }
}
