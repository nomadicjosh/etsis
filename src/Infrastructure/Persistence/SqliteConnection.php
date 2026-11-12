<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Schema\Compiler;
use Qubus\Expressive\Schema\Compiler\SQLite as SqliteCompiler;

/** Isolate per-table compiler state, including SQLite's inline auto-increment primary-key flag. */
final class SqliteConnection extends Sqlite
{
    public function schemaCompiler(): Compiler
    {
        $compiler = new SqliteCompiler($this);
        $compiler->setOptions($this->schemaCompilerOptions);
        return $compiler;
    }
}
