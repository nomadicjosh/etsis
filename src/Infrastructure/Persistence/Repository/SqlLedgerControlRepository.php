<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Finance\Repository\LedgerControlRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlLedgerControlRepository implements LedgerControlRepository
{
    public const string GATE = "01K00000000000000000000G40";

    public function __construct(private SqlRecords $sql)
    {
    }

    public function lock(): void
    {
        $this->sql->lock("sis_gl_gate", self::GATE, "revision");
    }

    public function book(string $currency): ?array
    {
        return $this->sql->rows(
            "sis_gl_books",
            ["currency" => $currency],
            1,
        )[0] ?? null;
    }

    public function period(string $id): ?array
    {
        return $this->sql->rows("sis_gl_periods", ["id" => $id], 1)[0] ?? null;
    }

    public function account(string $id): array
    {
        return $this->sql->one("sis_ledger_accounts", ["id" => $id]);
    }

    public function hasEarlierPostings(string $currency): bool
    {
        $q = $this->sql->quote(...);
        foreach (['student_account' => 'sis_student_accounts', 'journal' => 'sis_journals'] as $type => $table) {
            $unrouted = $this->sql->fetch('SELECT s.' . $q('id') . ' FROM ' . $q('sis_history_streams') . ' s'
                . ' LEFT JOIN ' . $q($table) . ' r ON r.' . $q('id') . ' = s.' . $q('aggregate_id')
                . ' WHERE s.' . $q('stream_type') . ' = ? AND r.' . $q('id') . ' IS NULL', [$type], 1);
            if ($unrouted !== []) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
        }
        if ($this->sql->rows('sis_student_accounts', ['currency' => $currency], 1) !== []) {
            return true;
        }
        return $this->sql->rows(
            "sis_account_entries",
            ["currency" => $currency],
            1,
        ) !== [] ||
        $this->sql->rows("sis_journals", ["currency" => $currency], 1) !==
        [];
    }

    public function renameReceivable(array $account): void
    {
        $this->sql->update(
            "sis_ledger_accounts",
            ["code" => $account["code"], "title" => $account["title"]],
            ["id" => $account["id"]],
        );
    }

    public function saveBook(
        string $id,
        string $currency,
        int $position,
        int $expected,
    ): void {
        if ($expected === 0) {
            $this->sql->insert("sis_gl_books", [
                "id" => $id,
                "currency" => $currency,
                "position" => $position,
            ]);
        } elseif (
            $this->sql->update(
                "sis_gl_books",
                ["position" => $position],
                ["id" => $id, "position" => $expected],
            ) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The ledger mapping changed.",
            );
        }
    }

    public function savePeriod(
        string $id,
        array $definition,
        int $position,
        int $expected,
    ): void {
        $row = [
            "book_id" => $definition["book_id"],
            "starts_on" => $definition["starts_on"],
            "ends_on" => $definition["ends_on"],
            "position" => $position,
        ];
        if ($expected === 0) {
            $this->sql->insert("sis_gl_periods", ["id" => $id] + $row);
        } elseif (
            $this->sql->update("sis_gl_periods", $row, [
                "id" => $id,
                "position" => $expected,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The accounting period changed.",
            );
        }
    }

    public function addMovement(array $row): void
    {
        $row["source_key"] =
        $row["entry_operation"] === null
        ? hash("sha256", "movement:" . $row["id"])
        : hash(
            "sha256",
            "source:" .
                        $row["account_id"] .
                        ":" .
                        $row["entry_operation"],
        );
        $this->sql->insert("sis_gl_movements", $row);
    }

    public function movements(string $period, int $after, int $through): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_gl_movements") .
                " WHERE " .
                $q("period_id") .
                " = ? AND " .
                $q("ordinal") .
                " > ? AND " .
                $q("ordinal") .
                " <= ? ORDER BY " .
                $q("ordinal"),
            [$period, $after, $through],
            20,
        );
    }

    public function sourceJournal(string $account, string $operation): ?string
    {
        $rows = $this->sql->rows(
            "sis_gl_movements",
            ["account_id" => $account, "entry_operation" => $operation],
            2,
        );
        if (
            count($rows) > 1 ||
            ($rows !== [] &&
                $rows[0]["source_key"] !==
                    hash("sha256", "source:" . $account . ":" . $operation))
        ) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        return $rows[0]["journal_id"] ?? null;
    }
}
