<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;

final class AdmissionChangeRequestMigrationTest extends TestCase
{
    public function testPrivateWorkingRequestsCompileAcrossFiveDialectsWithUlidsAndBoundedReadIndexes(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . "/database/migrations/20261124000004_CreateSisAdmissionChangeRequests.php";
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema
                ->method("create")
                ->willReturnCallback(static function (
                    string $name,
                    callable $callback,
                ) use (&$definitions): void {
                    $table = new CreateTable($name);
                    $callback($table);
                    $definitions[] = $table;
                });
            $connection = $this->createStub(Connection::class);
            $connection->method("getSchema")->willReturn($schema);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(
                    static fn(string $s): string => '"' . $s . '"',
                );
            $migration = new \CreateSisAdmissionChangeRequests(
                "20261124000004",
            );
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter(
                        $connection,
                        "migration",
                    ),
                ]),
            );
            $migration->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(
                new $class($connection)->create($definitions[0]),
                JSON_THROW_ON_ERROR,
            );
            self::assertCount(1, $definitions);
            $terms = [
                    "sis_admission_change_requests",
                    "sealed_data",
                    "sacr_app_page",
                    "sacr_active_area",
            ];
            foreach ($terms as $term) {
                self::assertStringContainsString($term, $sql);
            }
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i",
                $sql,
            );
            self::assertStringNotContainsString("sis_history_events", $sql);
            self::assertMatchesRegularExpression("/(?:CHAR|char).*26/", $sql);
        }
    }

    public function testRepeatedMigrationPreservesPopulatedRequestsAndAllBusinessFactsAndDownRefusesDestruction(): void
    {
        require_once dirname(__DIR__) . "/Support/AdmissionChangeRequestFixture.php";
        $f = new \Tests\Support\AdmissionChangeRequestFixture();
        $s = $f->sis;
        $f->owner()->request(
            $f->application,
            \Domain\Shared\ValueObject\EntityId::generate(),
            "program",
            "Fictional retained request.",
            0,
        );
        $before = [$f->facts(), $s->sql->rows("sis_admission_change_requests")];
        $migration = new \CreateSisAdmissionChangeRequests("20261124000004");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter(
                    $s->connection,
                    "migration",
                ),
            ]),
        );
        $migration->up();
        $migration->up();
        self::assertSame($before, [
            $f->facts(),
            $s->sql->rows("sis_admission_change_requests"),
        ]);
        try {
            $migration->down();
            self::fail("A populated request table was dropped.");
        } catch (\LogicException) {
            self::assertSame($before, [
                $f->facts(),
                $s->sql->rows("sis_admission_change_requests"),
            ]);
        }
    }
}
