<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisComponentExclusions extends Migration
{
    public function up(): void
    {
        $columns = $this->schema()->getColumns('sis_specialization_policies', clear: true);
        if (!in_array('component_exclusions', $columns, true)) {
            $this->schema()->alter('sis_specialization_policies', static function (AlterTable $t): void {
                $t->text('component_exclusions');
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve recorded curriculum-choice policies; change drafts explicitly.');
    }
}
