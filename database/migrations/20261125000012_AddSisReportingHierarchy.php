<?php

declare(strict_types=1);

use Domain\Catalog\Repository\ReportingHierarchyRepository;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class AddSisReportingHierarchy extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_reporting_hierarchy", clear: true)) {
            $this->schema()->create("sis_reporting_hierarchy", self::defineHierarchy(...));
        }
        if (!$this->schema()->hasTable("sis_reporting_hierarchy_state", clear: true)) {
            $this->schema()->create("sis_reporting_hierarchy_state", self::defineState(...));
        }
        $columns = $this->schema()->getColumns("sis_section_version_classes", clear: true);
        if (!in_array("reporting_group_ancestors", $columns, true)) {
            $this->schema()->alter("sis_section_version_classes", static function (AlterTable $t): void {
                $t->text("reporting_group_ancestors");
            });
        }
        if (!in_array("reporting_hierarchy_recorded", $columns, true)) {
            $this->schema()->alter("sis_section_version_classes", static function (AlterTable $t): void {
                $t->integer("reporting_hierarchy_recorded")->notNull()->defaultValue(0);
            });
        }
        foreach (["id" => 26, "code" => 40, "title" => 191] as $field => $length) {
            $column = "reporting_root_" . $field;
            if (!in_array($column, $columns, true)) {
                $this->schema()->alter("sis_section_version_classes", static function (AlterTable $t) use (
                    $column,
                    $length,
                ): void {
                    $t->string($column, $length);
                });
            }
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Reporting hierarchy requires a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            if (
                $sql->current("sis_reporting_hierarchy_state", ReportingHierarchyRepository::STATE_ID) ===
                null
            ) {
                $sql->insert("sis_reporting_hierarchy_state", [
                    "id" => ReportingHierarchyRepository::STATE_ID,
                    "version" => 0,
                ]);
            }
        });
    }

    public static function defineHierarchy(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->foreign("id", "srh_group_fk")->references("sis_catalog_classifications", "id");
        $t->string("parent_id", 26);
        $t->foreign("parent_id", "srh_parent_fk")->references("sis_catalog_classifications", "id");
    }

    public static function defineState(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->integer("version")->notNull();
    }

    public function down(): void
    {
        throw new LogicException(
            "Preserve reporting hierarchy configuration and retained publication facts.",
        );
    }
}
