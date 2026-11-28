<?php

declare(strict_types=1);

namespace Infrastructure\Person;

use Domain\Communications\Service\MessageCipher;
use Domain\Person\Service\PhotoPolicy;
use Domain\Person\Service\PhotoStorage;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

/** Encrypted, bounded CRUD artifacts commit atomically with their manifest/selection. No pixels in events. */
final readonly class SqlPhotoStorage implements PhotoStorage
{
    private const int CHUNK = 262144;

    public function __construct(
        private SqlRecords $sql,
        private MessageCipher $cipher,
    ) {
    }

    public function put(
        EntityId $id,
        #[\SensitiveParameter] string $bytes,
    ): void {
        if ($bytes === "" || strlen($bytes) > PhotoPolicy::MAX_ARCHIVE) {
            throw new \InvalidArgumentException(
                "The private image exceeds its limit.",
            );
        }
        $count = (int) ceil(strlen($bytes) / self::CHUNK);
        $digest = hash("sha256", $bytes);
        for ($i = 0; $i < $count; $i++) {
            $this->sql->insert("sis_photo_payloads", [
                "id" => EntityId::generate()->value,
                "artifact_id" => $id->value,
                "part" => $i,
                "encrypted_content" => $this->cipher->seal(
                    $id->value .
                        ":" .
                        $i .
                        ":" .
                        $count .
                        ":" .
                        $digest .
                        "\n" .
                        substr($bytes, $i * self::CHUNK, self::CHUNK),
                ),
            ]);
        }
    }

    public function read(EntityId $id): string
    {
        try {
            return $this->readProtected($id);
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    private function readProtected(EntityId $id): string
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_photo_payloads") .
                " WHERE " .
                $q("artifact_id") .
                " = ? ORDER BY " .
                $q("part"),
            [$id->value],
            41,
        );
        if ($rows === [] || count($rows) > 40) {
            throw new CorruptHistoryException();
        }
        $bytes = "";
        $digest = null;
        foreach ($rows as $i => $row) {
            if (
                (int) $row["part"] !== $i ||
                strlen($row["encrypted_content"]) > self::CHUNK * 3
            ) {
                throw new CorruptHistoryException();
            }
            $plain = $this->cipher->open($row["encrypted_content"]);
            if (!str_contains($plain, "\n")) {
                throw new CorruptHistoryException();
            }
            [$header, $chunk] = explode("\n", $plain, 2);
            $parts = explode(":", $header);
            $digest ??= $parts[3] ?? null;
            if (
                $header !==
                $id->value . ":" . $i . ":" . count($rows) . ":" . $digest
            ) {
                throw new CorruptHistoryException();
            }
            $bytes .= $chunk;
            if (strlen($bytes) > PhotoPolicy::MAX_ARCHIVE) {
                throw new CorruptHistoryException();
            }
        }
        if (
            !is_string($digest) ||
            !hash_equals($digest, hash("sha256", $bytes))
        ) {
            throw new CorruptHistoryException();
        }
        return $bytes;
    }

    public function remove(EntityId $id): void
    {
        $this->sql->execute(
            "DELETE FROM " .
                $this->sql->quote("sis_photo_payloads") .
                " WHERE " .
                $this->sql->quote("artifact_id") .
                " = ?",
            [$id->value],
        );
    }
}
