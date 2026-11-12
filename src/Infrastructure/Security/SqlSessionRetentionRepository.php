<?php

declare(strict_types=1);

namespace Infrastructure\Security;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\SessionRetentionRepository;
use Domain\User\ValueObject\SessionRetentionSummary;
use Infrastructure\Persistence\SqlRecords;

/** Fixed support tables only. Academic history, person links and provisioning receipts are never targets. */
final readonly class SqlSessionRetentionRepository implements SessionRetentionRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function preview(DateTimeImmutable $cutoff, int $limit): SessionRetentionSummary
    {
        return $this->collect($cutoff, $limit, false);
    }
    public function prune(DateTimeImmutable $cutoff, int $limit): SessionRetentionSummary
    {
        return $this->collect($cutoff, $limit, true);
    }
    private function collect(DateTimeImmutable $cutoff, int $limit, bool $apply): SessionRetentionSummary
    {
        foreach (["sis_login_sessions", "sis_moodle_launches"] as $table) {
            if (!$this->sql->tableExists($table)) {
                throw new \RuntimeException(
                    "Sign-in support migrations are required before retention cleanup.",
                );
            }
        }
        $before = $cutoff->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z");
        $invalid = $changed = $referenced = $launches = $sessions = 0;
        $rows = $this->candidates("sis_moodle_launches", "session_expires_at", $before, $limit);
        $previewRemovals = [];
        foreach ($rows as $row) {
            if (!$this->valid($row, $cutoff)) {
                $invalid++;
                continue;
            }
            if (!$apply) {
                $previewRemovals[] = $row["id"];
                $launches++;
                continue;
            }
            $removed = $this->removeLaunch($row["id"], $row["deadline"], $before);
            $launches += $removed;
            $changed += $removed === 0 ? 1 : 0;
        }
        $parents = $this->candidates("sis_login_sessions", "expires_at", $before, $limit);
        foreach ($parents as $row) {
            if (!$this->valid($row, $cutoff)) {
                $invalid++;
                continue;
            }
            if ($this->referenced($row["id"], $previewRemovals)) {
                $referenced++;
                continue;
            }
            if (!$apply) {
                $sessions++;
                continue;
            }
            $removed = $this->removeSession($row["id"], $row["deadline"], $before);
            $sessions += $removed;
            $changed += $removed === 0 ? 1 : 0;
        }
        return new SessionRetentionSummary(
            $cutoff,
            $apply,
            $launches,
            $sessions,
            $referenced,
            $invalid,
            $changed,
            count($rows) === $limit || count($parents) === $limit,
        );
    }
    /** @return list<array<string,mixed>> */
    private function candidates(string $table, string $expiry, string $before, int $limit): array
    {
        $q = $this->sql->quote(...);
        // Bound the examined candidates, not just the deleted rows, even when some need review.
        return $this->sql->fetch(
            "SELECT " .
                $q("id") .
                ", " .
                $q($expiry) .
                " AS " .
                $q("deadline") .
                " FROM " .
                $q($table) .
                " WHERE " .
                $q($expiry) .
                " < ? ORDER BY " .
                $q($expiry) .
                ", " .
                $q("id"),
            [$before],
            $limit,
        );
    }
    /** @param array<string,mixed> $row */
    private function valid(array $row, DateTimeImmutable $cutoff): bool
    {
        if (
            !is_string($row["id"] ?? null) ||
            !is_string($row["deadline"] ?? null) ||
            preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $row["deadline"]) !== 1
        ) {
            return false;
        }
        try {
            new EntityId($row["id"]);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat(
            "!Y-m-d\TH:i:s\Z",
            $row["deadline"],
            new DateTimeZone("UTC"),
        );
        return $date !== false &&
        $date->format("Y-m-d\TH:i:s\Z") === $row["deadline"] &&
        $date->getTimestamp() > 0 &&
        $date < $cutoff;
    }
    /** @param list<string> $previewRemovals */
    private function referenced(string $id, array $previewRemovals): bool
    {
        $q = $this->sql->quote(...);
        $statement =
        "SELECT " .
        $q("id") .
        " FROM " .
        $q("sis_moodle_launches") .
        " WHERE " .
        $q("session_id") .
        " = ?";
        if ($previewRemovals !== []) {
            $statement .=
            " AND " .
            $q("id") .
            " NOT IN (" .
            implode(", ", array_fill(0, count($previewRemovals), "?")) .
            ")";
        }
        return $this->sql->fetch($statement, [$id, ...$previewRemovals], 1) !== [];
    }
    private function removeLaunch(string $id, string $deadline, string $before): int
    {
        $q = $this->sql->quote(...);
        return $this->sql->execute(
            "DELETE FROM " .
                $q("sis_moodle_launches") .
                " WHERE " .
                $q("id") .
                " = ? AND " .
                $q("session_expires_at") .
                " = ? AND " .
                $q("session_expires_at") .
                " < ?",
            [$id, $deadline, $before],
        );
    }
    private function removeSession(string $id, string $deadline, string $before): int
    {
        $q = $this->sql->quote(...);
        return $this->sql->execute(
            "DELETE FROM " .
                $q("sis_login_sessions") .
                " WHERE " .
                $q("id") .
                " = ? AND " .
                $q("expires_at") .
                " = ? AND " .
                $q("expires_at") .
                " < ? AND NOT EXISTS (SELECT " .
                $q("id") .
                " FROM " .
                $q("sis_moodle_launches") .
                " WHERE " .
                $q("session_id") .
                " = ?)",
            [$id, $deadline, $before, $id],
        );
    }
}
