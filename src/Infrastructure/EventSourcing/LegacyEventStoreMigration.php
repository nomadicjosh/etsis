<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\DatabaseUnitOfWork;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\SystemClock;
use Qubus\Expressive\Database;
use Throwable;

/** Offline, resumable copy and verification. The migration drops the source only after this succeeds. */
final readonly class LegacyEventStoreMigration
{
    private SqlRecords $sql;
    private SqlHistoricalEventStore $store;
    private HistoricalSchemaRegistry $schemas;
    private LegacyEventDecoder $decoder;

    public function __construct(Database $db, ProtectedEventCodec $codec)
    {
        $this->sql = new SqlRecords($db);
        $this->schemas = new HistoricalSchemaRegistry();
        $this->decoder = new LegacyEventDecoder($this->schemas);
        $this->store = new SqlHistoricalEventStore(
            $this->sql,
            new DatabaseUnitOfWork($db),
            new SystemClock(),
            $codec,
            $this->schemas,
            convertingLegacy: true,
        );
    }

    public function copyAndVerify(): void
    {
        try {
            $q = $this->sql->quote(...);
            $afterType = "";
            $afterId = "";
            do {
                $streams = $this->sql->fetch(
                    "SELECT DISTINCT " .
                        $q("aggregate_type") .
                        ", " .
                        $q("aggregate_id") .
                        " FROM " .
                        $q("event_store") .
                        " WHERE " .
                        $q("aggregate_type") .
                        " > ? OR (" .
                        $q("aggregate_type") .
                        " = ? AND " .
                        $q("aggregate_id") .
                        " > ?) ORDER BY " .
                        $q("aggregate_type") .
                        ", " .
                        $q("aggregate_id"),
                    [$afterType, $afterType, $afterId],
                    20,
                );
                foreach ($streams as $row) {
                    $this->copyStream(
                        $this->schemas->stream(
                            $row["aggregate_type"],
                            $row["aggregate_id"],
                        ),
                    );
                    $afterType = $row["aggregate_type"];
                    $afterId = $row["aggregate_id"];
                }
            } while (count($streams) === 20);
            $source = $this->sql->fetch(
                "SELECT COUNT(*) AS " .
                    $q("total") .
                    " FROM " .
                    $q("event_store"),
            )[0]["total"];
            $target = $this->sql->fetch(
                "SELECT COUNT(*) AS " .
                    $q("total") .
                    " FROM " .
                    $q("sis_history_events"),
            )[0]["total"];
            if ((string) $source !== (string) $target) {
                throw new CorruptHistoryException();
            }
        } catch (Throwable) {
            // Never chain exceptions that might contain legacy payloads or credentials.
            throw new CorruptHistoryException();
        }
    }

    private function copyStream(StreamReference $stream): void
    {
        // An interrupted run may already have copied a verified prefix. Compare it before continuing.
        $through = $this->verifyStream($stream);
        $position = 0;
        $q = $this->sql->quote(...);
        do {
            $rows = $this->sql->fetch(
                "SELECT * FROM " .
                    $q("event_store") .
                    " WHERE " .
                    $q("aggregate_type") .
                    " = ? AND " .
                    $q("aggregate_id") .
                    " = ? AND " .
                    $q("aggregate_playhead") .
                    " > ? ORDER BY " .
                    $q("aggregate_playhead"),
                [$stream->type, (string) $stream->aggregateId, $position],
                50,
            );
            foreach ($rows as $row) {
                $decoded = $this->decoder->decode($row);
                if ($decoded["event"]->playhead() !== $position + 1) {
                    throw new CorruptHistoryException();
                }
                if ($position >= $through) {
                    $this->store->append(
                        $stream,
                        EntityId::generate(),
                        $position,
                        $decoded["context"],
                        [$decoded["event"]],
                    );
                }
                $position++;
            }
        } while (count($rows) === 50);
        if ($this->verifyStream($stream) !== $position) {
            throw new CorruptHistoryException();
        }
    }

    private function verifyStream(StreamReference $stream): int
    {
        $cursor = null;
        $through = 0;
        do {
            $page = $this->store->page($stream, $cursor, 50);
            foreach ($page->events as $stored) {
                $this->verifyEvent($stream, $stored);
            }
            $through = $page->throughVersion;
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return $through;
    }

    private function verifyEvent(
        StreamReference $stream,
        StoredEvent $stored,
    ): void {
        $row = $this->sql->one("event_store", [
            "event_id" => (string) $stored->event->eventId(),
        ]);
        $original = $this->decoder->decode($row);
        if (
            $original["stream"]->type !== $stream->type ||
            (string) $original["stream"]->aggregateId !==
                (string) $stream->aggregateId ||
            $original["event"]->playhead() !== $stored->event->playhead() ||
            ProtectedEventCodec::canonical(
                $this->schemas->describe($original["event"]),
            ) !==
                ProtectedEventCodec::canonical(
                    $this->schemas->describe($stored->event),
                ) ||
            ProtectedEventCodec::canonical($original["context"]->describe()) !==
                ProtectedEventCodec::canonical($stored->context->describe())
        ) {
            throw new CorruptHistoryException();
        }
    }
}
