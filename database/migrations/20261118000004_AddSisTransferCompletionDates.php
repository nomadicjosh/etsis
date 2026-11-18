<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisTransferCompletionDates extends Migration
{
    public function up(): void
    {
        if (
            !in_array(
                "corrected_completed_on",
                $this->schema()->getColumns("sis_transfer_awards", clear: true),
                true,
            )
        ) {
            $this->schema()->alter("sis_transfer_awards", static function (AlterTable $table): void {
                $table->string("corrected_completed_on", 10);
            });
        }
    }

    public function down(): void
    {
        throw new LogicException(
            "Retain corrected transfer completion dates and their original award identities.",
        );
    }
}
