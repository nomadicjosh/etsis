<?php

declare(strict_types=1);

namespace Domain\Registration;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Registration\Event\DropPolicyWasPublished;
use Domain\Registration\ValueObject\DropPolicyDefinition;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

final class DropPolicyPublication extends EventSourcedAggregate
{
    public const array FIELDS = [
        "publication_id",
        "previous_publication_id",
        "version",
        "definition",
        "observed_previous_policy",
        "review_hash",
    ];
    /** @var array<string,mixed>|null */
    private ?array $current = null;
    /** @var array<string,array<string,mixed>> */
    private array $operations = [];

    /** @param array<string,mixed> $p */
    public static function validate(array $p): void
    {
        if (
            count($p) !== count(self::FIELDS) ||
            array_diff(array_keys($p), self::FIELDS) !== [] ||
            !is_string($p["publication_id"]) ||
            !is_int($p["version"]) ||
            $p["version"] < 0 ||
            $p["version"] > 2000000000 ||
            !is_array($p["definition"]) ||
            !is_string($p["review_hash"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $p["review_hash"]) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($p["publication_id"]);
        new DropPolicyDefinition($p["definition"]);
        if ($p["previous_publication_id"] !== null) {
            if (!is_string($p["previous_publication_id"])) {
                throw new CorruptHistoryException();
            }
            new EntityId($p["previous_publication_id"]);
            if (
                $p["previous_publication_id"] === $p["publication_id"] ||
                $p["observed_previous_policy"] !== null
            ) {
                throw new CorruptHistoryException();
            }
        } elseif ($p["observed_previous_policy"] === null) {
            if ($p["version"] !== 0) {
                throw new CorruptHistoryException();
            }
        } else {
            $old = $p["observed_previous_policy"];
            if (
                !is_array($old) ||
                count($old) !== 2 ||
                array_diff(array_keys($old), ["version", "windows"]) !== [] ||
                !is_int($old["version"]) ||
                $old["version"] < 0 ||
                $old["version"] + 1 !== $p["version"] ||
                !is_array($old["windows"])
            ) {
                throw new CorruptHistoryException();
            }
            new DropWindows($old["windows"]);
        }
    }

    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !($event instanceof DropPolicyWasPublished ||
                $event instanceof \Domain\Registration\Event\SectionDropPolicyWasPublished) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        self::validate($event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }

    public function whenDropPolicyWasPublished(
        DropPolicyWasPublished $event,
    ): void {
        $this->applyPublication($event, false);
    }
    public function whenSectionDropPolicyWasPublished(
        \Domain\Registration\Event\SectionDropPolicyWasPublished $event,
    ): void {
        $this->applyPublication($event, true);
    }
    private function applyPublication(DomainEvent $event, bool $section): void
    {
        $p = $event->payload();
        if (
            isset($p['definition']['section_id']) !== $section ||
            ($p['definition'][$section ? 'section_id' : 'term_id'] ?? null) !== (string) $this->aggregateId() ||
            $p["previous_publication_id"] !==
                ($this->current["publication_id"] ?? null) ||
            ($this->current !== null &&
                $p["version"] !== $this->current["version"] + 1) ||
            isset($this->operations[$p["publication_id"]])
        ) {
            throw new CorruptHistoryException();
        }
        $this->current = $p;
        $this->operations[$p["publication_id"]] = $p;
    }

    /** @return array<string,mixed>|null */
    public function current(): ?array
    {
        return $this->current;
    }

    /** @return array<string,mixed>|null */
    public function operation(string $id): ?array
    {
        return $this->operations[$id] ?? null;
    }
}
