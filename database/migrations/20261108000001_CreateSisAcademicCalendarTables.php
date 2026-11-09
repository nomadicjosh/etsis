<?php

declare(strict_types=1);

use Qubus\Exception\Exception;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAcademicCalendarTables extends Migration
{
    /**
     * @throws Exception
     */
    public function up(): void
    {
        $this->schema()->create("sis_academic_calendar", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->integer("version")->notNull();
        });
        $this->schema()->create("sis_term_types", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("code", 22)->unique("stt_code_unique")->notNull();
            $t->integer("version")->notNull();
            $t->integer("active")->notNull();
            $t->text("definition")->notNull();
            $t->index(["active", "id"], "stt_active_page");
        });
        $this->schema()->create("sis_academic_years", static function (CreateTable $t): void {
            // The same stable ID anchors the internal derived cohort reference.
            $t->string("id", 26)->primary()->notNull();
            $t->integer("start_year")->unique("say_start_unique")->notNull();
            $t->integer("end_year")->notNull();
            $t->string("code", 9)->unique("say_code_unique")->notNull();
            $t->string("starts_on", 10)->notNull();
            $t->string("ends_on", 10)->notNull();
            $t->integer("legacy")->notNull();
            $t->foreign("id", "say_derived_cohort_fk")->references("sis_catalog_years", "id");
        });
        $this->schema()->create("sis_term_calendar", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("academic_year_id", 26);
            $t->string("term_type_id", 26);
            $t->integer("term_type_version");
            $t->string("kind", 24)->notNull();
            $t->string("name", 120)->notNull();
            $t->integer("version")->notNull();
            $t->index(["academic_year_id", "id"], "stc_year_page");
            $t->foreign("id", "stc_term_fk")->references("sis_terms", "id");
            $t->foreign("academic_year_id", "stc_year_fk")->references("sis_academic_years", "id");
            $t->foreign("term_type_id", "stc_type_fk")->references("sis_term_types", "id");
        });
        $this->schema()->create("sis_academic_year_actions", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("year_id", 26)->notNull();
            $t->string("actor_id", 36)->notNull();
            $t->string("request_hash", 64)->notNull();
            $t->text("encrypted_content")->size("medium")->notNull();
            $t->string("created_at", 26)->notNull();
            $t->foreign("year_id", "saya_year_fk")->references("sis_academic_years", "id");
            $t->foreign("actor_id", "saya_actor_fk")->references("users", "user_id");
        });
        $db = new \Infrastructure\Persistence\SqlRecords($this->schemaConnection()->queryBuilder());
        $db->insert("sis_academic_calendar", ["id" => "00000000000000000000000001", "version" => 0]);
    }

    private function schemaConnection(): \Qubus\Expressive\Connection
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof \Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter)) {
            throw new LogicException("A connection-aware adapter is required.");
        }
        return $adapter->connection();
    }

    public function down(): void
    {
        $tables = [
            "sis_academic_year_actions",
            "sis_term_calendar",
            "sis_academic_years",
            "sis_term_types",
            "sis_academic_calendar",
        ];
        foreach ($tables as $table) {
            $this->schema()->drop($table);
        }
    }
}
