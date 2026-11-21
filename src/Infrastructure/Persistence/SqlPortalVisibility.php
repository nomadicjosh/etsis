<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;

/** Shared query-time window, including direct URLs. No publishing worker or academic replay is needed. */
final class SqlPortalVisibility
{
    public static function window(SqlRecords $sql, string $alias, DateTimeImmutable $now): array
    {
        $from = $alias . "." . $sql->quote("visible_from");
        $until = $alias . "." . $sql->quote("visible_until");
        $stamp = $now->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z");
        return [
            "sql" =>
                "(" .
                $from .
                " IS NULL OR " .
                $from .
                " <= ?) AND (" .
                $until .
                " IS NULL OR " .
                $until .
                " > ?)",
            "parameters" => [$stamp, $stamp],
        ];
    }
}
