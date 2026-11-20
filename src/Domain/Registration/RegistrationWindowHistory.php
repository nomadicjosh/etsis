<?php

declare(strict_types=1);

namespace Domain\Registration;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Registration\Event\RegistrationWindowWasPublished;
use Domain\Registration\ValueObject\RegistrationWindowDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

final class RegistrationWindowHistory extends EventSourcedAggregate
{
    public const array FIELDS = [
        "publication_id",
        "previous_publication_id",
        "definition",
        "observed_previous",
        "review_hash",
    ];
    private ?array $current = null;
    private array $operations = [];
    public static function validate(array $p): void
    {
        if (
            count($p) !== 5 ||
            array_diff(array_keys($p), self::FIELDS) !== [] ||
            !is_string($p["publication_id"]) ||
            !is_array($p["definition"]) ||
            !is_string($p["review_hash"]) ||
            !preg_match('/^[a-f0-9]{64}$/D', $p["review_hash"])
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($p["publication_id"]);
        new RegistrationWindowDefinition($p["definition"]);
        if ($p["previous_publication_id"] !== null) {
            if (
                !is_string($p["previous_publication_id"]) ||
                $p["previous_publication_id"] === $p["publication_id"] ||
                $p["observed_previous"] !== null
            ) {
                throw new CorruptHistoryException();
            }
            new EntityId($p["previous_publication_id"]);
        }
        if ($p["observed_previous"] !== null) {
            if (!is_array($p["observed_previous"])) {
                throw new CorruptHistoryException();
            }
            new RegistrationWindowDefinition($p["observed_previous"]);
            if (
                $p["observed_previous"]["term_id"] !==
                $p["definition"]["term_id"]
            ) {
                throw new CorruptHistoryException();
            }
        }
    }
    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !($event instanceof RegistrationWindowWasPublished) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        self::validate($event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenRegistrationWindowWasPublished(
        RegistrationWindowWasPublished $event,
    ): void {
        $p = $event->payload();
        if (
            $p["definition"]["term_id"] !== (string) $this->aggregateId() ||
            $p["previous_publication_id"] !==
                ($this->current["publication_id"] ?? null) ||
            isset($this->operations[$p["publication_id"]])
        ) {
            throw new CorruptHistoryException();
        }
        $this->current = $p;
        $this->operations[$p["publication_id"]] = [
            "publication_id" => $p["publication_id"],
            "version" => $event->playhead(),
            "review_hash" => $p["review_hash"],
        ];
    }
    public function current(): ?array
    {
        return $this->current;
    }
    public function operation(string $id): ?array
    {
        return $this->operations[$id] ?? null;
    }
}
