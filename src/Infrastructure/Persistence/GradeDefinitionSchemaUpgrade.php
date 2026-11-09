<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use LogicException;
use PDO;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;
use Throwable;

/** Offline schema cutover. Existing grade IDs and their referring foreign keys stay intact. */
final readonly class GradeDefinitionSchemaUpgrade
{
    public function __construct(private Connection $connection)
    {
    }
    public static function columns(AlterTable $t): void
    {
        $t->string("scale_id", 26);
        $t->string("scale_version_id", 26);
        $t->integer("min_score_milli");
        $t->integer("max_score_milli");
        $t->integer("ordinal");
    }
    public static function table(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("code", 12)->notNull();
        $t->integer("points_milli")->notNull();
        $t->integer("passes")->notNull();
        $t->integer("counts_gpa")->notNull();
        $t->string("scale_id", 26);
        $t->string("scale_version_id", 26);
        $t->integer("min_score_milli");
        $t->integer("max_score_milli");
        $t->integer("ordinal");
        $t->unique(["scale_version_id", "code"], "sg_version_code");
        $t->index(["scale_id", "scale_version_id", "ordinal"], "sg_scale_version");
        $t->foreign("scale_id", "sg_scale_fk")->references("sis_grading_scales", "id");
    }
    public function run(): void
    {
        $schema = $this->connection->getSchema();
        $columns = $schema->getColumns("sis_grades", true);
        if (!is_array($columns)) {
            throw new LogicException("The existing grade table is required.");
        }
        $sqlite = $this->connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite";
        if ($sqlite) {
            if (!in_array("scale_version_id", $columns, true)) {
                $this->sqlite();
            }
            return;
        }
        foreach (["scale_id", "scale_version_id", "min_score_milli", "max_score_milli", "ordinal"] as $name) {
            if (!in_array($name, $columns, true)) {
                $schema->alter("sis_grades", static function (AlterTable $t) use ($name): void {
                    if (in_array($name, ["scale_id", "scale_version_id"], true)) {
                        $t->string($name, 26);
                    } else {
                        $t->integer($name);
                    }
                });
            }
        }
        if ($this->constraint("sis_w2_u0")) {
            $schema->alter("sis_grades", static function (AlterTable $t): void {
                $t->dropUnique("sis_w2_u0");
            });
        }
        if (!$this->constraint("sg_version_code")) {
            $schema->alter("sis_grades", static function (AlterTable $t): void {
                $t->unique(["scale_version_id", "code"], "sg_version_code");
            });
        }
        if (!$this->constraint("sg_scale_fk")) {
            $schema->alter("sis_grades", static function (AlterTable $t): void {
                $t->foreign("scale_id", "sg_scale_fk")->references("sis_grading_scales", "id");
            });
        }
        // The unique publication/code key also supports publication grade reads.
    }
    private function constraint(string $name): bool
    {
        $driver = $this->connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = match ($driver) {
            "mysql" => "SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() " .
            "AND table_name = 'sis_grades' AND constraint_name = ?",
            "pgsql"
            => "SELECT 1 FROM information_schema.table_constraints WHERE table_schema = current_schema() " .
            "AND table_name = 'sis_grades' AND constraint_name = ?",
            "sqlsrv",
            "dblib"
            => "SELECT 1 FROM sys.objects WHERE parent_object_id = OBJECT_ID('sis_grades') AND name = ?",
            "oci" => "SELECT 1 FROM user_constraints WHERE table_name = 'sis_grades' AND constraint_name = ?",
            default => throw new LogicException(
                "Use one of the five supported database migration adapters.",
            ),
        };
        $statement = $this->connection->pdo->prepare($sql);
        if ($statement === false) {
            throw new LogicException("Unable to inspect grade constraints.");
        }
        $statement->execute([$name]);
        return $statement->fetchColumn() !== false;
    }
    private function sqlite(): void
    {
        $pdo = $this->connection->pdo;
        if ($pdo->inTransaction()) {
            throw new LogicException("Run the SQLite grade cutover outside an existing transaction.");
        }
        $objects = $this->query(
            "SELECT name FROM sqlite_master WHERE tbl_name = 'sis_grades' " .
                "AND type IN ('trigger', 'index') AND sql IS NOT NULL",
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($objects !== []) {
            throw new LogicException("Review custom grade-table indexes/triggers before the SQLite cutover.");
        }
        $foreignKeys = (int) $this->query("PRAGMA foreign_keys")->fetchColumn();
        $pdo->exec("PRAGMA foreign_keys = OFF");
        try {
            if (!$pdo->beginTransaction()) {
                throw new LogicException("Unable to begin the grade schema cutover.");
            }
            $schema = $this->connection->getSchema();
            $schema->create("sis_grades_upgrade", self::table(...));
            $fields = "id, code, points_milli, passes, counts_gpa";
            $pdo->exec(
                "INSERT INTO sis_grades_upgrade (" . $fields . ") SELECT " . $fields . " FROM sis_grades",
            );
            if (
                $this->query(
                    "SELECT " .
                        $fields .
                        " FROM sis_grades EXCEPT SELECT " .
                        $fields .
                        " FROM sis_grades_upgrade",
                )->fetch() !== false
            ) {
                throw new CorruptHistoryException();
            }
            $schema->drop("sis_grades");
            // SQLite does not support the installed compiler's RENAME TABLE statement.
            $pdo->exec("ALTER TABLE sis_grades_upgrade RENAME TO sis_grades");
            if ($this->query("PRAGMA foreign_key_check")->fetch() !== false) {
                throw new CorruptHistoryException();
            }
            if (!$pdo->commit()) {
                throw new LogicException("Unable to commit the grade schema cutover.");
            }
        } catch (Throwable $error) {
            $this->rollbackIfActive($pdo);
            throw $error;
        } finally {
            $pdo->exec("PRAGMA foreign_keys = " . ($foreignKeys === 1 ? "ON" : "OFF"));
        }
    }
    private function query(string $sql): \PDOStatement
    {
        $statement = $this->connection->pdo->query($sql);
        if ($statement === false) {
            throw new LogicException("Unable to verify the grade schema cutover.");
        }
        return $statement;
    }
    private function rollbackIfActive(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
