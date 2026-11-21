<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class CurriculumReportMigrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/StudentSpecializationRequirementsFixture.php";
    }
    public function testNewProjectionAndCheckpointCompileForFiveDialects(): void
    {
        $f = new \SisFixture();
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $creates = $alters = [];
            $schema = $this->createStub(Schema::class);
            $schema->method("hasTable")->willReturn(false);
            $schema->method("getColumns")->willReturn([]);
            $schema
                ->method("create")
                ->willReturnCallback(static function (
                    string $name,
                    callable $callback
                ) use (
                    &$creates,
                ): void {
                    $t = new CreateTable($name);
                    $callback($t);
                    $creates[] = $t;
                });
            $schema
                ->method("alter")
                ->willReturnCallback(static function (
                    string $name,
                    callable $callback
                ) use (
                    &$alters,
                ): void {
                    $t = new AlterTable($name);
                    $callback($t);
                    $alters[] = $t;
                });
            $connection = $this->createStub(Connection::class);
            $connection->method("getSchema")->willReturn($schema);
            $connection->method("queryBuilder")->willReturn($f->connection->queryBuilder());
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $m = new \CreateSisCurriculumReports("curriculum");
            $m->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter($connection, "fixture"),
                ]),
            );
            $m->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $compiler = new $class($connection);
            $sql = json_encode(
                [
                    ...array_map($compiler->create(...), $creates),
                    ...array_map($compiler->alter(...), $alters),
                ],
                JSON_THROW_ON_ERROR,
            );
            self::assertCount(1, $creates);
            self::assertCount(1, $alters);
            self::assertStringContainsString("component_count", $sql);
            self::assertStringContainsString("parent_major_recorded", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |AUTO_INCREMENT|sis_history_events/i",
                $sql,
            );
        }
    }
    private function fixture(): \StudentSpecializationRequirementsFixture
    {
        $f = new \StudentSpecializationRequirementsFixture();
        $f->select(["DATA", "ART"]);
        $f->assign("DATA");
        return $f;
    }
    private function migrate(\StudentSpecializationRequirementsFixture $f): void
    {
        $m = new \CreateSisCurriculumReports("curriculum");
        $m->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($f->r->sis->connection, "fixture"),
                HistoricalEventStore::class => $f->r->history,
            ]),
        );
        $m->up();
    }
    public function testPopulatedEarlierSelectionsRebuildOnlyVerifiedFactsAndRerunsPreserveHistory(): void
    {
        $f = $this->fixture();
        $s = $f->r->sis;
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_audit",
            "sis_student_specializations",
            "sis_student_curriculum_components",
            "sis_student_specialization_requirements",
        ];
        $before = array_map($s->sql->rows(...), $tables);
        $s->sql->execute("DELETE FROM sis_student_curriculum_components");
        $s->sql->execute("UPDATE sis_student_specializations SET component_count = NULL");
        $this->migrate($f);
        $this->migrate($f);
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
        self::assertCount(
            2,
            $f->service->view($f->student, $f->assignment)["selection"]["specializations"],
        );
    }
    public function testUnverifiableEarlierSelectionStopsConversionWithoutPartialProjectionWrites(): void
    {
        $f = $this->fixture();
        $s = $f->r->sis;
        $s->sql->execute("DELETE FROM sis_student_curriculum_components");
        $s->sql->execute(
            "UPDATE sis_student_specializations SET component_count = NULL, decision_id = ?",
            [\Domain\Shared\ValueObject\EntityId::generate()->value],
        );
        $before = array_map($s->sql->rows(...), [
            "sis_student_specializations",
            "sis_history_events",
            "sis_audit",
        ]);
        try {
            $this->migrate($f);
            self::fail("An unverifiable selection was indexed.");
        } catch (CorruptHistoryException) {
            self::assertSame(
                $before,
                array_map($s->sql->rows(...), [
                    "sis_student_specializations",
                    "sis_history_events",
                    "sis_audit",
                ]),
            );
            self::assertSame([], $s->sql->rows("sis_student_curriculum_components"));
        }
    }
}
