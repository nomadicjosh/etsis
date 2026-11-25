<?php

declare(strict_types=1);

use Infrastructure\Persistence\Migration\NullableUlidReferences;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class AddSisCatalogHistoryCheckScopes extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Support checks need a database connection.');
        }
        NullableUlidReferences::apply($adapter->connection(), 'person_id', ['sis_history_checks']);
    }

    public function down(): void
    {
        throw new LogicException('Preserve support checks, including non-personal catalog observations.');
    }
}
