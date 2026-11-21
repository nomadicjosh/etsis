<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisChargeSourceReports extends Migration
{
    public function up(): void
    {
        if (!in_array('charge_source', $this->schema()->getColumns('sis_account_entries', clear: true), true)) {
            $this->schema()->alter('sis_account_entries', static function (AlterTable $table): void {
                $table->string('charge_source', 24);
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve recorded charge-source projection evidence.');
    }
}
