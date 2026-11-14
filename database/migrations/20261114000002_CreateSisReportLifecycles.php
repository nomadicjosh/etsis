<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisReportLifecycles extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_report_lifecycles', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('mode', 12)->notNull();
            $table->string('expires_at', 26);
            $table->string('removed_at', 26);
            $table->string('captured_at', 26);
            $table->string('capture_id', 26);
            $table->text('encrypted_manifest')->size('medium');
            $table->index(['removed_at', 'expires_at'], 'srl_expiry');
            $table->foreign('id', 'srl_report_fk')->references('sis_reports', 'id');
        });
        $this->schema()->create('sis_report_snapshot_pages', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('report_id', 26)->notNull();
            $table->integer('page')->notNull();
            $table->text('encrypted_content')->size('long')->notNull();
            $table->unique(['report_id', 'page'], 'srsp_report_page');
            $table->foreign('report_id', 'srsp_report_fk')->references('sis_reports', 'id');
        });
    }
    public function down(): void
    {
        throw new LogicException('Retained report captures require explicit disposition before removing their schema.');
    }
}
