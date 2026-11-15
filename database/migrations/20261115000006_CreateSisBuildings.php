<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisBuildings extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('A connection-aware migration adapter is required.');
        }
        $connection = $adapter->connection();
        $this->schema()->create('sis_buildings', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('code', 80)->notNull();
            $t->string('name', 191)->notNull();
            $t->unique(['code'], 'sb_code_uq');
        });
        $this->schema()->alter('sis_rooms', static function (AlterTable $t): void {
            $t->string('name', 191);
        });
        if ($connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            // SQLite supports a nullable new column with an inline FK, without rebuilding room/booking tables.
            $connection->pdo->exec('ALTER TABLE "sis_rooms" ADD COLUMN "building_id" VARCHAR(26) '
                . 'REFERENCES "sis_buildings" ("id")');
        } else {
            $this->schema()->alter('sis_rooms', static function (AlterTable $t): void {
                $t->string('building_id', 26);
                $t->foreign('building_id', 'sr_building_fk')->references('sis_buildings', 'id');
            });
        }
        $this->schema()->alter('sis_rooms', static function (AlterTable $t): void {
            $t->index(['building_id', 'id'], 'sr_building_idx');
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve room names and building links; downgrade requires an explicit conversion.');
    }
}
