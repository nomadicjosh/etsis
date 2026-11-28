<?php

declare(strict_types=1);

use Infrastructure\Persistence\Repository\SqlLedgerControlRepository;
use Infrastructure\Persistence\SqlRecords;
use Domain\Shared\ValueObject\EntityId;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisLedgerControls extends Migration
{
    public function up(): void
    {
        $tables = [
            "sis_gl_gate" => "gate",
            "sis_gl_books" => "books",
            "sis_gl_periods" => "periods",
            "sis_gl_movements" => "movements",
        ];
        foreach ($tables as $table => $method) {
            if (!$this->schema()->hasTable($table, clear: true)) {
                $this->schema()->create($table, [self::class, $method]);
            }
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException(
                "Ledger controls require a database connection.",
            );
        }
        $connection = $adapter->connection();
        $pdo = $connection->pdo;
        $q = $connection->quoteIdentifier(...);
        $query = $pdo->prepare(
            "SELECT " .
                $q("id") .
                " FROM " .
                $q("sis_gl_gate") .
                " WHERE " .
                $q("id") .
                " = ?",
        );
        $query->execute([SqlLedgerControlRepository::GATE]);
        if ($query->fetchColumn() === false) {
            $query = $pdo->prepare(
                "INSERT INTO " .
                    $q("sis_gl_gate") .
                    " (" .
                    $q("id") .
                    ", " .
                    $q("revision") .
                    ") VALUES (?, ?)",
            );
            $query->execute([SqlLedgerControlRepository::GATE, 0]);
        }
        $sql = new SqlRecords($connection->queryBuilder());
        $permission =
        $sql->rows(
            "sis_access_permissions",
            ["name" => "sis:finance:close"],
            1,
        )[0] ?? null;
        if ($permission !== null && (int) $permission["is_system"] !== 1) {
            throw new LogicException(
                "Conflicting ledger period-closing permission.",
            );
        }
        $id = $permission["id"] ?? EntityId::generate()->value;
        if ($permission === null) {
            $sql->insert("sis_access_permissions", [
                "id" => $id,
                "name" => "sis:finance:close",
                "description" =>
                    "Review accounting period closing and reasoned reopening after reconciliation.",
                "is_system" => 1,
            ]);
        }
        if (
            $sql->rows(
                "sis_access_implications",
                ["ancestor_id" => $id, "descendant_id" => $id],
                1,
            ) === []
        ) {
            $sql->insert("sis_access_implications", [
                "id" => EntityId::generate()->value,
                "ancestor_id" => $id,
                "descendant_id" => $id,
            ]);
        }
    }

    public static function gate(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->integer("revision")->notNull();
    }

    public static function books(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("currency", 3)->unique()->notNull();
        $t->integer("position")->notNull();
    }

    public static function periods(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("book_id", 26)->notNull();
        $t->string("starts_on", 10)->notNull();
        $t->string("ends_on", 10)->notNull();
        $t->integer("position")->notNull();
        $t->index(["book_id", "starts_on", "ends_on"], "gl_period_dates");
        $t->foreign("book_id", "gl_period_book")->references(
            "sis_gl_books",
            "id",
        );
    }

    public static function movements(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        foreach (["period_id", "journal_id", "event_id"] as $field) {
            $t->string($field, 26)->notNull();
        }
        $t->string("account_id", 26);
        $t->string("entry_operation", 26);
        $t->string("source_key", 64)->notNull();
        $t->integer("ordinal")->notNull();
        $t->unique(["period_id", "ordinal"], "gl_movement_order");
        $t->unique(["event_id"], "gl_movement_event");
        $t->unique(["source_key"], "gl_movement_source");
        $t->index(["account_id", "entry_operation"], "gl_source_lookup");
        $t->foreign("period_id", "gl_movement_period")->references(
            "sis_gl_periods",
            "id",
        );
        $t->foreign("journal_id", "gl_movement_journal")->references(
            "sis_journals",
            "id",
        );
    }

    public function down(): void
    {
        throw new LogicException(
            "Preserve ledger controls, retained postings and period-closing grants.",
        );
    }
}
