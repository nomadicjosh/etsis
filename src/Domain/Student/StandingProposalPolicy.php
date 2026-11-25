<?php

declare(strict_types=1);

namespace Domain\Student;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Student\Event\StandingProposalPolicyWasPublished;
use Domain\Student\ValueObject\StandingProposalRules;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\ValueObject\EntityId;

final class StandingProposalPolicy extends EventSourcedAggregate
{
    public const string ID = "01K00000000000000000000S41";
    public const array FIELDS = ["publication_id", "rules", "intent"];
    private array $records = [];
    public static function validate(#[\SensitiveParameter] array $data): void
    {
        if (count($data) !== count(self::FIELDS) || array_diff(array_keys($data), self::FIELDS) !== []) {
            throw new CorruptHistoryException();
        }
        new EntityId($data["publication_id"]);
        new StandingProposalRules($data["rules"]);
        if (!is_string($data["intent"]) || !preg_match('/^[a-f0-9]{64}$/D', $data["intent"])) {
            throw new CorruptHistoryException();
        }
    }
    public function accept(#[\SensitiveParameter] StoredEvent $stored): void
    {
        $event = $stored->event;
        $p = $event->payload();
        self::validate($p);
        if (
            !($event instanceof StandingProposalPolicyWasPublished) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1 ||
            $stored->context->origin !== "command" ||
            $stored->context->actor === null ||
            $stored->context->reason === null ||
            $stored->context->correlation?->value !== $p["publication_id"] ||
            !hash_equals(
                $p["intent"],
                StandingProposalRules::hash([$p["rules"], $stored->context->reason]),
            )
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
        $this->records[array_key_last($this->records)]["actor_id"] = $stored->context->actor->value;
    }
    public function whenStandingProposalPolicyWasPublished(
        StandingProposalPolicyWasPublished $event,
    ): void {
        $p = $event->payload();
        self::validate($p);
        if (
            (string) $this->aggregateId() !== self::ID ||
            array_any(
                $this->records,
                static fn(array $r): bool => $r["publication_id"] === $p["publication_id"],
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
        return ["policy" => "[protected institutional standing proposal policy]"];
    }
}
