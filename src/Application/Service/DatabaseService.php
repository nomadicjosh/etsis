<?php

declare(strict_types=1);

namespace Application\Service;

use PDO;
use Qubus\Expressive\Connection;
use Qubus\Expressive\QueryBuilder;

class DatabaseService extends QueryBuilder
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection);
        // The installed builder emits RETURNING on PostgreSQL even when its expression is unset.
        // A constant keeps ordinary inserts valid without returning private record contents.
        if ($connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $this->returning('1');
        }
    }

    public static function fromInstance(
        Connection $connection,
        string $primaryKeyName = 'id',
        ?string $tablePrefix = null,
    ): self {
        return new self($connection)
            ->setStructure(primaryKeyName: $primaryKeyName)
            ->setTablePrefix(tablePrefix: $tablePrefix);
    }
}
