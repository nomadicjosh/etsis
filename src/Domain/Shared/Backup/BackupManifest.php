<?php

declare(strict_types=1);

namespace Domain\Shared\Backup;

use Domain\Shared\EventSourcing\HistoryAnchor;
use Domain\Shared\ValueObject\EntityId;

/** Only encrypted repository storage may retain the manifest's paths and digests. */
final readonly class BackupManifest implements \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] public array $data)
    {
        try {
            $fields = [
                "format",
                "id",
                "scope",
                "release",
                "driver",
                "database",
                "files",
                "database_digest",
                "inventory",
                "anchor",
            ];
            if (
                count($data) !== count($fields) ||
                array_diff(array_keys($data), $fields) !== [] ||
                $data["format"] !== 1 ||
                !in_array($data["driver"], ["pgsql", "sqlite"], true)
            ) {
                throw new \InvalidArgumentException();
            }
            new EntityId($data["id"]);
            $anchor = new HistoryAnchor($data["anchor"]);
            if (
                $anchor->data["id"] !== $data["id"] ||
                $anchor->data["scope"] !== $data["scope"]
            ) {
                throw new \InvalidArgumentException();
            }
            new BackupPlan(
                $data["scope"],
                $data["release"],
                ["/placeholder"],
                "/placeholder",
                "/placeholder",
            );
            self::snapshot($data["database"]);
            self::snapshot($data["files"]);
            if (
                $data["database"] === $data["files"] ||
                !is_array($data["database_digest"]) ||
                count($data["database_digest"]) !== 2 ||
                !is_int($data["database_digest"]["bytes"] ?? null) ||
                $data["database_digest"]["bytes"] < 1 ||
                $data["database_digest"]["bytes"] > 10737418240 ||
                preg_match(
                    '/^[a-f0-9]{64}$/D',
                    $data["database_digest"]["sha256"] ?? "",
                ) !== 1 ||
                !is_array($data["inventory"]) ||
                !array_is_list($data["inventory"]) ||
                count($data["inventory"]) < 1 ||
                count($data["inventory"]) > 10000
            ) {
                throw new \InvalidArgumentException();
            }
            $previous = "";
            foreach ($data["inventory"] as $file) {
                if (
                    !is_array($file) ||
                    count($file) !== 3 ||
                    !is_string($file["path"] ?? null) ||
                    !str_starts_with($file["path"], "/") ||
                    strlen($file["path"]) > 4096 ||
                    preg_match('/[\x00-\x1f\x7f]/', $file["path"]) === 1 ||
                    preg_match('~(?:^|/)\.{1,2}(?:/|$)|//~', $file["path"]) ===
                        1 ||
                    strcmp($previous, $file["path"]) >= 0 ||
                    !is_int($file["bytes"] ?? null) ||
                    $file["bytes"] < 0 ||
                    preg_match('/^[a-f0-9]{64}$/D', $file["sha256"] ?? "") !== 1
                ) {
                    throw new \InvalidArgumentException();
                }
                $previous = $file["path"];
            }
        } catch (\Throwable) {
            throw new \RuntimeException(
                "The backup manifest could not be authenticated and validated.",
            );
        }
    }

    public static function snapshot(string $id): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
            throw new \InvalidArgumentException(
                "Use the full snapshot reference.",
            );
        }
    }

    public function __debugInfo(): array
    {
        return ["backup_manifest" => "[private encrypted backup evidence]"];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __serialize(): array
    {
        return $this->__debugInfo();
    }
}
