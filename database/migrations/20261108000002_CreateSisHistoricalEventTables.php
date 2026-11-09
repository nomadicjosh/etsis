<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisHistoricalEventTables extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_history_streams", clear: true)) {
            $this->schema()->create("sis_history_streams", static function (
                CreateTable $t,
            ): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("stream_type", 191)->notNull();
                $t->string("aggregate_id", 36)->notNull();
                $t->integer("version")->notNull();
                $t->string("head_hash", 64)->notNull();
                $t->text("sealed_anchor")->size("medium")->notNull();
                $t->unique(
                    ["stream_type", "aggregate_id"],
                    "shs_reference_unique",
                );
            });
        }
        if (!$this->schema()->hasTable("sis_history_events", clear: true)) {
            $this->schema()->create("sis_history_events", static function (
                CreateTable $t,
            ): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("stream_id", 26)->notNull();
                // Framework event IDs retain their existing format; row IDs are ULIDs.
                $t->string("event_id", 36)
                    ->unique("she_event_unique")
                    ->notNull();
                $t->integer("position")->notNull();
                $t->string("event_type", 191)->notNull();
                $t->integer("schema_version")->notNull();
                $t->string("recorded_at", 27)->notNull();
                $t->string("previous_hash", 64)->notNull();
                $t->string("event_hash", 64)->notNull();
                $t->text("sealed_event")->size("big")->notNull();
                $t->unique(["stream_id", "position"], "she_stream_position");
                $t->foreign("stream_id", "she_stream_fk")->references(
                    "sis_history_streams",
                    "id",
                );
            });
        }
        if (!$this->schema()->hasTable("sis_history_operations", clear: true)) {
            $this->schema()->create("sis_history_operations", static function (
                CreateTable $t,
            ): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("stream_id", 26)->notNull();
                $t->text("sealed_receipt")->size("medium")->notNull();
                $t->foreign("stream_id", "sho_stream_fk")->references(
                    "sis_history_streams",
                    "id",
                );
            });
        }
        if ($this->schema()->hasTable("event_store", clear: true)) {
            $adapter = $this->getAdapter();
            if (
                !(
                    $adapter instanceof
                    \Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter
                )
            ) {
                throw new LogicException(
                    "An event migration requires a database connection.",
                );
            }
            $db = $adapter->connection()->queryBuilder();
            $sql = new \Infrastructure\Persistence\SqlRecords($db);
            if ($sql->rows("event_store", [], 1) !== []) {
                $codec = $this->getObjectMap()["sis.history_codec"] ?? null;
                if (
                    !(
                        $codec instanceof
                        \Infrastructure\EventSourcing\ProtectedEventCodec
                    )
                ) {
                    throw new LogicException(
                        "Configure the historical event encryption codec before migrating existing events.",
                    );
                }
                new \Infrastructure\EventSourcing\LegacyEventStoreMigration(
                    $db,
                    $codec,
                )->copyAndVerify();
            } elseif ($sql->rows("sis_history_events", [], 1) !== []) {
                throw new LogicException(
                    "An empty legacy source cannot replace an existing event journal.",
                );
            }
            // Offline cutover: only verified records may replace the original event table.
            $this->schema()->drop("event_store");
        }
    }

    public function down(): void
    {
        throw new LogicException(
            "The authoritative event-store conversion cannot be rolled back. Restore a verified backup instead.",
        );
    }
}
