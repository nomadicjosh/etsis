<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisComponentMajorLinks extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Component-major links require a database connection.");
        }
        if (
            in_array(
                "parent_major_id",
                $this->schema()->getColumns("sis_program_specializations", clear: true),
                true,
            )
        ) {
            return;
        }
        if ($adapter->connection()->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
            $adapter
                ->connection()
                ->command(
                    'ALTER TABLE "sis_program_specializations" ADD COLUMN "parent_major_id" ' .
                        'VARCHAR(26) REFERENCES "sis_program_specializations" ("id")',
                );
        } else {
            $this->schema()->alter("sis_program_specializations", static function (
                AlterTable $t,
            ): void {
                $t->string("parent_major_id", 26);
                $t->foreign("parent_major_id", "sps_major_fk")->references(
                    "sis_program_specializations",
                    "id",
                );
            });
        }
    }
    public function down(): void
    {
        throw new LogicException(
            "Preserve component-major links and their published historical facts.",
        );
    }
}
