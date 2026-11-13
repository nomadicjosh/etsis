<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Codefy\Domain\EventSourcing\DomainEvent;
use Codefy\Domain\Metadata;
use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\EventPage;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use PDOException;
use Throwable;

final class SqlHistoricalEventStore implements HistoricalEventStore
{
    private bool $migrationVerified = false;

    private const string ZERO = "0000000000000000000000000000000000000000000000000000000000000000";

    public function __construct(
        private readonly SqlRecords $sql,
        private readonly UnitOfWork $transactions,
        private readonly Clock $clock,
        private readonly ProtectedEventCodec $codec,
        private readonly HistoricalSchemaRegistry $schemas,
        private readonly bool $convertingLegacy = false,
    ) {
    }

    public function append(
        StreamReference $stream,
        EntityId $operation,
        int $expectedVersion,
        #[\SensitiveParameter] EventContext $context,
        #[\SensitiveParameter] array $events,
    ): array {
        try {
            return $this->transactions->run(function () use (
                $stream,
                $operation,
                $expectedVersion,
                $context,
                $events,
            ): array {
                [$described, $fingerprint] = $this->prepare($stream, $expectedVersion, $context, $events);
                $head = $this->current("sis_history_streams", [
                    "stream_type" => $stream->type,
                    "aggregate_id" => (string) $stream->aggregateId,
                ]);
                if ($head === null) {
                    if ($expectedVersion !== 0) {
                        throw new ConcurrentModificationException("The historical stream version changed.");
                    }
                    $head = [
                        "id" => EntityId::generate()->value,
                        "stream_type" => $stream->type,
                        "aggregate_id" => (string) $stream->aggregateId,
                        "version" => 0,
                        "head_hash" => self::ZERO,
                    ];
                    $head["sealed_anchor"] = $this->codec->seal("history.head.v1", $head);
                    $this->sql->insert("sis_history_streams", $head);
                }
                $this->verifyHead($head, $stream);
                if ($head["version"] > 0) {
                    $last = $this->current("sis_history_events", [
                        "stream_id" => $head["id"],
                        "position" => $head["version"],
                    ]);
                    if ($last === null || $last["event_hash"] !== $head["head_hash"]) {
                        throw new CorruptHistoryException();
                    }
                    $this->decode($stream, $last);
                }
                $old = $this->current("sis_history_operations", [
                    "id" => $operation->value,
                ]);
                if ($old !== null) {
                    $saved = $this->codec->open("history.receipt.v1", $old["sealed_receipt"]);
                    if (($saved["actor_id"] ?? null) !== $context->actor?->value) {
                        throw new AccessDeniedException("Access denied.");
                    }
                    if (
                        $old["stream_id"] !== $head["id"] ||
                        ($saved["stream_id"] ?? null) !== $head["id"] ||
                        ($saved["operation_id"] ?? null) !== $operation->value ||
                        ($saved["fingerprint"] ?? null) !== $fingerprint ||
                        !is_array($saved["result"] ?? null)
                    ) {
                        throw new ConcurrentModificationException("This operation reference has already been used.");
                    }
                    $result = $this->receiptResult($saved["result"]);
                    if ($result["operation_id"] !== $operation->value || $result["last_version"] > $head["version"]) {
                        throw new CorruptHistoryException();
                    }
                    return $result;
                }
                if ($head["version"] !== $expectedVersion) {
                    throw new ConcurrentModificationException("The historical stream version changed.");
                }
                $first = $head["version"] + 1;
                $ids = [];
                foreach ($events as $index => $event) {
                    $row = [
                        "id" => EntityId::generate()->value,
                        "stream_id" => $head["id"],
                        "event_id" => (string) $event->eventId(),
                        "position" => $head["version"] + 1,
                        "event_type" => $event->eventType(),
                        "schema_version" => $described[$index]["schema_version"],
                        "recorded_at" => $this->clock
                            ->now()
                            ->setTimezone(new DateTimeZone("UTC"))
                            ->format("Y-m-d\TH:i:s.u\Z"),
                        "previous_hash" => $head["head_hash"],
                    ];
                    $this->date($row["recorded_at"]);
                    $row["sealed_event"] = $this->codec->seal(
                        "history.event.v1",
                        $row + [
                            "stream_type" => $stream->type,
                            "aggregate_id" => (string) $stream->aggregateId,
                            "operation_id" => $operation->value,
                            "context" => $context->describe(),
                            "event" => $described[$index],
                        ],
                    );
                    $row["event_hash"] = hash("sha256", ProtectedEventCodec::canonical($row));
                    $this->sql->insert("sis_history_events", $row);
                    $head["version"] = $row["position"];
                    $head["head_hash"] = $row["event_hash"];
                    $ids[] = $row["event_id"];
                }
                $plainHead = $head;
                unset($plainHead["sealed_anchor"]);
                $head["sealed_anchor"] = $this->codec->seal("history.head.v1", $plainHead);
                if (
                    $this->sql->update(
                        "sis_history_streams",
                        [
                            "version" => $head["version"],
                            "head_hash" => $head["head_hash"],
                            "sealed_anchor" => $head["sealed_anchor"],
                        ],
                        ["id" => $head["id"], "version" => $expectedVersion],
                    ) !== 1
                ) {
                    throw new ConcurrentModificationException("The historical stream version changed.");
                }
                $result = [
                    "operation_id" => $operation->value,
                    "first_version" => $first,
                    "last_version" => $head["version"],
                    "event_ids" => $ids,
                ];
                $this->sql->insert("sis_history_operations", [
                    "id" => $operation->value,
                    "stream_id" => $head["id"],
                    "sealed_receipt" => $this->codec->seal("history.receipt.v1", [
                        "stream_id" => $head["id"],
                        "operation_id" => $operation->value,
                        "actor_id" => $context->actor?->value,
                        "fingerprint" => $fingerprint,
                        "result" => $result,
                    ]),
                ]);
                return $result;
            });
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ["23000", "23505", "40001", "40P01"], true)) {
                throw new ConcurrentModificationException("The historical write conflicted with another operation.");
            }
            throw $error;
        }
    }

    /**
     * @param list<DomainEvent> $events
     * @return array{0: list<array<mixed>>, 1: string}
     */
    private function prepare(
        StreamReference $stream,
        int $expectedVersion,
        #[\SensitiveParameter] EventContext $context,
        #[\SensitiveParameter] array $events,
    ): array {
        $this->requireMigrationComplete();
        if ($context->effectiveAt !== null) {
            $this->date($context->describe()["effective_at"] ?? "");
        }
        if (
            $expectedVersion < 0 ||
            $expectedVersion > 2000000000 ||
            count($events) < 1 ||
            count($events) > 50 ||
            $expectedVersion + count($events) > 2000000000
        ) {
            throw new InvalidArgumentException("A bounded event batch and expected stream version are required.");
        }
        $described = [];
        $intents = [];
        $size = 0;
        foreach ($events as $index => $event) {
            if (
                (string) $event->aggregateId() !== (string) $stream->aggregateId ||
                $event->metaParam(Metadata::AGGREGATE_TYPE) !== $stream->type ||
                $event->playhead() !== $expectedVersion + $index + 1
            ) {
                throw new InvalidArgumentException("Events must belong to the expected contiguous stream.");
            }
            $canonical = ProtectedEventCodec::canonical($this->schemas->describe($event));
            $data = json_decode($canonical, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new CorruptHistoryException();
            }
            if (isset($data["payload"]["actor_id"]) && $data["payload"]["actor_id"] !== $context->actor?->value) {
                throw new InvalidArgumentException("Event and command actors must match.");
            }
            $length = strlen($canonical);
            $size += $length;
            // Course definitions have a reviewed 10,000-character Unicode description contract.
            // Keep other events at 64 KiB and retain the existing 256 KiB batch ceiling.
            $maximum = $event instanceof \Domain\Catalog\Event\CourseDefinitionWasPublished ? 262144 : 65536;
            if ($length > $maximum || $size > 262144) {
                throw new InvalidArgumentException("The protected event batch exceeds its size limit.");
            }
            $described[] = $data;
            // Generated event IDs and creation clock values are not the command's business intent.
            unset($data["source_recorded_at"]);
            $intents[] = $data;
        }
        $fingerprint = hash(
            "sha256",
            ProtectedEventCodec::canonical([
                "stream_type" => $stream->type,
                "aggregate_id" => (string) $stream->aggregateId,
                "expected_version" => $expectedVersion,
                "context" => $context->describe(),
                "events" => $intents,
            ]),
        );
        return [$described, $fingerprint];
    }

    public function page(StreamReference $stream, ?string $cursor = null, int $limit = 50): EventPage
    {
        $this->requireMigrationComplete();
        if ($limit < 1 || $limit > 100 || ($cursor !== null && strlen($cursor) > 16384)) {
            throw new InvalidArgumentException("Choose a historical page size from 1 to 100 and a valid cursor.");
        }
        return $this->transactions->run(function () use ($stream, $cursor, $limit): EventPage {
            $head =
                $this->sql->rows(
                    "sis_history_streams",
                    [
                        "stream_type" => $stream->type,
                        "aggregate_id" => (string) $stream->aggregateId,
                    ],
                    1,
                )[0] ?? null;
            if ($head === null) {
                if ($cursor !== null) {
                    throw new CorruptHistoryException();
                }
                return new EventPage([], null, 0);
            }
            $this->verifyHead($head, $stream);
            $state =
                $cursor === null
                    ? [
                        "stream_id" => $head["id"],
                        "stream_type" => $stream->type,
                        "aggregate_id" => (string) $stream->aggregateId,
                        "through_version" => $head["version"],
                        "through_hash" => $head["head_hash"],
                        "after" => 0,
                        "after_hash" => self::ZERO,
                    ]
                    : $this->codec->open("history.cursor.v1", $cursor);
            if (
                ($state["stream_id"] ?? null) !== $head["id"] ||
                ($state["stream_type"] ?? null) !== $stream->type ||
                ($state["aggregate_id"] ?? null) !== (string) $stream->aggregateId ||
                !is_int($state["through_version"] ?? null) ||
                !is_int($state["after"] ?? null) ||
                !is_string($state["through_hash"] ?? null) ||
                !is_string($state["after_hash"] ?? null) ||
                $state["through_version"] > $head["version"] ||
                $state["after"] < 0 ||
                $state["after"] > $state["through_version"]
            ) {
                throw new CorruptHistoryException();
            }
            $q = $this->sql->quote(...);
            $rows = $this->sql->fetch(
                "SELECT * FROM " .
                    $q("sis_history_events") .
                    " WHERE " .
                    $q("stream_id") .
                    " = ? AND " .
                    $q("position") .
                    " > ? AND " .
                    $q("position") .
                    " <= ? ORDER BY " .
                    $q("position"),
                [$head["id"], $state["after"], $state["through_version"]],
                $limit,
            );
            if (count($rows) !== min($limit, $state["through_version"] - $state["after"])) {
                throw new CorruptHistoryException();
            }
            $events = [];
            foreach ($rows as $row) {
                if ((int) $row["position"] !== $state["after"] + 1 || $row["previous_hash"] !== $state["after_hash"]) {
                    throw new CorruptHistoryException();
                }
                $events[] = $this->decode($stream, $row);
                $state["after"]++;
                $state["after_hash"] = $row["event_hash"];
            }
            $done = $state["after"] === $state["through_version"];
            if ($done && $state["after_hash"] !== $state["through_hash"]) {
                throw new CorruptHistoryException();
            }
            return new EventPage(
                $events,
                $done ? null : $this->codec->seal("history.cursor.v1", $state),
                $state["through_version"],
            );
        });
    }

    public function latest(StreamReference $stream): ?StoredEvent
    {
        return $this->transactions->run(function () use ($stream): ?StoredEvent {
            $head = $this->sql->rows('sis_history_streams', [
                'stream_type' => $stream->type, 'aggregate_id' => (string) $stream->aggregateId,
            ], 1)[0] ?? null;
            if ($head === null) {
                return null;
            }
            $this->verifyHead($head, $stream);
            if ($head['version'] === 0) {
                if ($head['head_hash'] !== self::ZERO) {
                    throw new CorruptHistoryException();
                }
                return null;
            }
            $last = $this->sql->rows('sis_history_events', [
                'stream_id' => $head['id'], 'position' => $head['version'],
            ], 1)[0] ?? null;
            if ($last === null || $last['event_hash'] !== $head['head_hash']) {
                throw new CorruptHistoryException();
            }
            return $this->decode($stream, $last);
        });
    }

    /** @param array<string, scalar|null> $criteria
     * @return array<string, mixed>|null
     */
    private function current(string $table, array $criteria): ?array
    {
        $q = $this->sql->quote(...);
        $where = implode(
            " AND ",
            array_map(static fn(string $key): string => $q($key) . " = ?", array_keys($criteria)),
        );
        if ($this->sql->driver() === "sqlite" && $table === "sis_history_streams") {
            $this->sql->execute(
                "UPDATE " . $q($table) . " SET " . $q("version") . " = " . $q("version") . " WHERE " . $where,
                array_values($criteria),
            );
        }
        $from = $q($table) . ($this->sql->driver() === "sqlsrv" ? " WITH (UPDLOCK, HOLDLOCK)" : "");
        $suffix = in_array($this->sql->driver(), ["mysql", "pgsql", "oci"], true) ? " FOR UPDATE" : "";
        // Each lookup is constrained by a unique key; do not wrap locking SQL in pagination subqueries.
        return $this->sql->fetch("SELECT * FROM " . $from . " WHERE " . $where . $suffix, array_values($criteria))[0] ??
        null;
    }

    /** @param array<string, mixed> $head */
    private function verifyHead(array &$head, StreamReference $stream): void
    {
        $head["version"] = $this->integer($head["version"]);
        $plain = $head;
        unset($plain["sealed_anchor"]);
        $sealed = $this->codec->open("history.head.v1", $head["sealed_anchor"]);
        if (
            $head["version"] < 0 ||
            $head["stream_type"] !== $stream->type ||
            $head["aggregate_id"] !== (string) $stream->aggregateId ||
            ProtectedEventCodec::canonical($plain) !== ProtectedEventCodec::canonical($sealed)
        ) {
            throw new CorruptHistoryException();
        }
    }

    /** @param array<string, mixed> $row */
    private function decode(StreamReference $stream, array $row, ?EntityId $operation = null): StoredEvent
    {
        try {
            $row["position"] = $this->integer($row["position"]);
            $row["schema_version"] = $this->integer($row["schema_version"]);
            $hash = $row["event_hash"];
            unset($row["event_hash"]);
            if (!hash_equals($hash, hash("sha256", ProtectedEventCodec::canonical($row)))) {
                throw new CorruptHistoryException();
            }
            $data = $this->codec->open("history.event.v1", $row["sealed_event"]);
            $bind = $row;
            unset($bind["sealed_event"]);
            foreach ($bind as $key => $value) {
                if (($data[$key] ?? null) !== $value) {
                    throw new CorruptHistoryException();
                }
            }
            if (
                ($operation !== null && ($data["operation_id"] ?? null) !== $operation->value) ||
                $data["stream_type"] !== $stream->type ||
                $data["aggregate_id"] !== (string) $stream->aggregateId ||
                $data["schema_version"] !== $data["event"]["schema_version"] ||
                $data["event_type"] !== $data["event"]["type"]
            ) {
                throw new CorruptHistoryException();
            }
            $c = $data["context"];
            if (
                isset($data["event"]["payload"]["actor_id"]) &&
                $data["event"]["payload"]["actor_id"] !== $c["actor_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $effective =
            $c["effective_at"] === null
            ? null
            : $this->date($c["effective_at"])->setTimezone(new DateTimeZone($c["effective_timezone"]));
            $context = new EventContext(
                $c["actor_id"] === null ? null : new EntityId($c["actor_id"]),
                $effective,
                $c["correlation_id"] === null ? null : new EntityId($c["correlation_id"]),
                $c["causation_id"] === null ? null : new EntityId($c["causation_id"]),
                $c["reason"],
                $c["approval_id"] === null ? null : new EntityId($c["approval_id"]),
                $c["origin"],
                $c["source_transaction_id"],
                $c["legacy_record"],
            );
            return new StoredEvent(
                new EntityId($row["id"]),
                $this->schemas->restore($stream, $row["event_id"], $row["position"], $data["event"]),
                $context,
                $this->date($row["recorded_at"]),
                $hash,
            );
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function verifyOperation(EntityId $operation): int
    {
        $this->requireMigrationComplete();
        try {
            return $this->transactions->run(function () use ($operation): int {
                $row = $this->sql->rows("sis_history_operations", ["id" => $operation->value], 1)[0] ?? null;
                if ($row === null) {
                    throw new CorruptHistoryException();
                }
                $saved = $this->codec->open("history.receipt.v1", $row["sealed_receipt"]);
                if (
                    count($saved) !== 5 ||
                    ($saved["operation_id"] ?? null) !== $operation->value ||
                    ($saved["stream_id"] ?? null) !== $row["stream_id"] ||
                    !array_key_exists("actor_id", $saved) ||
                    !is_string($saved["fingerprint"] ?? null) ||
                    preg_match('/^[a-f0-9]{64}$/D', $saved["fingerprint"]) !== 1 ||
                    !is_array($saved["result"] ?? null)
                ) {
                    throw new CorruptHistoryException();
                }
                new EntityId($row["stream_id"]);
                if ($saved["actor_id"] !== null) {
                    new EntityId($saved["actor_id"]);
                }
                $result = $this->receiptResult($saved["result"]);
                $head = $this->sql->rows("sis_history_streams", ["id" => $row["stream_id"]], 1)[0] ?? null;
                if ($head === null || $result["operation_id"] !== $operation->value) {
                    throw new CorruptHistoryException();
                }
                $stream = $this->schemas->stream($head["stream_type"], $head["aggregate_id"]);
                $this->verifyHead($head, $stream);
                if ($result["last_version"] > $head["version"]) {
                    throw new CorruptHistoryException();
                }
                $q = $this->sql->quote(...);
                $rows = $this->sql->fetch(
                    "SELECT * FROM " .
                        $q("sis_history_events") .
                        " WHERE " .
                        $q("stream_id") .
                        " = ? AND " .
                        $q("position") .
                        " >= ? AND " .
                        $q("position") .
                        " <= ? ORDER BY " .
                        $q("position"),
                    [$row["stream_id"], $result["first_version"], $result["last_version"]],
                    50,
                );
                if (count($rows) !== count($result["event_ids"])) {
                    throw new CorruptHistoryException();
                }
                $events = [];
                $context = null;
                foreach ($rows as $index => $eventRow) {
                    if (
                        $this->integer($eventRow["position"]) !== $result["first_version"] + $index ||
                        $eventRow["event_id"] !== $result["event_ids"][$index]
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $stored = $this->decode($stream, $eventRow, $operation);
                    if (
                        $stored->context->actor?->value !== $saved["actor_id"] ||
                        ($context !== null &&
                            ProtectedEventCodec::canonical($context->describe()) !==
                                ProtectedEventCodec::canonical($stored->context->describe()))
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $context ??= $stored->context;
                    $events[] = $stored->event;
                }
                if ($context === null) {
                    throw new CorruptHistoryException();
                }
                [, $fingerprint] = $this->prepare($stream, $result["first_version"] - 1, $context, $events);
                if (!hash_equals($saved["fingerprint"], $fingerprint)) {
                    throw new CorruptHistoryException();
                }
                return count($events);
            });
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    /** @param array<string, mixed> $value
     * @return array{operation_id: string, first_version: int, last_version: int, event_ids: list<string>}
     */
    private function receiptResult(array $value): array
    {
        if (
            !is_string($value["operation_id"] ?? null) ||
            !is_int($value["first_version"] ?? null) ||
            !is_int($value["last_version"] ?? null) ||
            !is_array($value["event_ids"] ?? null) ||
            !array_is_list($value["event_ids"]) ||
            count($value["event_ids"]) < 1 ||
            count($value["event_ids"]) > 50 ||
            $value["first_version"] < 1 ||
            $value["last_version"] - $value["first_version"] + 1 !== count($value["event_ids"])
        ) {
            throw new CorruptHistoryException();
        }
        foreach ($value["event_ids"] as $id) {
            if (!is_string($id)) {
                throw new CorruptHistoryException();
            }
        }
        return [
            "operation_id" => $value["operation_id"],
            "first_version" => $value["first_version"],
            "last_version" => $value["last_version"],
            "event_ids" => $value["event_ids"],
        ];
    }

    private function requireMigrationComplete(): void
    {
        if ($this->convertingLegacy || $this->migrationVerified) {
            return;
        }
        // Resolve lazily, once per adapter instance; normal appends/pages need no repeated schema scan.
        if ($this->sql->tableExists("event_store")) {
            throw new \LogicException("Complete the single event-store migration before using event history.");
        }
        $this->migrationVerified = true;
    }

    private function integer(mixed $value): int
    {
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > 2000000000) {
            throw new CorruptHistoryException();
        }
        return $value;
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat("!Y-m-d\TH:i:s.u\Z", $value, new DateTimeZone("UTC"));
        if ($date === false || $date->format("Y-m-d\TH:i:s.u\Z") !== $value) {
            throw new CorruptHistoryException();
        }
        return $date;
    }
}
