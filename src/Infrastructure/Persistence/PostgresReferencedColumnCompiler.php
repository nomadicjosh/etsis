<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\BaseColumn;
use Qubus\Expressive\Schema\Compiler\PostgreSQL;

/** Migration-only inheritance of trusted, database-quoted parent column collations. */
final class PostgresReferencedColumnCompiler extends PostgreSQL
{
    /** @param array<string,string> $collations Database-quoted schema/column collation identifiers. */
    public function __construct(Connection $connection, private readonly array $collations)
    {
        parent::__construct($connection);
    }

    protected function handleTypeString(BaseColumn $column): string
    {
        return parent::handleTypeString($column)
        . (isset($this->collations[$column->getName()])
                ? ' COLLATE ' . $this->collations[$column->getName()] : '');
    }
}
