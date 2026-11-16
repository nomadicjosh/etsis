<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPublicEvents extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_public_events', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('booking_id', 26)->notNull();
            $t->string('room_id', 26)->notNull();
            $t->integer('version')->notNull();
            $t->integer('published')->notNull();
            $t->string('title', 191)->notNull();
            $t->text('description')->notNull();
            $t->string('timezone', 80)->notNull();
            $t->string('room_label', 400)->notNull();
            $t->string('building_label', 400)->notNull();
            $t->string('operation_id', 26)->notNull();
            $t->integer('operation_version')->notNull();
            $t->string('request_hash', 64)->notNull();
            $t->string('published_at', 40)->notNull();
            $t->unique(['booking_id'], 'spe_booking_uq');
            $t->foreign('booking_id', 'spe_booking_fk')->references('sis_bookings', 'id');
            $t->foreign('room_id', 'spe_room_fk')->references('sis_rooms', 'id');
        });
        $this->schema()->create('sis_public_event_times', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('event_id', 26)->notNull();
            $t->string('occurrence_id', 26)->notNull();
            $t->string('starts_at', 32)->notNull();
            $t->string('ends_at', 32)->notNull();
            $t->unique(['event_id', 'occurrence_id'], 'spet_occurrence_uq');
            $t->index(['starts_at', 'ends_at', 'event_id'], 'spet_calendar');
            $t->index(['ends_at', 'starts_at', 'event_id'], 'spet_calendar_end');
            $t->foreign('event_id', 'spet_event_fk')->references('sis_public_events', 'id');
            $t->foreign('occurrence_id', 'spet_slot_fk')->references('sis_booking_slots', 'id');
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve published event records; removal requires explicit retention review.');
    }
}
