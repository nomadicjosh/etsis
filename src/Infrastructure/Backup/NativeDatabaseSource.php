<?php

declare(strict_types=1);

namespace Infrastructure\Backup;

use RuntimeException;

/** Native consistent logical exports, streamed directly into Restic without a plaintext dump file. */
final readonly class NativeDatabaseSource
{
    public function __construct(
        #[\SensitiveParameter] private array $connection,
        private array $tools,
    ) {
    }

    public function driver(): string
    {
        $dsn = $this->connection["dsn"] ?? "";
        return match (true) {
            is_string($dsn) && str_starts_with($dsn, "pgsql:") => "pgsql",
            is_string($dsn) && str_starts_with($dsn, "sqlite:") => "sqlite",
            default => throw new RuntimeException(
                "This database driver requires a separately qualified backup adapter.",
            ),
        };
    }

    /** @return array{command:list<string>,environment:array<string,string>} */
    public function export(): array
    {
        $driver = $this->driver();
        $binary = $this->tools[$driver] ?? null;
        if (!is_string($binary) || !is_executable($binary)) {
            throw new RuntimeException(
                "The native database export tool is unavailable.",
            );
        }
        if ($driver === "sqlite") {
            $path = substr($this->connection["dsn"], 7);
            if (
                !str_starts_with($path, "/") ||
                realpath($path) !== $path ||
                !is_file($path)
            ) {
                throw new RuntimeException(
                    "Use an existing local SQLite database.",
                );
            }
            return [
                "command" => [$binary, "-batch", "-readonly", $path, ".dump"],
                "environment" => [],
            ];
        }
        $parts = [];
        foreach (explode(";", substr($this->connection["dsn"], 6)) as $part) {
            if ($part === "") {
                continue;
            }
            $pair = explode("=", $part, 2);
            if (
                count($pair) !== 2 ||
                isset($parts[$pair[0]]) ||
                !in_array(
                    $pair[0],
                    ["host", "port", "dbname", "sslmode"],
                    true,
                ) ||
                $pair[1] === "" ||
                preg_match('/[\x00-\x20\x7f]/', $pair[1]) === 1
            ) {
                throw new RuntimeException(
                    "Use the supported explicit PostgreSQL DSN fields.",
                );
            }
            $parts[$pair[0]] = $pair[1];
        }
        if (
            !isset($parts["host"], $parts["dbname"]) ||
            (isset($parts["port"]) &&
                (preg_match('/^[0-9]{1,5}$/D', $parts["port"]) !== 1 ||
                    (int) $parts["port"] < 1 ||
                    (int) $parts["port"] > 65535)) ||
            (isset($parts["sslmode"]) &&
                !in_array(
                    $parts["sslmode"],
                    [
                        "disable",
                        "allow",
                        "prefer",
                        "require",
                        "verify-ca",
                        "verify-full",
                    ],
                    true,
                ))
        ) {
            throw new RuntimeException(
                "Use an explicit supported PostgreSQL connection.",
            );
        }
        return [
            "command" => [
                $binary,
                "--format=custom",
                "--no-password",
                "--no-owner",
                "--no-acl",
                "--lock-wait-timeout=1500ms",
            ],
            "environment" => [
                "PGHOST" => $parts["host"],
                "PGPORT" => $parts["port"] ?? "5432",
                "PGDATABASE" => $parts["dbname"],
                "PGUSER" => $this->connection["username"] ?? "",
                "PGPASSWORD" => $this->connection["password"] ?? "",
                "PGCONNECT_TIMEOUT" => "5",
                "PGAPPNAME" => "etsis-backup",
                "PGSSLMODE" => $parts["sslmode"] ?? "prefer",
                "PGOPTIONS" =>
                    "-c statement_timeout=60000 -c lock_timeout=1500 -c default_transaction_read_only=on",
            ],
        ];
    }

    public function __debugInfo(): array
    {
        return ["database_source" => "[private configured database]"];
    }

    public function __serialize(): array
    {
        return $this->__debugInfo();
    }
}
