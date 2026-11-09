<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisCatalogOperationsTable extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_catalog_operations', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('actor_id', 36)->notNull();
            $table->string('kind', 24)->notNull();
            $table->text('encrypted_content')->size('medium')->notNull();
            $table->string('created_at', 26)->notNull();
            $table->string('published_at', 26);
            $table->index(['kind', 'id'], 'sco_kind_page');
            $table->foreign('actor_id', 'sco_actor_fk')->references('users', 'user_id');
        });
    }

    public function down(): void
    {
        $this->schema()->drop('sis_catalog_operations');
    }
}
