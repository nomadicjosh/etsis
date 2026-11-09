<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Codefy\Domain\EventSourcing\DomainEvent;
use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/** Reads actual recorded legacy events, never synthesizes events from current-state projections. */
final readonly class LegacyEventDecoder
{
    public function __construct(private HistoricalSchemaRegistry $schemas)
    {
    }

    /** @param array<string, mixed> $row
     * @return array{stream: StreamReference, event: DomainEvent, context: EventContext}
     */
    public function decode(#[\SensitiveParameter] array $row): array
    {
        try {
            $payload = json_decode(
                $row["payload"],
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            $metadata = json_decode(
                $row["metadata"],
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($payload) ||
                !is_array($metadata) ||
                (!is_string($row["aggregate_playhead"]) &&
                    !is_int($row["aggregate_playhead"])) ||
                preg_match(
                    '/^[1-9][0-9]{0,9}$/D',
                    (string) $row["aggregate_playhead"],
                ) !== 1 ||
                (int) $row["aggregate_playhead"] > 2000000000 ||
                $metadata["__aggregate_id"] !== $row["aggregate_id"] ||
                $metadata["__aggregate_type"] !== $row["aggregate_type"] ||
                $metadata["__event_id"] !== $row["event_id"] ||
                $metadata["__event_type"] !== $row["event_type"] ||
                $metadata["__aggregate_playhead"] !==
                    (int) $row["aggregate_playhead"]
            ) {
                throw new CorruptHistoryException();
            }
            $stream = $this->schemas->stream(
                $row["aggregate_type"],
                $row["aggregate_id"],
            );
            $sourceTime = $this->sourceTime($metadata["__recorded_at"]);
            // Preserve all original fields verbatim inside authenticated encryption, including the old SQL clock.
            $context = new EventContext(
                isset($payload["actor_id"])
                    ? new EntityId($payload["actor_id"])
                    : null,
                null,
                null,
                origin: "legacy",
                sourceTransactionId: $row["transaction_id"],
                legacyRecord: $row,
            );
            $custom = array_diff_key(
                $metadata,
                array_flip([
                    "__aggregate_id",
                    "__aggregate_type",
                    "__aggregate_playhead",
                    "__event_id",
                    "__event_type",
                    "__recorded_at",
                ]),
            );
            $event = $this->schemas->restore(
                $stream,
                $row["event_id"],
                (int) $row["aggregate_playhead"],
                [
                    "class" => $row["event_classname"],
                    "type" => $row["event_type"],
                    "schema_version" => 1,
                    "payload" => $payload,
                    "metadata" => $custom,
                    "source_recorded_at" => $sourceTime->format(
                        "Y-m-d\TH:i:s.u\Z",
                    ),
                ],
            );
            return [
                "stream" => $stream,
                "event" => $event,
                "context" => $context,
            ];
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    private function sourceTime(string $value): DateTimeImmutable
    {
        foreach (["!Y-m-d H:i:s", "!Y-m-d H:i:s.u"] as $format) {
            $date = DateTimeImmutable::createFromFormat(
                $format,
                $value,
                new DateTimeZone("UTC"),
            );
            if (
                $date !== false &&
                $date->format(substr($format, 1)) === $value
            ) {
                return $date;
            }
        }
        throw new CorruptHistoryException();
    }
}
