<?php

declare(strict_types=1);

use Domain\Communications\Service\MessageCipher;
use Infrastructure\Communications\DefuseMessageCipher;
use Infrastructure\Persistence\AdvisingCheckInStorage;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisAdvisingFollowUpReports extends Migration
{
    public function up(): void
    {
        foreach (["held_on", "due_on", "recorded_on", "responded_on", "resolved_on"] as $field) {
            if (
                !in_array(
                    $field,
                    $this->schema()->getColumns("sis_advising_checkins", clear: true),
                    true,
                )
            ) {
                $this->schema()->alter("sis_advising_checkins", static function (
                    AlterTable $table,
                ) use ($field): void {
                    $table->date($field);
                });
            }
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException(
                "Advising follow-up projections require a database connection.",
            );
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        $q = $sql->quote(...);
        $pending =
        "SELECT * FROM " .
        $q("sis_advising_checkins") .
        " WHERE " .
        $q("held_on") .
        " IS NULL ORDER BY " .
        $q("id");
        // One upgrade invocation handles at most 200 original records. Retry advances the remaining batch.
        // Validate the complete bounded batch before changing a projection; never rewrite sealed originals.
        PortableTransactions::run($db, function () use ($sql, $pending, $q): void {
            $rows = $sql->fetch($pending, [], 201);
            if ($rows === []) {
                return;
            }
            $cipher =
                $this->getObjectMap()[MessageCipher::class] ??
                new DefuseMessageCipher(\Codefy\Framework\Helpers\config());
            $updates = [];
            foreach (array_slice($rows, 0, 200) as $row) {
                $updates[] = [
                    $row,
                    AdvisingCheckInStorage::dates(AdvisingCheckInStorage::open($row, $cipher)),
                ];
            }
            foreach ($updates as [$row, $dates]) {
                $assignments = array_map(
                    static fn(string $key): string => $q($key) . " = ?",
                    array_keys($dates),
                );
                if (
                    $sql->execute(
                        "UPDATE " .
                            $q("sis_advising_checkins") .
                            " SET " .
                            implode(", ", $assignments) .
                            " WHERE " .
                            $q("id") .
                            " = ? AND " .
                            $q("version") .
                            " = ? AND " .
                            $q("held_on") .
                            " IS NULL",
                        [...array_values($dates), $row["id"], (int) $row["version"]],
                    ) !== 1
                ) {
                    throw new LogicException(
                        "Advising check-ins changed during conversion; retry the migration.",
                    );
                }
            }
        });
        if ($sql->fetch($pending, [], 1) !== []) {
            throw new LogicException(
                "One bounded advising date batch was converted. Retry this migration to continue; " .
                "reports stay unavailable until conversion completes.",
            );
        }
        if (!$this->hasIndex()) {
            $this->schema()->alter("sis_advising_checkins", static function (
                AlterTable $table,
            ): void {
                $table->index(["status", "due_on", "id"], "sac_followup_due_idx");
            });
        }
    }

    private function hasIndex(): bool
    {
        $adapter = $this->getAdapter();
        if (
            !(
                $adapter instanceof
                \Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter
            )
        ) {
            throw new LogicException("Advising follow-up indexes require a connection.");
        }
        $pdo = $adapter->connection()->queryBuilder()->getConnection()->pdo;
        $query = match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            "mysql" => "SELECT 1 FROM information_schema.statistics " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            "pgsql"
            => "SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?",
            "sqlite"
            => "SELECT 1 FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
            "sqlsrv" => "SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(?) AND name = ?",
            "oci" => "SELECT 1 FROM user_indexes WHERE table_name = ? AND index_name = ?",
            default => throw new LogicException("Unsupported advising follow-up index dialect."),
        };
        $statement = $pdo->prepare($query);
        $statement->execute(["sis_advising_checkins", "sac_followup_due_idx"]);
        $found = $statement->fetchColumn() !== false;
        $statement->closeCursor();
        return $found;
    }

    public function down(): void
    {
        throw new LogicException(
            "Preserve verified advising follow-up projections and original conversations.",
        );
    }
}
