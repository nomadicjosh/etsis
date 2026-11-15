<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class CreateSisBookingCancellations extends Migration
{
    public function up(): void
    {
        $this->schema()->alter('sis_booking_slots', static function (AlterTable $t): void {
            $t->string('cancelled_at', 26);
            $t->string('cancelled_by', 26);
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve recorded meeting cancellations and their attribution.');
    }
}
