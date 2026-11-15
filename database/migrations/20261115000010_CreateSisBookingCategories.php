<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisBookingCategories extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('A connection-aware migration adapter is required.');
        }
        $this->schema()->create('sis_booking_categories', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('code', 80)->notNull();
            $t->string('name', 191)->notNull();
            $t->string('tone', 6)->notNull();
            $t->boolean('active')->notNull()->defaultValue(1);
            $t->integer('version')->notNull()->defaultValue(0);
            $t->unique(['code'], 'sbc_code_uq');
        });
        if ($adapter->connection()->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            // Add the nullable FK inline; preserve existing bookings without rebuilding their table.
            $adapter->connection()->command('ALTER TABLE "sis_bookings" ADD COLUMN "category_id" VARCHAR(26) '
                . 'REFERENCES "sis_booking_categories" ("id")');
        } else {
            $this->schema()->alter('sis_bookings', static function (AlterTable $t): void {
                $t->string('category_id', 26);
                $t->foreign('category_id', 'sb_category_fk')->references('sis_booking_categories', 'id');
            });
        }
        $this->schema()->alter('sis_bookings', static function (AlterTable $t): void {
            $t->index(['category_id', 'id'], 'sb_category_idx');
            $t->string('request_hash', 64);
        });
        if ($adapter->connection()->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $adapter->connection()->command('ALTER TABLE "sis_bookings" '
                . 'ADD COLUMN "section_publication_id" VARCHAR(26) '
                . 'REFERENCES "sis_section_versions" ("id")');
        } else {
            $this->schema()->alter('sis_bookings', static function (AlterTable $t): void {
                $t->string('section_publication_id', 26);
                $t->foreign('section_publication_id', 'sb_section_pub_fk')->references('sis_section_versions', 'id');
            });
        }
        $this->schema()->alter('sis_bookings', static function (AlterTable $t): void {
            $t->index(['section_publication_id', 'id'], 'sb_section_pub_idx');
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve booking categories and their links;'
            . ' downgrade requires explicit conversion.');
    }
}
