<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Closure;
use Domain\Shared\Persistence\UnitOfWork;
use Qubus\Expressive\Database;

final readonly class DatabaseUnitOfWork implements UnitOfWork
{
    public function __construct(private Database $db)
    {
    }

    public function run(Closure $operation): mixed
    {
        return \Infrastructure\Persistence\PortableTransactions::run($this->db, $operation);
    }
}
