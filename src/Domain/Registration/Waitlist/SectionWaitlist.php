<?php

declare(strict_types=1);

namespace Domain\Registration\Waitlist;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\ValueObject\EntityId;

/** Accepted per-section order, without browser-time fairness or inferred earlier requests. */
final class SectionWaitlist extends EventSourcedAggregate
{
    public const int MAX_EVENTS = 2000;
    public const array POLICY_FIELDS = ["operation_id", "policy", "intent"];
    public const array PLACE_FIELDS = [
        "operation_id",
        "action",
        "entry_id",
        "student_id",
        "person_id",
        "policy_position",
        "enrollment_id",
        "intent",
    ];
    private array $entries = [];
    private array $receipts = [];
    private array $bypasses = [];
    private array $policy = [
        "enabled" => false,
        "maximum_active" => 50,
        "ordering" => "accepted_fifo",
        "allocation" => "oldest_may_register",
    ];
    private int $policyPosition = 0;
    public static function validate(#[\SensitiveParameter] array $p, bool $policy): void
    {
        try {
            $keys = $policy ? self::POLICY_FIELDS : self::PLACE_FIELDS;
            if (!$policy && array_key_exists('student_credit_choice', $p)) {
                $keys[] = 'student_credit_choice';
                if ($p['action'] !== 'requested' || !is_array($p['student_credit_choice'])) {
                    throw new CorruptHistoryException();
                }
                \Domain\Registration\ValueObject\SelectedRegistrationCredits::from($p['student_credit_choice']);
            }
            if (!$policy && array_key_exists('priority_bypass', $p)) {
                $keys[] = 'priority_bypass';
                $bypass = $p['priority_bypass'];
                if (
                    $p['action'] !== 'registered' || !is_array($bypass) || count($bypass) !== 3 ||
                    !is_string($bypass['review_revision'] ?? null) ||
                    !preg_match('/^[a-f0-9]{64}$/D', $bypass['review_revision']) ||
                    !is_int($bypass['queue_version'] ?? null) || $bypass['queue_version'] < 1 ||
                    $bypass['queue_version'] >= self::MAX_EVENTS ||
                    !is_array($bypass['earlier_entry_ids'] ?? null) ||
                    !array_is_list($bypass['earlier_entry_ids']) ||
                    count($bypass['earlier_entry_ids']) < 1 || count($bypass['earlier_entry_ids']) > 99 ||
                    count(array_unique($bypass['earlier_entry_ids'])) !== count($bypass['earlier_entry_ids'])
                ) {
                    throw new CorruptHistoryException();
                }
                foreach ($bypass['earlier_entry_ids'] as $id) {
                    new EntityId($id);
                }
            }
            if (count($keys) !== count($p) || array_diff(array_keys($p), $keys) !== []) {
                throw new CorruptHistoryException();
            }
            new EntityId($p["operation_id"]);
            if (!is_string($p["intent"]) || !preg_match('/^[a-f0-9]{64}$/D', $p["intent"])) {
                throw new CorruptHistoryException();
            }
            if ($policy) {
                WaitlistPolicy::from($p["policy"]);
                return;
            }
            foreach (["entry_id", "student_id", "person_id"] as $key) {
                new EntityId($p[$key]);
            }
            if (
                !in_array(
                    $p["action"],
                    ["requested", "cancelled", "removed", "registered"],
                    true,
                ) ||
                !is_int($p["policy_position"]) ||
                $p["policy_position"] < 1 ||
                $p["policy_position"] > 1900 ||
                ($p["enrollment_id"] !== null) !== ($p["action"] === "registered")
            ) {
                throw new CorruptHistoryException();
            }
            if ($p["enrollment_id"] !== null) {
                new EntityId($p["enrollment_id"]);
            }
            if ($p["action"] === "requested" && $p["entry_id"] !== $p["operation_id"]) {
                throw new CorruptHistoryException();
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function accept(#[\SensitiveParameter] StoredEvent $stored): void
    {
        $this->applyAccepted($stored->event, $stored->context);
    }
    /** For a genuine command after append; replay calls the same contract with authenticated stored context. */
    public function applyAccepted(
        #[\SensitiveParameter] DomainEvent $event,
        #[\SensitiveParameter] EventContext $context,
    ): void {
        $policy = $event instanceof WaitlistPolicyWasSet;
        if (!$policy && !($event instanceof WaitlistPlaceWasChanged)) {
            throw new CorruptHistoryException();
        }
        $p = $event->payload();
        self::validate($p, $policy);
        if (
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1 ||
            $event->playhead() > self::MAX_EVENTS ||
            isset($this->receipts[$p["operation_id"]]) ||
            $context->origin !== "command" ||
            $context->actor === null ||
            $context->effectiveAt === null ||
            $context->reason === null ||
            $context->correlation?->value !== $p["operation_id"] ||
            !hash_equals($p["intent"], self::intent($p, $context->reason))
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
        $this->receipts[$p["operation_id"]] = [
            "actor_id" => $context->actor->value,
            "intent" => $p["intent"],
            "version" => $event->playhead(),
            "action" => $policy ? "policy" : $p["action"],
            "entry_id" => $p["entry_id"] ?? null,
        ];
        if (isset($p['priority_bypass'])) {
            $this->bypasses[] = [
                'operation_id' => $p['operation_id'], 'entry_id' => $p['entry_id'],
                'student_id' => $p['student_id'], 'person_id' => $p['person_id'],
                'enrollment_id' => $p['enrollment_id'], 'accepted_position' => $event->playhead(),
                'earlier_requests' => count($p['priority_bypass']['earlier_entry_ids']),
                'actor_id' => $context->actor->value, 'reason' => $context->reason,
                'effective_at' => $context->effectiveAt->format(DATE_ATOM),
            ];
            $this->receipts[$p['operation_id']] += [
                'bypass_revision' => $p['priority_bypass']['review_revision'],
                'enrollment_id' => $p['enrollment_id'],
                'student_id' => $p['student_id'],
                'person_id' => $p['person_id'],
            ];
        }
        if (!$policy) {
            $this->entries[$p["entry_id"]]["changed_at"] = $context->effectiveAt->format(DATE_ATOM);
        }
    }
    public static function intent(array $payload, string $reason): string
    {
        unset($payload["intent"]);
        return WaitlistPolicy::hash([$payload, $reason]);
    }
    public function whenWaitlistPolicyWasSet(WaitlistPolicyWasSet $event): void
    {
        $p = $event->payload();
        self::validate($p, true);
        if ($event->playhead() > 1900) {
            throw new CorruptHistoryException();
        }
        $this->policy = $p["policy"];
        $this->policyPosition = $event->playhead();
    }
    public function whenWaitlistPlaceWasChanged(WaitlistPlaceWasChanged $event): void
    {
        $p = $event->payload();
        self::validate($p, false);
        if ($p["action"] === "requested") {
            if (
                !$this->policy["enabled"] ||
                $event->playhead() > 1800 ||
                $p["policy_position"] !== $this->policyPosition ||
                isset($this->entries[$p["entry_id"]]) ||
                $this->forStudent($p["student_id"]) !== null ||
                count($this->active()) >= $this->policy["maximum_active"]
            ) {
                throw new CorruptHistoryException();
            }
            $this->entries[$p["entry_id"]] = [
                "id" => $p["entry_id"],
                "student_id" => $p["student_id"],
                "person_id" => $p["person_id"],
                "position" => $event->playhead(),
                "policy_position" => $p["policy_position"],
                "status" => "active",
                "enrollment_id" => null,
            ] + (isset($p["student_credit_choice"]) ? [
                "student_credit_choice" => \Domain\Registration\ValueObject\SelectedRegistrationCredits::from(
                    $p["student_credit_choice"]
                )->values()] : []);
            return;
        }
        $entry = $this->entries[$p["entry_id"]] ?? throw new CorruptHistoryException();
        $earlier = [];
        foreach ($this->active() as $waiting) {
            if ($waiting['id'] === $entry['id']) {
                break;
            }
            $earlier[] = $waiting['id'];
        }
        if (
            isset($p['priority_bypass']) &&
            ($p['priority_bypass']['queue_version'] !== $event->playhead() - 1 ||
            $p['priority_bypass']['earlier_entry_ids'] !== $earlier)
        ) {
            throw new CorruptHistoryException();
        }
        if (
            $entry["status"] !== "active" ||
            $entry["student_id"] !== $p["student_id"] ||
            $entry["person_id"] !== $p["person_id"] ||
            $entry["policy_position"] !== $p["policy_position"] ||
            ($p["action"] === "registered" && !isset($p['priority_bypass']) &&
                ($this->active()[0]["id"] ?? null) !== $p["entry_id"])
        ) {
            throw new CorruptHistoryException();
        }
        $this->entries[$p["entry_id"]]["status"] = $p["action"];
        $this->entries[$p["entry_id"]]["enrollment_id"] = $p["enrollment_id"];
    }
    public function policy(): array
    {
        return $this->policy;
    }
    public function policyPosition(): int
    {
        return $this->policyPosition;
    }
    public function active(): array
    {
        return array_values(
            array_filter($this->entries, static fn(array $e): bool => $e["status"] === "active"),
        );
    }
    public function entries(): array
    {
        return $this->entries;
    }
    /** Restricted workflow evidence; never part of the ordinary queue or owner projection. */
    public function bypasses(): array
    {
        return $this->bypasses;
    }
    public function forStudent(string $student): ?array
    {
        return array_find(
            $this->active(),
            static fn(array $e): bool => $e["student_id"] === $student,
        );
    }
    public function receipt(EntityId $operation): ?array
    {
        return $this->receipts[$operation->value] ?? null;
    }
    public function projection(): array
    {
        return [
            "policy" => $this->policy,
            "policy_position" => $this->policyPosition,
            "active" => array_map(static function (array $e): array {
                unset($e["changed_at"]);
                return $e;
            }, $this->active()),
        ];
    }
    public function __debugInfo(): array
    {
        return ["waitlist" => "[restricted waitlist history]"];
    }
}
