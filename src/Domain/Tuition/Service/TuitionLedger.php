<?php

declare(strict_types=1);

namespace Domain\Tuition\Service;

use Domain\Finance\ValueObject\LedgerSchema;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Tuition\Event\TuitionAssignmentWasRecorded;
use Domain\Tuition\Event\TuitionBillActionWasRecorded;
use Domain\Tuition\Event\TuitionScheduleWasPublished;
use Domain\Tuition\Repository\TuitionRepository;
use Domain\Tuition\TuitionBill;
use Domain\Tuition\ValueObject\TuitionAssignmentId;
use Domain\Tuition\ValueObject\TuitionBillId;
use Domain\Tuition\ValueObject\TuitionScheduleId;

/** Internal selected source access. Callers authorize and own the finance gate and transaction. */
final readonly class TuitionLedger
{
    public function __construct(
        public TuitionRepository $records,
        private HistoricalEventStore $history,
    ) {
    }

    public static function assignmentId(string $scope, EntityId $id): EntityId
    {
        return \Domain\Gradebook\ValueObject\PublishedGradeId::forVersion(
            $id,
            "tuition-assignment:" . $scope,
        );
    }

    public static function billId(EntityId $student, EntityId $term): EntityId
    {
        return \Domain\Gradebook\ValueObject\PublishedGradeId::forVersion(
            $student,
            "tuition-bill:" . $term->value,
        );
    }

    private function settingStream(string $kind, EntityId $id): StreamReference
    {
        return $kind === "schedules"
        ? new StreamReference(
            new TuitionScheduleId($id->value),
            "tuition_schedule",
        )
        : new StreamReference(
            new TuitionAssignmentId($id->value),
            "tuition_assignment",
        );
    }

    public function setting(string $kind, EntityId $id): ?array
    {
        $row = $this->records->find($kind, $id);
        $stored = $this->history->latest($this->settingStream($kind, $id));
        if ($stored === null && $row === null) {
            return null;
        }
        $p = $stored?->event->payload();
        if (
            $stored === null ||
            $row === null ||
            (int) $row["version"] !== $stored->event->playhead() ||
            $row["event_id"] !== (string) $stored->event->eventId() ||
            $row["source_hash"] !== LedgerSchema::hash($p["definition"]) ||
            $p["intent"] !==
                LedgerSchema::hash([
                    $p["definition"],
                    $stored->context->reason,
                ]) ||
            $stored->context->origin !== "command" ||
            $stored->context->actor === null ||
            $stored->context->correlation?->value !== $p["operation_id"]
        ) {
            throw new CorruptHistoryException();
        }
        if ($kind === "schedules") {
            if (
                !($stored->event instanceof TuitionScheduleWasPublished) ||
                $row["name"] !== $p["definition"]["name"] ||
                $row["currency"] !== $p["definition"]["currency"]
            ) {
                throw new CorruptHistoryException();
            }
        } elseif (
            !($stored->event instanceof TuitionAssignmentWasRecorded) ||
            $row["scope"] !== $p["definition"]["scope"] ||
            $row["scope_id"] !== $p["definition"]["scope_id"] ||
            $row["schedule_id"] !== $p["definition"]["schedule_id"]
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "id" => $id->value,
            "version" => $stored->event->playhead(),
            "event_id" => (string) $stored->event->eventId(),
            "definition" => $p["definition"],
        ];
    }

    public function saveSetting(
        string $kind,
        EntityId $id,
        array $d,
        int $expected,
        EventContext $context,
    ): void {
        $stream = $this->settingStream($kind, $id);
        $class =
        $kind === "schedules"
        ? TuitionScheduleWasPublished::class
        : TuitionAssignmentWasRecorded::class;
        $p = [
            "definition" => $d,
            "operation_id" => $context->correlation->value,
            "intent" => LedgerSchema::hash([$d, $context->reason]),
        ];
        $event = $class
        ::occur($stream->aggregateId, $p, [
                "__aggregate_type" => $stream->type,
            ])
            ->withPlayhead($expected + 1);
        $this->history->append(
            $stream,
            $context->correlation,
            $expected,
            $context,
            [$event],
        );
        $values =
        $kind === "schedules"
        ? ["name" => $d["name"], "currency" => $d["currency"]]
        : [
                    "scope" => $d["scope"],
                    "scope_id" => $d["scope_id"],
                    "schedule_id" => $d["schedule_id"],
                ];
        $this->records->save(
            $kind,
            $id,
            $values + [
                "version" => $expected + 1,
                "event_id" => (string) $event->eventId(),
                "source_hash" => LedgerSchema::hash($d),
            ],
            $expected,
        );
    }

    public function bill(
        EntityId $id,
        ?HistoryReadBudget $budget = null,
    ): TuitionBill {
        $budget ??= new HistoryReadBudget(
            maximumEvents: 500,
            maximumBytes: 8388608,
        );
        $root = TuitionBill::root(new TuitionBillId($id->value));
        $cursor = null;
        $through = null;
        $last = null;
        do {
            $page = $budget->page(
                $this->history,
                new StreamReference($root->aggregateId(), "tuition_bill"),
                $cursor,
            );
            $through ??= $page->throughVersion;
            if ($through !== $page->throughVersion) {
                throw new CorruptHistoryException();
            }
            foreach ($page->events as $stored) {
                $last = (string) $stored->event->eventId();
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $row = $this->records->find("bills", $id);
        $s = $root->state();
        if (
            $root->playhead() !== $through ||
            ($row === null) !== ($root->playhead() === 0) ||
            ($row !== null &&
                ($row["event_id"] !== $last ||
                    (int) $row["pending_count"] !==
                        count($root->pending()["lines"]) +
                            (int) ($root->pending()["adjustment_minor"] > 0) ||
                    (int) $row["version"] !== $root->playhead() ||
                    $row["source_hash"] !== LedgerSchema::hash($s) ||
                    $row["student_id"] !== $s["student_id"] ||
                    $row["term_id"] !== $s["term_id"] ||
                    $row["currency"] !==
                        $s["snapshot"]["definition"]["currency"]))
        ) {
            throw new CorruptHistoryException();
        }
        return $root;
    }

    public function record(
        EntityId $id,
        EntityId $operation,
        string $action,
        array $d,
        int $expected,
        EventContext $context,
    ): TuitionBill {
        $root = $this->bill($id);
        $intent = LedgerSchema::hash([$action, $d, $context->reason]);
        $old = $root->receipt($operation->value);
        if ($old !== null) {
            if (
                $old["intent"] !== $intent ||
                $old["actor"] !== $context->actor?->value
            ) {
                throw new ConcurrentModificationException(
                    "This billing operation belongs to another decision.",
                );
            }
            return $root;
        }
        if ($root->playhead() !== $expected) {
            throw new ConcurrentModificationException(
                "Pending charges changed. Review again.",
            );
        }
        $event = TuitionBillActionWasRecorded::occur(
            $root->aggregateId(),
            [
                "operation_id" => $operation->value,
                "action" => $action,
                "details" => $d,
                "intent" => $intent,
            ],
            ["__aggregate_type" => "tuition_bill"],
        )->withPlayhead($expected + 1);
        $stream = new StreamReference($root->aggregateId(), "tuition_bill");
        $this->history->append($stream, $operation, $expected, $context, [
            $event,
        ]);
        $stored =
        $this->history->latest($stream) ??
        throw new CorruptHistoryException();
        $root->accept($stored);
        $s = $root->state();
        $pending = $root->pending();
        $this->records->save(
            "bills",
            $id,
            [
                "student_id" => $s["student_id"],
                "term_id" => $s["term_id"],
                "currency" => $s["snapshot"]["definition"]["currency"],
                "pending_count" =>
                    count($pending["lines"]) +
                    (int) ($pending["adjustment_minor"] > 0),
                "version" => $root->playhead(),
                "event_id" => (string) $event->eventId(),
                "source_hash" => LedgerSchema::hash($s),
            ],
            $expected,
        );
        return $root;
    }
}
