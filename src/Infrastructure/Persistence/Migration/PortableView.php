<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Migration;

use Qubus\Expressive\Connection;
use PDO;
use LogicException;

final class PortableView
{
    public static function exists(Connection $connection, string $name): bool
    {
        if (preg_match('/^sis_[a-z_]+$/D', $name) !== 1) {
            throw new LogicException("Use a known view identifier.");
        }
        $query = match ($connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            "sqlite"
            => "SELECT name FROM sqlite_master WHERE type='view' AND name=?",
            "pgsql"
            => "SELECT table_name FROM information_schema.views WHERE table_schema=current_schema() AND table_name=?",
            "mysql"
            => "SELECT table_name FROM information_schema.views WHERE table_schema=DATABASE() AND table_name=?",
            "sqlsrv",
            "dblib"
            => "SELECT table_name FROM information_schema.views WHERE table_schema=SCHEMA_NAME() AND table_name=?",
            "oci" => "SELECT VIEW_NAME FROM USER_VIEWS WHERE VIEW_NAME=?",
            default => throw new LogicException(
                "This database requires an explicit view metadata dialect.",
            ),
        };
        $statement = $connection->pdo->prepare($query);
        $statement->execute([$name]);
        $found = $statement->fetchColumn() !== false;
        $statement->closeCursor();
        return $found;
    }
}
