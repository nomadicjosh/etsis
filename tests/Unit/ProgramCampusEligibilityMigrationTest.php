<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;

final class ProgramCampusEligibilityMigrationTest extends TestCase
{
    public function testPolicyTableCompilesAcrossFiveDialectsWithoutDriverSpecificCollation(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261125000002_AddSisProgramCampusEligibility.php';
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $n): string => '"' . $n . '"');
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $t = new CreateTable('sis_section_program_policies');
            \AddSisProgramCampusEligibility::define($t);
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($t), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('PRIMARY KEY', $sql);
            self::assertStringContainsString('require_program_campus', $sql);
            self::assertStringContainsString('sis_sections', $sql);
            self::assertStringNotContainsString('COLLATE', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|INSERT INTO|AUTO_INCREMENT/i', $sql);
        }
    }

    public function testPopulatedFirstApplicationAndRerunPreserveEarlierFactsAndUnknownPolicy(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261125000002_AddSisProgramCampusEligibility.php';
        $c = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $c->pdo->exec('CREATE TABLE sis_sections (id VARCHAR(26) PRIMARY KEY)');
        $c->pdo->exec('CREATE TABLE sis_section_versions (id VARCHAR(26) PRIMARY KEY, original_fact TEXT NOT NULL)');
        $id = \Domain\Shared\ValueObject\EntityId::generate()->value;
        $c->pdo->prepare('INSERT INTO sis_sections VALUES (?)')->execute([$id]);
        $c->pdo->prepare('INSERT INTO sis_section_versions VALUES (?, ?)')->execute([$id, 'Fictional retained fact']);
        $migration = new \AddSisProgramCampusEligibility('20261125000002');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter($c, 'migration')]));
        $migration->up();
        $migration->up();
        $rows = $c->pdo->query('SELECT * FROM sis_section_versions')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(
            [['id' => $id, 'original_fact' => 'Fictional retained fact', 'require_program_campus' => null]],
            $rows,
        );
        self::assertSame(0, (int) $c->pdo->query('SELECT COUNT(*) FROM sis_section_program_policies')->fetchColumn());
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
