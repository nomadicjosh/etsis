<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;
use SisFixture;

final class AdmissionDeferralMigrationTest extends TestCase
{
    public function testOriginalIntakeColumnsCompileAcrossSupportedDialectsWithoutChangingTheEventJournal(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        $f = new SisFixture();
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema->method('getColumns')->willReturn(['id']);
            $schema->method('alter')->willReturnCallback(
                static function (string $name, callable $callback) use (&$definitions): void {
                    $table = new AlterTable($name);
                    $callback($table);
                    $definitions[] = $table;
                }
            );
            $connection = $this->createStub(Connection::class);
            $connection->method('getSchema')->willReturn($schema);
            $connection->method('queryBuilder')->willReturn($f->connection->queryBuilder());
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $migration = new \CreateSisAdmissionDeferral('20261117000008');
            $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
                new DbalMigrationAdapter($connection, 'migration')]));
            $migration->up();
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $commands = new $class($connection)->alter($definitions[0]);
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertCount(1, $definitions);
            self::assertStringContainsString('submitted_term_id', $sql);
            self::assertStringContainsString('submitted_term_code', $sql);
            self::assertStringContainsString('sai_submitted_term', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|AUTO_INCREMENT/i', $sql);
        }
    }
}
