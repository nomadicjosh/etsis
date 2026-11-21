<?php

declare(strict_types=1);

namespace Domain\Registration;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Registration\Event\PrerequisitePermissionWasRecorded;
use Domain\Registration\ValueObject\PrerequisitePermissionReference;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;

final class PrerequisitePermission extends EventSourcedAggregate
{
    public const array FIELDS = [
        "operation_id",
        "previous_operation_id",
        "before_hash",
        "scope",
        "approval",
        "intent",
    ];
    private ?array $scope = null;
    private ?array $approval = null;
    private ?string $operation = null;
    private array $receipts = [];
    private array $seen = [];

    public static function validate(#[\SensitiveParameter] array $data): void
    {
        try {
            if (count($data) !== count(self::FIELDS) || array_diff(array_keys($data), self::FIELDS) !== []) {
                throw new CorruptHistoryException();
            }
            new EntityId($data["operation_id"]);
            if ($data["previous_operation_id"] !== null) {
                new EntityId($data["previous_operation_id"]);
            }
            foreach (["before_hash", "intent"] as $key) {
                if (!is_string($data[$key]) || preg_match('/^[a-f0-9]{64}$/D', $data[$key]) !== 1) {
                    throw new CorruptHistoryException();
                }
            }
            if (
                !is_array($data["scope"]) ||
                count($data["scope"]) !== 3 ||
                array_diff(array_keys($data["scope"]), ["student_id", "pursuit_id", "section_id"]) !== []
            ) {
                throw new CorruptHistoryException();
            }
            foreach ($data["scope"] as $value) {
                new EntityId($value);
            }
            if ($data["approval"] !== null) {
                if (
                    !is_array($data["approval"]) ||
                    count($data["approval"]) !== 3 ||
                    array_diff(array_keys($data["approval"]), [
                        "term_id",
                        "course_version_id",
                        "expires_at",
                    ]) !== []
                ) {
                    throw new CorruptHistoryException();
                }
                new PrerequisitePermissionReference([
                    "stream_id" => $data["operation_id"],
                    "operation_id" => $data["operation_id"],
                    "position" => 1,
                    ...$data["scope"],
                    ...$data["approval"],
                ]);
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function accept(StoredEvent $stored): void
    {
        $event = $stored->event;
        if (
            !($event instanceof PrerequisitePermissionWasRecorded) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1 ||
            $stored->context->origin !== "command" ||
            $stored->context->actor === null ||
            $stored->context->effectiveAt === null ||
            $stored->context->reason === null ||
            $stored->context->correlation?->value !== ($event->payload()["operation_id"] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        self::recordedState($stored);
        $this->applyThat($event);
        $this->playhead = $event->playhead();
        $this->receipts[$this->operation] = [
            "actor" => $stored->context->actor->value,
            "intent" => $event->payload()["intent"],
            "result" => [
                "operation_id" => $this->operation,
                "version" => $this->playhead(),
                "status" => $this->approval === null ? "revoked" : "approved",
            ],
        ];
    }

    public function whenPrerequisitePermissionWasRecorded(PrerequisitePermissionWasRecorded $event): void
    {
        self::validate($data = $event->payload());
        $data["scope"] = self::scope($data["scope"]);
        $data["approval"] = self::approval($data["approval"]);
        if (
            ($this->scope !== null && $data["scope"] !== $this->scope) ||
            $data["previous_operation_id"] !== $this->operation ||
            isset($this->seen[$data["operation_id"]]) ||
            $data["before_hash"] !== self::hash($this->approval) ||
            $data["approval"] === $this->approval
        ) {
            throw new CorruptHistoryException();
        }
        $this->scope = $data["scope"];
        $this->approval = $data["approval"];
        $this->operation = $data["operation_id"];
        $this->seen[$this->operation] = true;
    }

    public static function scope(array $scope): array
    {
        return array_replace(array_fill_keys(["student_id", "pursuit_id", "section_id"], null), $scope);
    }

    public static function approval(?array $approval): ?array
    {
        return $approval === null
        ? null
        : array_replace(array_fill_keys(["term_id", "course_version_id", "expires_at"], null), $approval);
    }

    public static function recordedState(StoredEvent $stored): array
    {
        $event = $stored->event;
        if (
            !($event instanceof PrerequisitePermissionWasRecorded) ||
            $stored->context->origin !== "command" ||
            $stored->context->actor === null ||
            $stored->context->reason === null ||
            $stored->context->effectiveAt === null ||
            $stored->context->correlation?->value !== ($event->payload()["operation_id"] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        self::validate($data = $event->payload());
        if (
            $data["approval"] !== null &&
            new \DateTimeImmutable($data["approval"]["expires_at"]) <= $stored->context->effectiveAt
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "position" => $event->playhead(),
            "operation_id" => $data["operation_id"],
            "scope" => self::scope($data["scope"]),
            "approval" => self::approval($data["approval"]),
        ];
    }

    public function state(): array
    {
        return [
            "position" => $this->playhead(),
            "operation_id" => $this->operation,
            "scope" => $this->scope,
            "approval" => $this->approval,
        ];
    }

    public function receipt(EntityId $operation, EntityId $actor, string $intent): ?array
    {
        $r = $this->receipts[$operation->value] ?? null;
        if ($r !== null && ($r["actor"] !== $actor->value || !hash_equals($r["intent"], $intent))) {
            throw new AccessDeniedException();
        }
        return $r["result"] ?? null;
    }

    public static function hash(?array $approval): string
    {
        return hash("sha256", json_encode($approval, JSON_THROW_ON_ERROR));
    }
}
