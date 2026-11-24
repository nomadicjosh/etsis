<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Application\Service\DatabaseService;
use Qubus\Expressive\Connection\Pdo\Pgsql;
use Qubus\Expressive\QueryBuilder;

/** Keep direct connection consumers on the same application query builder as dependency injection. */
final class PostgresConnection extends Pgsql
{
    public function queryBuilder(): QueryBuilder
    {
        return DatabaseService::fromInstance($this);
    }
}
