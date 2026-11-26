<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisBackgroundImports extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_import_jobs", static function (
            CreateTable $t,
        ): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("owner_user_id", 36)->notNull();
            $t->foreign("owner_user_id", "sij_owner_fk")->references(
                "users",
                "user_id",
            );
            $t->string("target", 32)->notNull();
            $t->string("state", 24)->notNull();
            $t->string("review_hash", 64)->notNull();
            $iterationValues = [
                "total",
                "processed",
                "imported",
                "linked",
                "skipped",
                "omitted",
                "revision",
                ];
            foreach ($iterationValues as $column) {
                $t->integer($column)->notNull();
            }
            $t->string("created_at", 26)->notNull();
            $t->string("expires_at", 26)->notNull();
            $t->string("submitted_at", 26);
            $t->string("finished_at", 26);
            $t->string("lease_token", 32);
            $t->string("lease_until", 26);
            $t->string("error_code", 24);
            $t->index(["state", "lease_until", "id"], "sij_work");
            $t->index(["owner_user_id", "id"], "sij_owner_page");
        });
        $this->schema()->create("sis_import_artifacts", static function (
            CreateTable $t,
        ): void {
            $t->string("id", 26)->primary()->notNull();
            $t->foreign("id", "sia_job_fk")->references(
                "sis_import_jobs",
                "id",
            );
            $t->text("encrypted_payload")->size("medium")->notNull();
            $t->string("payload_hash", 64)->notNull();
        });
        $this->schema()->create("sis_import_notices", static function (
            CreateTable $t,
        ): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("owner_user_id", 36)->notNull();
            $t->foreign("owner_user_id", "sin_owner_fk")->references(
                "users",
                "user_id",
            );
            $t->string("job_id", 26)->notNull();
            $t->foreign("job_id", "sin_job_fk")->references(
                "sis_import_jobs",
                "id",
            );
            $t->string("target", 32)->notNull();
            $t->string("state", 24)->notNull();
            $t->integer("processed")->notNull();
            $t->integer("total")->notNull();
            $t->string("recorded_at", 26)->notNull();
            $t->string("read_at", 26);
            $t->index(["owner_user_id", "id"], "sin_owner_page");
        });
    }
    public function down(): void
    {
        throw new LogicException(
            "Preserve submitted import jobs and operation receipts; dispose of private artifacts deliberately.",
        );
    }
}
