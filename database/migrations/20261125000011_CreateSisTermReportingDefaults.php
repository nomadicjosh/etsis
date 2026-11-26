<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisTermReportingDefaults extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_term_reporting_defaults', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->foreign('id', 'strd_term_fk')->references('sis_terms', 'id');
            $t->string('reporting_group_id', 26);
            $t->foreign('reporting_group_id', 'strd_group_fk')->references('sis_catalog_classifications', 'id');
            $t->integer('version')->notNull();
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve recorded term reporting defaults; plan disposition explicitly.');
    }
}
