<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use DateTimeImmutable;
use Domain\Gradebook\Assignment;
use Domain\Gradebook\AssignmentScore;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlGradebookRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class ScoreExcusalMigrationTest extends TestCase
{
    public function testDefaultIncludedScoreCompilesForFiveFrameworkDialects(): void
    {
        require_once dirname(__DIR__, 2) . "/database/migrations/20261118000002_AddSisScoreExcusals.php";
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema->method("getColumns")->willReturn([]);
            $schema
                ->method("alter")
                ->willReturnCallback(static function (
                    string $name,
                    callable $callback
                ) use (
                    &$definitions,
                ): void {
                    $table = new AlterTable($name);
                    $callback($table);
                    $definitions[] = $table;
                });
            $connection = $this->createStub(Connection::class);
            $connection->method("getSchema")->willReturn($schema);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $migration = new \AddSisScoreExcusals("20261118000002");
            $migration->setObjectMap(
                new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($connection, "migration")]),
            );
            $migration->up();
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(new $compiler($connection)->alter($definitions[0]), JSON_THROW_ON_ERROR);
            self::assertCount(1, $definitions);
            self::assertStringContainsString("excused", $sql);
            self::assertStringContainsString("NOT NULL", $sql);
            self::assertMatchesRegularExpression("/DEFAULT.*0/i", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|INSERT INTO|sis_history_events/i",
                $sql,
            );
        }
    }

    public function testRerunsPreserveScoresExistingLimitsEventsAndAccessGrants(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new \SisFixture();
        $section = $f->section();
        $repository = new SqlGradebookRepository($f->sql);
        $assignment = new Assignment(
            EntityId::generate(),
            $section->id,
            "Fictional bonus task",
            1000,
            new DateTimeImmutable("2026-09-01"),
            200,
        );
        $repository->addAssignment($assignment);
        $repository->score(new AssignmentScore($assignment->id, $f->student()->id, 1100, 1000, 200, true), 0);
        $tables = [
            "sis_assignments",
            "sis_scores",
            "sis_history_events",
            "sis_academic_credits",
            "sis_access_role_permissions",
            "sis_access_user_roles",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        $migration = new \AddSisScoreExcusals("20261118000002");
        $migration->setObjectMap(
            new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($f->connection, "migration")]),
        );
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        self::assertSame(200, $repository->assignment($assignment->id)->bonusHundredths);
        self::assertSame(1, $f->sql->rows("sis_scores")[0]["excused"]);
    }
}
