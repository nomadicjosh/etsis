<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisEarnedCeuProjection extends Migration
{
    public function up(): void
    {
        if (!in_array('earned_ceus', $this->schema()->getColumns('sis_academic_credit_streams', clear: true), true)) {
            $this->schema()->alter(
                'sis_academic_credit_streams',
                static fn (AlterTable $table) => $table->text('earned_ceus')
            );
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retained earned CEU projections cannot be dropped.');
    }
}
