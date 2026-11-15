<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisBookingWindowIndexes extends Migration
{
    public function up(): void
    {
        $this->schema()->alter('sis_booking_slots', static function (AlterTable $t): void {
            $t->index(['room_id', 'ends_at', 'starts_at'], 'sbs_room_overlap');
            $t->index(['room_id', 'starts_at', 'id'], 'sbs_room_agenda');
        });
    }
    public function down(): void
    {
        // InnoDB can replace its implicit room foreign-key index with either new compound index.
        // Stop before DDL: a downgrade must first establish a replacement supporting index.
        throw new LogicException('Booking index removal requires explicit foreign-key/index planning.');
    }
}
