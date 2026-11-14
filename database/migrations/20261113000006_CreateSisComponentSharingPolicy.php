<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class CreateSisComponentSharingPolicy extends Migration
{
    public function up(): void
    {
        $columns = $this->schema()->getColumns('sis_specialization_policies', true);
        if (!is_array($columns)) {
            throw new LogicException('Inspect the specialization policy schema before migration.');
        }
        if (!in_array('component_sharing', $columns, true)) {
            $this->schema()->alter('sis_specialization_policies', static function (AlterTable $t): void {
                $t->string('component_sharing', 24);
            });
        }
    }
    public function down(): void
    {
        throw new LogicException('Preserve the program sharing settings and retained publication evidence.');
    }
}
