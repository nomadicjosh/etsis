<?php

declare(strict_types=1);

use Infrastructure\Persistence\Migration\HistoryCheckSubjectWidth;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class ExpandSisHistoryCheckSubjects extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('A connection-aware migration adapter is required.');
        }
        HistoryCheckSubjectWidth::apply($adapter->connection());
    }

    public function down(): void
    {
        throw new LogicException('Preserve support routing; narrowing could truncate recorded subjects.');
    }
}
