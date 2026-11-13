<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoricalIntegrityVerifier;
use Domain\Shared\EventSourcing\HistoryVerificationFailedException;
use Domain\Shared\EventSourcing\HistoryVerificationResult;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use Throwable;

/** Walk indexed heads and reuse the journal's authenticated paging. No database writes or aggregate handlers. */
final readonly class SqlHistoricalIntegrityVerifier implements HistoricalIntegrityVerifier
{
    public function __construct(
        private SqlRecords $sql,
        private HistoricalEventStore $history,
        private ProtectedEventCodec $codec,
        private HistoricalSchemaRegistry $schemas,
    ) {
    }
    public function verify(
        #[\SensitiveParameter] ?string $cursor = null,
        int $streamLimit = 10,
        int $eventLimit = 500,
    ): HistoryVerificationResult {
        if ($streamLimit < 1 || $streamLimit > 50 || $eventLimit < 1 || $eventLimit > 2000) {
            throw new InvalidArgumentException(
                "Use stream limits from 1 to 50 and event limits from 1 to 2000.",
            );
        }
        if ($this->sql->tableExists("event_store")) {
            throw new \LogicException(
                "Complete the single-store migration before checking protected history.",
            );
        }
        $state = $this->state($cursor);
        $events = $completed = $inventory = 0;
        $started = $state["active"] === null ? 0 : 1;
        $begin = hrtime(true);
        while ($events + $inventory < $eventLimit) {
            if (hrtime(true) - $begin >= 5_000_000_000) {
                break;
            }
            if ($state["stage"] === "inventory") {
                $rows = $this->eventRows($state["after"], min(20, $eventLimit - $events - $inventory));
                if ($rows === []) {
                    return new HistoryVerificationResult(
                        true,
                        $completed,
                        $events,
                        inventoryEvents: $inventory,
                    );
                }
                $this->assertRecordedHeads($rows);
                $inventory += count($rows);
                $state["after"] = $rows[array_key_last($rows)]["id"];
                continue;
            }
            if ($state["active"] === null) {
                $row = $this->nextHead($state["after"]);
                if ($row === null) {
                    $state = ["stage" => "inventory", "after" => null, "active" => null];
                    continue;
                }
                if ($started >= $streamLimit) {
                    break;
                }
                $state["active"] = ["id" => $row["id"], "cursor" => null];
                $started++;
            } else {
                $row =
                $this->sql->rows("sis_history_streams", ["id" => $state["active"]["id"]], 1)[0] ?? null;
            }
            $reference = null;
            try {
                $reference = new EntityId($state["active"]["id"]);
                if ($row === null) {
                    throw new \RuntimeException();
                }
                $stream = $this->schemas->stream($row["stream_type"], $row["aggregate_id"]);
                $page = $this->history->page(
                    $stream,
                    $state["active"]["cursor"],
                    min(20, $eventLimit - $events - $inventory),
                );
                $events += count($page->events);
                if ($page->nextCursor === null) {
                    $this->assertNoUncommittedPositions($reference);
                    $completed++;
                    $state["after"] = $reference->value;
                    $state["active"] = null;
                } else {
                    $state["active"]["cursor"] = $page->nextCursor;
                }
            } catch (Throwable) {
                // Storage/decryption/schema failures must not retain an upstream payload or exception chain.
                throw new HistoryVerificationFailedException($reference);
            }
        }
        if (
            $state["stage"] === "streams" &&
            $state["active"] === null &&
            $this->nextHead($state["after"]) === null
        ) {
            $state = ["stage" => "inventory", "after" => null, "active" => null];
        }
        if ($state["stage"] === "inventory" && $this->eventRows($state["after"], 1) === []) {
            return new HistoryVerificationResult(true, $completed, $events, inventoryEvents: $inventory);
        }
        return new HistoryVerificationResult(
            false,
            $completed,
            $events,
            $this->codec->seal("history.verification.v1", $state),
            $inventory,
        );
    }
    /** @return array{stage:'streams'|'inventory',after:?string,active:?array{id:string,cursor:?string}} */
    private function state(#[\SensitiveParameter] ?string $cursor): array
    {
        if ($cursor === null) {
            return ["stage" => "streams", "after" => null, "active" => null];
        }
        try {
            if ($cursor === "" || strlen($cursor) > 32768) {
                throw new \RuntimeException();
            }
            $state = $this->codec->open("history.verification.v1", $cursor);
            if (
                count($state) !== 3 ||
                !in_array($state["stage"] ?? null, ["streams", "inventory"], true) ||
                !array_key_exists("after", $state) ||
                !array_key_exists("active", $state)
            ) {
                throw new \RuntimeException();
            }
            if ($state["after"] !== null) {
                if (!is_string($state["after"])) {
                    throw new \RuntimeException();
                }
                new EntityId($state["after"]);
            }
            if ($state["active"] !== null) {
                $active = $state["active"];
                if (
                    $state["stage"] !== "streams" ||
                    !is_array($active) ||
                    count($active) !== 2 ||
                    !is_string($active["id"] ?? null) ||
                    !array_key_exists("cursor", $active) ||
                    ($active["cursor"] !== null &&
                        (!is_string($active["cursor"]) || strlen($active["cursor"]) > 16384)) ||
                    ($state["after"] !== null && strcmp($active["id"], $state["after"]) <= 0)
                ) {
                    throw new \RuntimeException();
                }
                new EntityId($active["id"]);
            }
            return ["stage" => $state["stage"], "after" => $state["after"], "active" => $state["active"]];
        } catch (Throwable) {
            throw new InvalidArgumentException(
                "The verification cursor could not be validated. Start a fresh check.",
            );
        }
    }
    /** @param list<array<string,mixed>> $rows */
    private function assertRecordedHeads(array $rows): void
    {
        try {
            $ids = [];
            foreach ($rows as $row) {
                new EntityId($row["id"]);
                $ids[] = new EntityId($row["stream_id"])->value;
            }
            $ids = array_values(array_unique($ids));
            $q = $this->sql->quote(...);
            $heads = $this->sql->fetch(
                "SELECT " .
                    $q("id") .
                    " FROM " .
                    $q("sis_history_streams") .
                    " WHERE " .
                    $q("id") .
                    " IN (" .
                    implode(", ", array_fill(0, count($ids), "?")) .
                    ")",
                $ids,
                count($ids),
            );
            if (count($heads) !== count($ids)) {
                throw new \RuntimeException();
            }
        } catch (Throwable) {
            // A damaged foreign key could contain a person ID; never expose it as a support reference.
            throw new HistoryVerificationFailedException();
        }
    }
    /** @return list<array<string,mixed>> */
    private function eventRows(?string $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                $q("id") .
                ", " .
                $q("stream_id") .
                " FROM " .
                $q("sis_history_events") .
                ($after === null ? "" : " WHERE " . $q("id") . " > ?") .
                " ORDER BY " .
                $q("id"),
            $after === null ? [] : [$after],
            $limit,
        );
    }
    /** One indexed statement compares against the currently committed head, allowing concurrent atomic appends. */
    private function assertNoUncommittedPositions(EntityId $stream): void
    {
        $q = $this->sql->quote(...);
        $conditions = [
            "< 1",
            "> (SELECT " .
            $q("version") .
            " FROM " .
            $q("sis_history_streams") .
            " WHERE " .
            $q("id") .
            " = ?)",
        ];
        foreach ($conditions as $condition) {
            $parameters = [$stream->value];
            if ($condition !== "< 1") {
                $parameters[] = $stream->value;
            }
            $rows = $this->sql->fetch(
                "SELECT " .
                    $q("position") .
                    " FROM " .
                    $q("sis_history_events") .
                    " WHERE " .
                    $q("stream_id") .
                    " = ? AND " .
                    $q("position") .
                    " " .
                    $condition .
                    " ORDER BY " .
                    $q("position"),
                $parameters,
                1,
            );
            if ($rows !== []) {
                throw new \RuntimeException();
            }
        }
    }
    /** @return array<string,mixed>|null */
    private function nextHead(?string $after): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                implode(", ", array_map($q, ["id", "stream_type", "aggregate_id"])) .
                " FROM " .
                $q("sis_history_streams") .
                ($after === null ? "" : " WHERE " . $q("id") . " > ?") .
                " ORDER BY " .
                $q("id"),
            $after === null ? [] : [$after],
            1,
        )[0] ?? null;
    }
}
