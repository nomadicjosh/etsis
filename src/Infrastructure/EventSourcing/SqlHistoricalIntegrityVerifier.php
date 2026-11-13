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
        $events = $completed = 0;
        $started = $state["active"] === null ? 0 : 1;
        $begin = hrtime(true);
        while ($events < $eventLimit && ($state["active"] !== null || $started < $streamLimit)) {
            if (hrtime(true) - $begin >= 5_000_000_000) {
                break;
            }
            if ($state["active"] === null) {
                $row = $this->nextHead($state["after"]);
                if ($row === null) {
                    return new HistoryVerificationResult(true, $completed, $events);
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
                    min(20, $eventLimit - $events),
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
        if ($state["active"] === null && $this->nextHead($state["after"]) === null) {
            return new HistoryVerificationResult(true, $completed, $events);
        }
        return new HistoryVerificationResult(
            false,
            $completed,
            $events,
            $this->codec->seal("history.verification.v1", $state),
        );
    }
    /** @return array{after:?string,active:?array{id:string,cursor:?string}} */
    private function state(#[\SensitiveParameter] ?string $cursor): array
    {
        if ($cursor === null) {
            return ["after" => null, "active" => null];
        }
        try {
            if ($cursor === "" || strlen($cursor) > 32768) {
                throw new \RuntimeException();
            }
            $state = $this->codec->open("history.verification.v1", $cursor);
            if (
                count($state) !== 2 ||
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
            return ["after" => $state["after"], "active" => $state["active"]];
        } catch (Throwable) {
            throw new InvalidArgumentException(
                "The verification cursor could not be validated. Start a fresh check.",
            );
        }
    }
    /** One indexed statement compares against the currently committed head, allowing concurrent atomic appends. */
    private function assertNoUncommittedPositions(EntityId $stream): void
    {
        $q = $this->sql->quote(...);
        $conditions = [
            "< 1",
            "> (SELECT " . $q("version") . " FROM " . $q("sis_history_streams") .
            " WHERE " . $q("id") . " = ?)",
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
