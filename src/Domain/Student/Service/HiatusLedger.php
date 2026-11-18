<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Communications\Service\MessageCipher;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\HiatusCaseWasRecorded;
use Domain\Student\HiatusCase;
use Domain\Student\Repository\HiatusRepository;
use Domain\Student\StudentHiatus;
use Domain\Student\ValueObject\HiatusCaseId;
use Domain\Student\ValueObject\HiatusFacts;

/** Internal ledger. Calling services own registrar authorization and the student/case transaction locks. */
final readonly class HiatusLedger
{
    public function __construct(
        private HiatusRepository $records,
        private HistoricalEventStore $history,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** A bounded head check, not a full integrity scan. No event or projection writes.
     * @return array{tracked:bool,position:int} */
    public function verify(StudentHiatus $current): array
    {
        $stored = $this->history->latest($this->stream($current->id));
        $this->projection(
            $current,
            $stored?->event->payload(),
            $stored?->event->playhead() ?? 0,
        );
        if ($stored !== null) {
            $row = $this->records->action(
                new EntityId($stored->event->payload()["operation_id"]),
            );
            if (
                $row === null ||
                $stored->event->payload()["action"] === "observed"
            ) {
                throw new CorruptHistoryException();
            }
            $this->evidence($stored, $row);
        }
        return [
            "tracked" => $stored !== null,
            "position" => $stored?->event->playhead() ?? 0,
        ];
    }

    /** Append a real case decision; caller projects the returned position/time atomically.
     * @param array<string,mixed> $request
     * @param array<string,mixed>|null $result
     * @return array{position:int,confirmed_at:string} */
    public function record(
        ?StudentHiatus $before,
        StudentHiatus $next,
        EntityId $operation,
        EntityId $actor,
        string $action,
        #[\SensitiveParameter] array $request,
        ?array $result = null,
    ): array {
        [$root] = $this->load($next->id);
        if ($before === null) {
            if (
                $root->current() !== null ||
                $this->records->find($next->id) !== null ||
                $action !== "created"
            ) {
                throw new ConcurrentModificationException(
                    "This hiatus operation already exists.",
                );
            }
        } else {
            $this->projection($before, $root->current(), $root->playhead());
        }
        $now = $this->clock->now();
        $confirmed = $now
            ->setTimezone(new DateTimeZone("UTC"))
            ->format(DATE_ATOM);
        if ($before !== null && $root->current() === null) {
            $op = PublishedGradeId::forVersion($operation, "hiatus.observe");
            $observed = HiatusCaseWasRecorded::occur(
                $this->stream($next->id)->aggregateId,
                [
                    "operation_id" => $op->value,
                    "previous_operation_id" => null,
                    "action" => "observed",
                    "facts" => HiatusFacts::fromHiatus($before)->data,
                    "request" => null,
                    "request_hash" => null,
                    "result" => null,
                    "return_operation_id" =>
                        $this->records->returned($before->id)["id"] ?? null,
                    "previous_ends_on" => null,
                    "confirmed_at" => $confirmed,
                ],
                ["__aggregate_type" => "hiatus_case"],
            )->withPlayhead(1);
            $root->acceptHistorical($observed);
            $this->history->append(
                $this->stream($next->id),
                $op,
                0,
                new EventContext(
                    $actor,
                    $now,
                    $operation,
                    reason: "Current hiatus observed; earlier decision history remains unknown.",
                ),
                [$observed],
            );
        }
        if ($root->playhead() >= 1000) {
            throw new ConcurrentModificationException(
                "This case requires an operator history review before another decision.",
            );
        }
        $note = $request["note"] ?? null;
        if (!is_string($note) || trim($note) === "") {
            throw new \InvalidArgumentException(
                "A protected case reason is required.",
            );
        }
        $p = [
            "operation_id" => $operation->value,
            "previous_operation_id" => $root->current()["operation_id"] ?? null,
            "action" => $action,
            "facts" => HiatusFacts::fromHiatus($next)->data,
            "request" => array_diff_key($request, ["note" => true]),
            "request_hash" => self::requestHash($next->id, $actor, $request),
            "result" => $result,
            "return_operation_id" =>
                $action === "returned"
                    ? $operation->value
                    : $root->current()["return_operation_id"] ?? null,
            "previous_ends_on" => $before?->dates->end?->format("Y-m-d"),
            "confirmed_at" => $confirmed,
        ];
        $event = HiatusCaseWasRecorded::occur(
            $this->stream($next->id)->aggregateId,
            $p,
            ["__aggregate_type" => "hiatus_case"],
        )->withPlayhead($root->playhead() + 1);
        $expected = $root->playhead();
        $root->acceptHistorical($event);
        $effective = match ($action) {
            "created" => $next->dates->start->format("Y-m-d"),
            "end_changed" => $next->dates->end?->format("Y-m-d") ??
            throw new CorruptHistoryException(),
            "returned" => $request["on"],
            default => $now->format("Y-m-d"),
        };
        $this->history->append(
            $this->stream($next->id),
            $operation,
            $expected,
            new EventContext(
                $actor,
                new DateTimeImmutable($effective, $now->getTimezone()),
                $operation,
                reason: $note,
            ),
            [$event],
        );
        return ["position" => $root->playhead(), "confirmed_at" => $confirmed];
    }

    /** Verify an accepted retry without repeating its effects. Earlier receipts remain explicitly untracked.
     * @param array<string,mixed> $request */
    public function retry(
        StudentHiatus $current,
        EntityId $operation,
        EntityId $actor,
        string $action,
        #[\SensitiveParameter] array $request,
    ): void {
        [$root, $stored] = $this->load($current->id, $operation);
        $this->projection($current, $root->current(), $root->playhead());
        if ($stored === null) {
            if ($root->current() !== null && !$root->observed()) {
                throw new CorruptHistoryException();
            }
            return;
        }
        $p = $stored->event->payload();
        if (
            $p["action"] !== $action ||
            $stored->context->actor?->value !== $actor->value ||
            !hash_equals(
                $p["request_hash"] ?? "",
                self::requestHash($current->id, $actor, $request),
            )
        ) {
            throw new ConcurrentModificationException(
                "This operation belongs to a different hiatus change.",
            );
        }
        $row =
        $this->records->action($operation) ??
        throw new CorruptHistoryException();
        $this->evidence($stored, $row);
    }

    /** Verify a bounded action page in one bounded replay, without exposing raw events.
     * @param list<array<string,mixed>> $rows
     * @return array<string,bool> */
    public function evidencePage(StudentHiatus $current, array $rows): array
    {
        $wanted = array_fill_keys(array_column($rows, "id"), true);
        [$root, , $events] = $this->load($current->id, wanted: $wanted);
        $this->projection($current, $root->current(), $root->playhead());
        $result = [];
        foreach ($rows as $row) {
            $stored = $events[$row["id"]] ?? null;
            if ($stored === null) {
                if ($root->current() !== null && !$root->observed()) {
                    throw new CorruptHistoryException();
                }
                $result[$row["id"]] = false;
                continue;
            }
            $this->evidence($stored, $row);
            $result[$row["id"]] = true;
        }
        return $result;
    }

    /** Pure reconstruction input for the existing encrypted case-evidence projection.
     * @return array<string,mixed> */
    public static function evidencePayload(StoredEvent $stored): array
    {
        $p = $stored->event->payload();
        if ($p["action"] === "observed" || $stored->context->reason === null) {
            throw new CorruptHistoryException();
        }
        $request = $p["request"] + ["note" => $stored->context->reason];
        $data = [
            "request" => $request,
            "previous_ends_on" => $p["previous_ends_on"],
            "new_ends_on" => $p["facts"]["ends_on"],
        ];
        if ($p["action"] === "returned") {
            $data["return_result"] = $p["result"];
        } else {
            $data["impact_result"] =
            $p["action"] === "created" ? $p["result"] : null;
        }
        return $data;
    }

    /** @param array<string,mixed> $row */
    private function evidence(
        StoredEvent $stored,
        #[\SensitiveParameter] array $row,
    ): void {
        $p = $stored->event->payload();
        $data = json_decode(
            $this->cipher->open($row["encrypted_content"]),
            true,
            24,
            JSON_THROW_ON_ERROR,
        );
        if (
            $row["id"] !== $p["operation_id"] ||
            $row["hiatus_id"] !== $p["facts"]["id"] ||
            $row["actor_id"] !== $stored->context->actor?->value ||
            $row["action"] !== $p["action"] ||
            $row["created_at"] !== $p["confirmed_at"] ||
            self::canonical($data) !==
                self::canonical(self::evidencePayload($stored)) ||
            !hash_equals(
                $p["request_hash"],
                self::requestHash(
                    new EntityId($p["facts"]["id"]),
                    $stored->context->actor ??
                        throw new CorruptHistoryException(),
                    $data["request"],
                ),
            )
        ) {
            throw new CorruptHistoryException();
        }
    }

    /** @param array<string,mixed>|null $p */
    private function projection(
        StudentHiatus $current,
        ?array $p,
        int $head,
    ): void {
        $position = $this->records->historyPosition($current->id);
        if ($p === null) {
            if ($position !== null) {
                throw new CorruptHistoryException();
            }
            return;
        }
        if (
            $position !== $head ||
            self::canonical($p["facts"]) !==
                self::canonical(HiatusFacts::fromHiatus($current)->data) ||
            $p["return_operation_id"] !==
                ($this->records->returned($current->id)["id"] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
    }

    /** @param array<string,true> $wanted
     * @return array{HiatusCase,?StoredEvent,array<string,StoredEvent>} */
    private function load(
        EntityId $id,
        ?EntityId $operation = null,
        array $wanted = [],
    ): array {
        $root = HiatusCase::root(new HiatusCaseId($id->value));
        $found = null;
        $events = [];
        $cursor = null;
        $pages = 0;
        do {
            if (++$pages > 50) {
                throw new CorruptHistoryException();
            }
            $page = $this->history->page($this->stream($id), $cursor, 20);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                $op = $stored->event->payload()["operation_id"];
                if ($op === $operation?->value) {
                    if ($found !== null) {
                        throw new CorruptHistoryException();
                    }
                    $found = $stored;
                }
                if (isset($wanted[$op])) {
                    $events[$op] = $stored;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return [$root, $found, $events];
    }

    /** @param array<string,mixed> $request */
    private static function requestHash(
        EntityId $case,
        EntityId $actor,
        #[\SensitiveParameter] array $request,
    ): string {
        return hash(
            "sha256",
            self::canonical([
                "hiatus_id" => $case->value,
                "actor_id" => $actor->value,
                "request" => $request,
            ]),
        );
    }

    public static function same(mixed $left, mixed $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }

    private static function canonical(mixed $value): string
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }
            return array_map($normalize, $value);
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR);
    }

    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new HiatusCaseId($id->value), "hiatus_case");
    }
}
