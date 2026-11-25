<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;

final class StandingProposalMigrationTest extends TestCase
{
    public function testTechnicalStateCompilesAcrossFiveDialectsWithoutBusinessDefaultsOrCollation(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261125000003_AddSisStandingProposalPolicy.php';
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $n): string => '"' . $n . '"');
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_standing_proposal_policy_state');
            \AddSisStandingProposalPolicy::define($table);
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('PRIMARY KEY', $sql);
            self::assertStringContainsString('version', $sql);
            self::assertDoesNotMatchRegularExpression('/COLLATE|DROP |DELETE FROM|INSERT INTO|AUTO_INCREMENT/i', $sql);
        }
    }

    public function testFirstApplicationAndRerunPreserveRecordedFactsAndNonzeroState(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261125000003_AddSisStandingProposalPolicy.php';
        $c = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $c->pdo->exec("CREATE TABLE original_facts (fact TEXT NOT NULL)");
        $c->pdo->exec("INSERT INTO original_facts VALUES ('Fictional protected original')");
        $migration = new \AddSisStandingProposalPolicy('20261125000003');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter($c, 'migration')]));
        $migration->up();
        $state = 'SELECT version FROM sis_standing_proposal_policy_state';
        self::assertSame(0, (int) $c->pdo->query($state)->fetchColumn());
        $c->pdo->exec('UPDATE sis_standing_proposal_policy_state SET version = 7');
        $migration->up();
        self::assertSame(7, (int) $c->pdo->query($state)->fetchColumn());
        self::assertSame(
            'Fictional protected original',
            $c->pdo->query('SELECT fact FROM original_facts')->fetchColumn()
        );
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
