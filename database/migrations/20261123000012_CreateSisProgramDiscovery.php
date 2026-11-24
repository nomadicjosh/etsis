<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisProgramDiscovery extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_program_discovery', clear: true)) {
            $this->schema()->create('sis_program_discovery', self::define(...));
        }
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('catalog_id', 26);
        $t->string('publication_hash', 64);
        $t->integer('visible')->size('tiny')->notNull();
        $t->integer('version')->notNull();
        $t->string('operation_id', 26)->notNull();
        $t->string('request_hash', 64)->notNull();
        $t->string('actor_id', 36)->notNull();
        $t->string('updated_at', 27)->notNull();
        $t->index(['visible', 'id'], 'spdc_public_page');
        $t->foreign('id', 'spdc_program_fk')->references('sis_programs', 'id');
        $t->foreign('catalog_id', 'spdc_catalog_fk')->references('sis_program_catalogs', 'id');
    }

    public function down(): void
    {
        throw new LogicException('Withdraw public catalog listings before removing their visibility policy.');
    }
}
