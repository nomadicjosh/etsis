<?php

declare(strict_types=1);

namespace Domain\HumanResources;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\HumanResources\Event\TimeEntryWasDecided;
use Domain\HumanResources\ValueObject\DefinitionRules;
use Domain\HumanResources\ValueObject\TimeApprovalRules;
use Domain\HumanResources\ValueObject\TimeReviewEvidence;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\ValueObject\EntityId;

/** One genuine decision per submitted interval; earlier unrecorded decisions are never synthesized. */
final class TimeDecision extends EventSourcedAggregate
{
    public const array FIELDS = [
        "operation_id",
        "entry_id",
        "employee_id",
        "position_id",
        "position_version",
        "evidence",
        "next_status",
        "exception_reason",
        "intent",
    ];
    private ?array $decision = null;
    public static function validate(#[\SensitiveParameter] array $data): void
    {
        TimeReviewEvidence::keys($data, self::FIELDS);
        foreach (["operation_id", "entry_id", "employee_id"] as $key) {
            new EntityId($data[$key]);
        }
        if (($data["position_id"] === null) !== ($data["position_version"] === null)) {
            throw new CorruptHistoryException();
        }
        if ($data["position_id"] !== null) {
            new EntityId($data["position_id"]);
            if (
                !is_int($data["position_version"]) ||
                $data["position_version"] < 1 ||
                $data["position_version"] > 2000
            ) {
                throw new CorruptHistoryException();
            }
        }
        $evidence = new TimeReviewEvidence($data["evidence"]);
        if ($data["exception_reason"] !== null) {
            DefinitionRules::reason($data["exception_reason"]);
        }
        if (
            $data["employee_id"] !== $evidence->data["employee_id"] ||
            $data["entry_id"] !== $evidence->data["target"]["id"] ||
            $evidence->data["target"]["status"] !== "pending" ||
            ($data["next_status"] === "approved" && $evidence->data["scope"] === "rejection") ||
            !in_array($data["next_status"], ["approved", "rejected"], true) ||
            ($data["next_status"] === "approved" &&
                $evidence->summary["requires_exception"] &&
                $data["exception_reason"] === null) ||
            !is_string($data["intent"]) ||
            !hash_equals(
                $data["intent"],
                TimeApprovalRules::hash([
                    $data["evidence"],
                    $data["next_status"],
                    $data["exception_reason"],
                ]),
            )
        ) {
            throw new CorruptHistoryException();
        }
    }
    public function accept(#[\SensitiveParameter] StoredEvent $stored): void
    {
        $event = $stored->event;
        $p = $event->payload();
        self::validate($p);
        if (
            !($event instanceof TimeEntryWasDecided) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== 1 ||
            $stored->context->origin !== "command" ||
            $stored->context->actor === null ||
            $stored->context->correlation?->value !== $p["operation_id"] ||
            $stored->context->reason !== ($p["exception_reason"] ?? "Reviewed time-entry decision.")
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = 1;
        $this->decision["actor_id"] = $stored->context->actor->value;
        $this->decision["recorded_at"] = $stored->context->effectiveAt?->format(DATE_ATOM);
    }
    public function whenTimeEntryWasDecided(TimeEntryWasDecided $event): void
    {
        $p = $event->payload();
        self::validate($p);
        if ($this->decision !== null || $p["entry_id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->decision = $p;
    }
    public function decision(): ?array
    {
        return $this->decision;
    }
    public function __debugInfo(): array
    {
        return ["decision" => "[protected time-entry decision]"];
    }
}
