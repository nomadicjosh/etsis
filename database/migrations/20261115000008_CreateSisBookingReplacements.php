<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisBookingReplacements extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_booking_replacements', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('booking_id', 26)->notNull();
            $t->unique(['booking_id'], 'sbr_booking_uq');
            $t->foreign('id', 'sbr_occurrence_fk')->references('sis_booking_slots', 'id');
            $t->foreign('booking_id', 'sbr_booking_fk')->references('sis_bookings', 'id');
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve the relationship between original meetings and their replacements.');
    }
}
