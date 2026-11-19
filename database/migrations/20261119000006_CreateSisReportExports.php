<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisReportExports extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_report_exports', clear: true)) {
            $this->schema()->create('sis_report_exports', static function (CreateTable $t): void {
                $t->string('id', 26)->notNull()->primary();
                $t->string('report_id', 26)->notNull();
                $t->string('actor_id', 26)->notNull();
                $t->integer('definition_version')->notNull();
                $t->string('capture_id', 26);
                $t->string('status', 16)->notNull();
                $t->integer('attempts')->notNull();
                $t->string('lease_id', 26);
                $t->string('lease_until', 25);
                $t->string('created_at', 25)->notNull();
                $t->string('expires_at', 25)->notNull();
                $t->string('started_at', 25);
                $t->string('completed_at', 25);
                $t->integer('row_count');
                $t->integer('byte_count');
                $t->text('encrypted_manifest')->size('medium');
                $t->index(['report_id', 'actor_id', 'id'], 'sre_owner_page');
                $t->index(['status', 'lease_until', 'id'], 'sre_pending');
                $t->index(['expires_at', 'id'], 'sre_expiry');
                $t->foreign('report_id', 'sre_report_fk')->references('sis_reports', 'id');
            });
        }
        if (!$this->schema()->hasTable('sis_report_export_chunks', clear: true)) {
            $this->schema()->create('sis_report_export_chunks', static function (CreateTable $t): void {
                $t->string('id', 26)->notNull()->primary();
                $t->string('export_id', 26)->notNull();
                $t->integer('chunk_number')->notNull();
                $t->text('encrypted_content')->size('big')->notNull();
                $t->unique(['export_id', 'chunk_number'], 'srec_chunk');
                $t->foreign('export_id', 'srec_export_fk')->references('sis_report_exports', 'id');
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Expire report exports before a reviewed schema downgrade.');
    }
}
