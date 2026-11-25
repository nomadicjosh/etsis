<?php

declare(strict_types=1);

use Infrastructure\Persistence\Migration\NullableUlidReferences;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class AddSisVirtualPublicEvents extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('A connection-aware migration adapter is required.');
        }
        NullableUlidReferences::apply(
            $adapter->connection(),
            'room_id',
            ['sis_public_events'],
            ['sis_public_event_times']
        );
    }

    public function down(): void
    {
        throw new LogicException('Preserve online event publications; downgrade requires explicit conversion.');
    }
}
