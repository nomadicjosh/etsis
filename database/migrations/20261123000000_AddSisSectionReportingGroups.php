<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisSectionReportingGroups extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException('Section reporting groups require a database connection.');
        }
        $table = 'sis_section_classifications';
        if (!in_array('reporting_group_id', $this->schema()->getColumns($table, clear: true), true)) {
            if ($adapter->connection()->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $adapter->connection()->command(
                    'ALTER TABLE "sis_section_classifications" ADD COLUMN "reporting_group_id" '
                    . 'VARCHAR(26) REFERENCES "sis_catalog_classifications" ("id")',
                );
            } else {
                $this->schema()->alter($table, static function (AlterTable $t): void {
                    $t->string('reporting_group_id', 26);
                    $t->foreign('reporting_group_id', 'ssc_reporting_group_fk')->references(
                        'sis_catalog_classifications',
                        'id',
                    );
                });
            }
        }
        $table = 'sis_section_version_classes';
        foreach (['id' => 26, 'code' => 40, 'title' => 191] as $suffix => $length) {
            $column = 'reporting_group_' . $suffix;
            if (!in_array($column, $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t) use ($column, $length): void {
                    $t->string($column, $length);
                });
            }
        }
        if (!in_array('reporting_group_recorded', $this->schema()->getColumns($table, clear: true), true)) {
            $this->schema()->alter($table, static function (AlterTable $t): void {
                // Earlier publications never recorded this relationship; preserve that unknown.
                $t->integer('reporting_group_recorded')->notNull()->defaultValue(0);
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve retained reporting groups and unknown earlier relationships.');
    }
}
