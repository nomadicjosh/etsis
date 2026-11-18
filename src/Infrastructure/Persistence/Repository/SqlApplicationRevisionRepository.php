<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\Repository\ApplicationRevisionRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlApplicationRevisionRepository implements ApplicationRevisionRepository
{
    public function __construct(
        private SqlRecords $sql,
        private MessageCipher $cipher,
    ) {
    }
    public function revision(EntityId $application): int
    {
        $r = $this->sql->one("sis_applications", ["id" => $application->value]);
        $n = filter_var($r["answer_revision"], FILTER_VALIDATE_INT);
        if ($n === false || $n < 0 || $n >= 2000000000) {
            throw new CorruptHistoryException();
        }
        return $n;
    }
    public function find(EntityId $id): ?array
    {
        $rows = $this->sql->rows('sis_application_revisions', ['id' => $id->value], 1);
        $r = $rows[0] ?? null;
        return $r === null ? null : $this->open($r);
    }
    public function pending(EntityId $application): ?array
    {
        $rows = $this->sql->rows(
            "sis_application_revisions",
            ["application_id" => $application->value, "status" => "requested"],
            2,
        );
        if (count($rows) > 1) {
            throw new CorruptHistoryException();
        }
        return isset($rows[0]) ? $this->open($rows[0]) : null;
    }
    private function open(#[\SensitiveParameter] array $row): array
    {
        try {
            if (
                !is_string($row["sealed_data"]) ||
                strlen($row["sealed_data"]) > 1000000
            ) {
                throw new CorruptHistoryException();
            }
            $p = json_decode(
                $this->cipher->open($row["sealed_data"]),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($p) ||
                count($p) !== 11 ||
                !in_array(
                    $p["status"] ?? null,
                    ["requested", "cancelled", "submitted"],
                    true,
                ) ||
                !is_int($p["version"]) ||
                $p["version"] < 0 ||
                !is_string($p["instructions"]) ||
                !is_array($p["request_receipt"]) ||
                ($p["artifact"] !== null && !is_array($p["artifact"]))
            ) {
                throw new CorruptHistoryException();
            }
            foreach (["id", "application_id", "owner_user_id", "status", "version"] as $key) {
                if ((string) $row[$key] !== (string) $p[$key]) {
                    throw new CorruptHistoryException();
                }
            }
            return $p;
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function save(
        #[\SensitiveParameter] array $record,
        int $expected,
        bool $new,
    ): void {
        $app = new EntityId($record["application_id"]);
        if ($this->revision($app) !== $expected) {
            throw new ConcurrentModificationException(
                "Application answers changed. Reload them.",
            );
        }
        $old = $this->find(new EntityId($record["id"]));
        if (
            ($new && $old !== null) ||
            (!$new && ($old === null || $old["status"] !== "requested"))
        ) {
            throw new ConcurrentModificationException(
                "The answer artifact is already closed. Reload it.",
            );
        }
        $row = array_intersect_key(
            $record,
            array_flip([
                "id",
                "application_id",
                "owner_user_id",
                "status",
                "version",
            ]),
        );
        $row["sealed_data"] = $this->cipher->seal(
            json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
        $this->open($row);
        if ($new) {
            $this->sql->insert("sis_application_revisions", $row);
        } elseif (
            $this->sql->update("sis_application_revisions", $row, [
                "id" => $record["id"],
                "version" => $old["version"],
                "status" => "requested",
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The correction request changed. Reload it.",
            );
        }
        if (
            $this->sql->update(
                "sis_applications",
                ["answer_revision" => $expected + 1],
                ["id" => $app->value, "answer_revision" => $expected],
            ) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The correction request changed. Reload it.",
            );
        }
    }
}
