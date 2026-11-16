<?php

declare(strict_types=1);

use Infrastructure\Persistence\Migration\NullableRoomReferences;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class CreateSisRoomFreeMeetings extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('A connection-aware migration adapter is required.');
        }
        NullableRoomReferences::apply(
            $adapter->connection(),
            ['sis_bookings', 'sis_booking_slots'],
            ['sis_booking_replacements']
        );
    }

    public function down(): void
    {
        throw new LogicException('Preserve room-free meeting facts; downgrade needs an explicit conversion.');
    }
}
