<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisProgramClassifications extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Program classification setup requires a database connection.');
        }
        $sqlite = $adapter->connection()->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        foreach (['school', 'field_of_study'] as $kind) {
            $columns = $this->schema()->getColumns('sis_program_details', clear: true);
            if (!in_array($kind . '_id', $columns, true)) {
                if ($sqlite) {
                    // SQLite supports a nullable inline reference, preserving existing rows without a rebuild.
                    $adapter->connection()->command('ALTER TABLE "sis_program_details" ADD COLUMN "' . $kind
                        . '_id" VARCHAR(26) REFERENCES "sis_catalog_classifications" ("id")');
                } else {
                    $this->schema()->alter('sis_program_details', static function (AlterTable $t) use ($kind): void {
                        $t->string($kind . '_id', 26);
                        $t->foreign($kind . '_id', 'spd_' . $kind . '_fk')->references(
                            'sis_catalog_classifications',
                            'id'
                        );
                    });
                }
            }
            foreach (['id' => 26, 'code' => 40, 'title' => 191] as $suffix => $length) {
                $column = $kind . '_' . $suffix;
                if (!in_array($column, $this->schema()->getColumns('sis_program_catalog_details', clear: true), true)) {
                    $this->schema()->alter('sis_program_catalog_details', static function (
                        AlterTable $t,
                    ) use (
                        $column,
                        $length
): void {
                        $t->string($column, $length);
                    });
                }
            }
        }
        $columns = $this->schema()->getColumns('sis_program_catalog_details', clear: true);
        if (!in_array('school_field_recorded', $columns, true)) {
            $this->schema()->alter('sis_program_catalog_details', static function (AlterTable $t): void {
                // Earlier publications did not capture these fields; never infer them from current setup.
                $t->integer('school_field_recorded')->notNull()->defaultValue(0);
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve program classification snapshots and their recorded/unknown distinction.');
    }
}
