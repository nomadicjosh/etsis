<?php

declare(strict_types=1);

namespace Domain\Gradebook;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Gradebook\Event\ClassGradesWereCertified;
use Domain\Gradebook\ValueObject\GradeCertificationSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\ValueObject\EntityId;

/** Accepted per-section ordering; each certification remains a separate immutable reviewed fact. */
final class GradeCertificationHistory extends EventSourcedAggregate
{
    public const array FIELDS = [
        "operation_id",
        "section_id",
        "previous_operation_id",
        "snapshot",
        "acknowledged_history_gaps",
        "intent",
    ];
    private array $records = [];
    public static function validate(#[\SensitiveParameter] array $data): void
    {
        GradeCertificationSnapshot::keys($data, self::FIELDS);
        foreach (["operation_id", "section_id"] as $key) {
            new EntityId($data[$key]);
        }
        if ($data["previous_operation_id"] !== null) {
            new EntityId($data["previous_operation_id"]);
        }
        $snapshot = new GradeCertificationSnapshot($data["snapshot"]);
        if (
            $snapshot->data["section"]["id"] !== $data["section_id"] ||
            !is_bool($data["acknowledged_history_gaps"]) ||
            ($snapshot->historyGaps && !$data["acknowledged_history_gaps"]) ||
            !is_string($data["intent"]) ||
            !preg_match('/^[a-f0-9]{64}$/D', $data["intent"])
        ) {
            throw new CorruptHistoryException();
        }
    }
    public function accept(StoredEvent $stored): void
    {
        $e = $stored->event;
        $p = $e->payload();
        self::validate($p);
        $at = $stored->context->effectiveAt;
        if (
            !($e instanceof ClassGradesWereCertified) ||
            !$e->aggregateId()->equals($this->aggregateId()) ||
            $e->playhead() !== $this->playhead() + 1 ||
            $stored->context->origin !== "command" ||
            $stored->context->actor === null ||
            $stored->context->reason === null ||
            $at === null ||
            $stored->context->correlation?->value !== $p["operation_id"] ||
            $at->format("Y-m-d") !== $p["snapshot"]["as_of_date"] ||
            $p["section_id"] !== (string) $this->aggregateId() ||
            !hash_equals(
                $p["intent"],
                self::intent(
                    new GradeCertificationSnapshot($p["snapshot"]),
                    $p["acknowledged_history_gaps"],
                    $stored->context->reason,
                ),
            )
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($e);
        $this->playhead = $e->playhead();
        $this->records[array_key_last($this->records)]["actor_id"] = $stored->context->actor->value;
        $this->records[array_key_last($this->records)]["reason"] = $stored->context->reason;
        $this->records[array_key_last($this->records)]["recorded_at"] = $at->format(DATE_ATOM);
    }
    public static function intent(
        GradeCertificationSnapshot $snapshot,
        bool $gaps,
        string $reason,
    ): string {
        return GradeCertificationSnapshot::hash([
            $snapshot->data["section"]["id"],
            $snapshot->fingerprint(),
            $gaps,
            trim($reason),
        ]);
    }
    public function whenClassGradesWereCertified(ClassGradesWereCertified $event): void
    {
        $p = $event->payload();
        self::validate($p);
        $previous = null;
        if ($this->records !== []) {
            $previous = $this->records[array_key_last($this->records)]["operation_id"];
        }
        if (
            $p["section_id"] !== (string) $this->aggregateId() ||
            $p["previous_operation_id"] !== $previous ||
            array_any(
                $this->records,
                static fn(array $row): bool => $row["operation_id"] === $p["operation_id"],
            )
        ) {
            throw new CorruptHistoryException();
        }
        $this->records[] = $p + ["version" => $event->playhead()];
    }
    public function records(): array
    {
        return $this->records;
    }
    public function __debugInfo(): array
    {
        return ["certification" => "[restricted grade certification history]"];
    }
}
