<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\Compiler\PostgreSQL;

/** PostgreSQL changes type and nullability using separate ALTER COLUMN actions. */
final class PostgresSchemaCompiler extends PostgreSQL
{
    protected function handleModifyColumn(AlterTable $table, $data): string
    {
        $column = 'ALTER COLUMN ' . $this->wrap($data->getName());
        $actions = [$column . ' TYPE ' . trim($this->handleColumnType($data))];
        if (array_key_exists('nullable', $data->getProperties())) {
            $actions[] = $column . ($data->get('nullable') ? ' DROP NOT NULL' : ' SET NOT NULL');
        }
        return 'ALTER TABLE ' . $this->wrap($table->getTableName()) . ' ' . implode(', ', $actions);
    }
}
