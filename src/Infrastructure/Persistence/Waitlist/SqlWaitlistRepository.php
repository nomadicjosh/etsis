<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Waitlist;

use Domain\Communications\Service\MessageCipher;
use Domain\Registration\Waitlist\WaitlistRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlWaitlistRepository implements WaitlistRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }
    public function entry(EntityId $entry): ?array
    {
        $row = $this->sql->one('sis_waitlist_entries', ['id' => $entry->value]);
        return $row === null ? null : $this->row($row);
    }
    public function current(EntityId $section): ?array
    {
        $row = $this->sql->current("sis_waitlist_state", $section->value, "section_id");
        if ($row === null) {
            return null;
        }
        try {
            $version = filter_var($row["version"], FILTER_VALIDATE_INT);
            if (
                $version === false ||
                $version < 1 ||
                $version > 2000 ||
                !is_string($row["encrypted_state"]) ||
                strlen($row["encrypted_state"]) > 100000
            ) {
                throw new CorruptHistoryException();
            }
            return [
                "version" => $version,
                "projection" => json_decode(
                    $this->cipher->open($row["encrypted_state"]),
                    true,
                    12,
                    JSON_THROW_ON_ERROR,
                ),
            ];
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function active(EntityId $section): array
    {
        $q = $this->sql->quote(...);
        return array_map(
            $this->row(...),
            $this->sql->fetch(
                "SELECT * FROM " .
                    $q("sis_waitlist_entries") .
                    " WHERE " .
                    $q("section_id") .
                    " = ? AND " .
                    $q("status") .
                    " = ? ORDER BY " .
                    $q("position"),
                [$section->value, "active"],
                101,
            ),
        );
    }
    public function save(EntityId $section, int $expected, array $projection, ?array $entry): void
    {
        $data = [
            "version" => $expected + 1,
            "encrypted_state" => $this->cipher->seal(json_encode($projection, JSON_THROW_ON_ERROR)),
        ];
        if ($expected === 0) {
            $this->sql->insert("sis_waitlist_state", ["section_id" => $section->value] + $data);
        } elseif (
            $this->sql->update("sis_waitlist_state", $data, [
                "section_id" => $section->value,
                "version" => $expected,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("The waitlist changed. Refresh it.");
        }
        if ($entry === null) {
            return;
        }
        unset($entry["changed_at"]);
        $data = ["section_id" => $section->value] + $entry;
        if ($entry["status"] === "active") {
            $this->sql->insert("sis_waitlist_entries", $data);
        } elseif (
            $this->sql->update(
                "sis_waitlist_entries",
                ["status" => $entry["status"], "enrollment_id" => $entry["enrollment_id"]],
                ["id" => $entry["id"], "section_id" => $section->value, "status" => "active"],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The waitlist request changed.");
        }
    }
    public function mine(EntityId $student, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        return array_map(
            $this->row(...),
            $this->sql->fetch(
                "SELECT * FROM " .
                    $q("sis_waitlist_entries") .
                    " WHERE " .
                    $q("student_id") .
                    " = ?" .
                    ($after === null ? "" : " AND " . $q("id") . " < ?") .
                    " ORDER BY " .
                    $q("id") .
                    " DESC",
                $after === null ? [$student->value] : [$student->value, $after->value],
                11,
            ),
        );
    }
    private function row(array $row): array
    {
        try {
            foreach (["id", "section_id", "student_id", "person_id"] as $key) {
                new EntityId($row[$key]);
            }
            foreach (["position", "policy_position"] as $key) {
                $number = filter_var($row[$key], FILTER_VALIDATE_INT);
                if ($number === false || $number < 1 || $number > 2000) {
                    throw new CorruptHistoryException();
                }
                $row[$key] = $number;
            }
            if (
                !in_array($row["status"], ["active", "cancelled", "removed", "registered"], true) ||
                ($row["enrollment_id"] !== null) !== ($row["status"] === "registered")
            ) {
                throw new CorruptHistoryException();
            }
            if ($row["enrollment_id"] !== null) {
                new EntityId($row["enrollment_id"]);
            }
            return $row;
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }
}
