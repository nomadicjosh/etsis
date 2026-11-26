<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

/** Migration receipts need no generated identity or database RETURNING capability. */
final class DatabaseMigrationAdapter extends DbalMigrationAdapter
{
    public function up(Migration $migration): self
    {
        $q = $this->connection->quoteIdentifier(...);
        $this->connection->query(
            'INSERT INTO ' . $q($this->tableName) . ' (' . $q('version') . ', ' . $q('recorded_on') . ') VALUES (?, ?)',
            [$migration->getVersion(), new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
        return $this;
    }
}
