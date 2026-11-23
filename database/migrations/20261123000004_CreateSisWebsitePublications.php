<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final class CreateSisWebsitePublications extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_page_publications', clear: true)) {
            $this->schema()->create('sis_page_publications', static function (CreateTable $t): void {
                $t->string('id', 26)->notNull()->primary();
                $t->string('page_id', 191)->notNull();
                $t->unique('page_id', 'spp_page_unique');
                $t->integer('version')->notNull();
                $t->string('status', 12)->notNull();
                $t->string('source_hash', 64)->notNull();
                $t->string('visible_from', 20);
                $t->string('visible_until', 20);
                $t->text('review_note');
                $t->index(['status', 'page_id'], 'spp_navigation_idx');
            });
        }
        if (!$this->schema()->hasTable('sis_page_publication_operations', clear: true)) {
            $this->schema()->create('sis_page_publication_operations', static function (CreateTable $t): void {
                $t->string('id', 26)->notNull()->primary();
                $t->string('page_id', 191)->notNull();
                $t->string('actor_id', 36)->notNull();
                $t->string('intent', 64)->notNull();
                $t->text('result')->notNull();
            });
        }
        if (!$this->schema()->hasTable('sis_navigation_menus', clear: true)) {
            $this->schema()->create('sis_navigation_menus', static function (CreateTable $t): void {
                $t->string('id', 26)->notNull()->primary();
                $t->string('slot', 12)->notNull();
                $t->unique('slot', 'snm_slot_unique');
                $t->integer('version')->notNull();
                $t->text('draft_items')->size('medium')->notNull();
                $t->text('published_items')->size('medium');
            });
        }
        if (!$this->schema()->hasTable('sis_navigation_operations', clear: true)) {
            $this->schema()->create('sis_navigation_operations', static function (CreateTable $t): void {
                $t->string('id', 26)->notNull()->primary();
                $t->string('slot', 12)->notNull();
                $t->string('actor_id', 36)->notNull();
                $t->string('revision', 64)->notNull();
                $t->text('result')->notNull();
            });
        }
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Website menus require the configured database connection.');
        }
        $sql = new SqlRecords($adapter->connection()->queryBuilder());
        foreach (['primary', 'secondary', 'footer'] as $slot) {
            if ($sql->rows('sis_navigation_menus', ['slot' => $slot], 1) === []) {
                $sql->insert('sis_navigation_menus', ['id' => EntityId::generate()->value,
                    'slot' => $slot, 'version' => 0, 'draft_items' => '[]', 'published_items' => null]);
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve website publication decisions and accepted receipts.');
    }
}
