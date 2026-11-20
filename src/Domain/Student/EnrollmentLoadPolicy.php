<?php

declare(strict_types=1);

namespace Domain\Student;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\EnrollmentLoadPolicyWasPublished;
use Domain\Student\ValueObject\EnrollmentLoadBands;
use Domain\Student\ValueObject\EnrollmentLoadPolicyId;

final class EnrollmentLoadPolicy extends EventSourcedAggregate
{
    public const array FIELDS = [
        "program_id",
        "operation_id",
        "previous_operation_id",
        "before_hash",
        "definition",
        "intent",
    ];
    private ?string $operation = null;
    private ?array $definition = null;
    private array $receipts = [];
    private array $seen = [];

    public static function validate(#[\SensitiveParameter] array $data): void
    {
        try {
            if (count($data) !== count(self::FIELDS) || array_diff(array_keys($data), self::FIELDS) !== []) {
                throw new CorruptHistoryException();
            }
            foreach (["program_id", "operation_id", "previous_operation_id"] as $key) {
                if ($key === "operation_id" || $data[$key] !== null) {
                    if (!is_string($data[$key])) {
                        throw new CorruptHistoryException();
                    }
                    new EntityId($data[$key]);
                }
            }
            if ($data["program_id"] === EnrollmentLoadPolicyId::GLOBAL) {
                throw new CorruptHistoryException();
            }
            foreach (["before_hash", "intent"] as $key) {
                if (!is_string($data[$key]) || preg_match('/^[a-f0-9]{64}$/D', $data[$key]) !== 1) {
                    throw new CorruptHistoryException();
                }
            }
            if ($data["definition"] !== null) {
                new EnrollmentLoadBands($data["definition"]);
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function accept(StoredEvent $stored): void
    {
        $event = $stored->event;
        if (
            !($event instanceof EnrollmentLoadPolicyWasPublished) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1 ||
            $stored->context->origin !== "command" ||
            $stored->context->actor === null ||
            $stored->context->reason === null ||
            $stored->context->effectiveAt === null ||
            $stored->context->correlation?->value !== ($event->payload()["operation_id"] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
        $this->receipts[$this->operation] = [
            "actor" => $stored->context->actor->value,
            "intent" => $event->payload()["intent"],
            "result" => [
                "operation_id" => $this->operation,
                "version" => $this->playhead,
                "status" => "published",
            ],
        ];
    }

    public function whenEnrollmentLoadPolicyWasPublished(EnrollmentLoadPolicyWasPublished $event): void
    {
        self::validate($data = $event->payload());
        $next =
        $data["definition"] === null ? null : new EnrollmentLoadBands($data["definition"])->definition;
        if (
            ($data["program_id"] ?? EnrollmentLoadPolicyId::GLOBAL) !== (string) $this->aggregateId() ||
            $data["previous_operation_id"] !== $this->operation ||
            $data["before_hash"] !== self::hash($this->definition) ||
            isset($this->seen[$data["operation_id"]]) ||
            $next === $this->definition
        ) {
            throw new CorruptHistoryException();
        }
        $this->definition = $next;
        $this->operation = $data["operation_id"];
        $this->seen[$this->operation] = true;
    }

    public function state(): array
    {
        return [
            "position" => $this->playhead(),
            "operation_id" => $this->operation,
            "definition" => $this->definition,
        ];
    }

    public function receipt(EntityId $operation, EntityId $actor, string $intent): ?array
    {
        $receipt = $this->receipts[$operation->value] ?? null;
        if ($receipt === null) {
            return null;
        }
        if ($receipt["actor"] !== $actor->value || !hash_equals($receipt["intent"], $intent)) {
            throw new AccessDeniedException("This load-policy confirmation belongs to another instruction.");
        }
        return $receipt["result"];
    }

    public static function hash(?array $definition): string
    {
        return hash("sha256", json_encode($definition, JSON_THROW_ON_ERROR));
    }
}
