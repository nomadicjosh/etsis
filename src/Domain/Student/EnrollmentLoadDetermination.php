<?php

declare(strict_types=1);

namespace Domain\Student;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Student\Event\EnrollmentLoadWasDetermined;
use Domain\Student\ValueObject\LoadDeterminationSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;

/** Each determination is an immutable decision. A later determination does not replace this fact. */
final class EnrollmentLoadDetermination extends EventSourcedAggregate
{
    public const array FIELDS = ["operation_id", "snapshot", "intent"];
    private ?array $data = null;
    public static function validate(array $data): void
    {
        if (
            count($data) !== 3 ||
            array_diff(array_keys($data), self::FIELDS) !== [] ||
            !is_string($data["intent"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $data["intent"]) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        new \Domain\Shared\ValueObject\EntityId($data["operation_id"]);
        new LoadDeterminationSnapshot($data["snapshot"]);
    }
    public function accept(StoredEvent $stored): void
    {
        $event = $stored->event;
        if (
            !($event instanceof EnrollmentLoadWasDetermined) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== 1 ||
            $this->playhead() !== 0 ||
            $stored->context->origin !== "command" ||
            $stored->context->actor === null ||
            $stored->context->reason === null ||
            $stored->context->effectiveAt === null ||
            $stored->context->correlation?->value !== (string) $this->aggregateId() ||
            $stored->context->effectiveAt->format("Y-m-d") !==
                $event->payload()["snapshot"]["as_of_date"]
        ) {
            throw new CorruptHistoryException();
        }
        $snapshot = new LoadDeterminationSnapshot($event->payload()["snapshot"]);
        $d = $snapshot->data;
        $intent = hash(
            "sha256",
            json_encode(
                [
                    $d["student_id"],
                    $d["pursuit_id"],
                    $d["term"]["id"],
                    $snapshot->fingerprint(),
                    trim($stored->context->reason),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        if (!hash_equals($intent, $event->payload()["intent"])) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = 1;
    }
    public function whenEnrollmentLoadWasDetermined(EnrollmentLoadWasDetermined $event): void
    {
        self::validate($data = $event->payload());
        if ($this->data !== null || $data["operation_id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->data = $data;
    }
    public function snapshot(): ?LoadDeterminationSnapshot
    {
        return $this->data === null ? null : new LoadDeterminationSnapshot($this->data["snapshot"]);
    }
}
