<?php

declare(strict_types=1);

namespace Domain\Registration;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Registration\Event\EnrollmentWasEstablished;
use Domain\Registration\Event\EnrollmentWasClosed;
use Domain\Registration\ValueObject\EnrollmentEventSchema;
use Domain\Registration\ValueObject\EnrollmentFacts;
use Domain\Shared\EventSourcing\CorruptHistoryException;

final class EnrollmentHistory extends EventSourcedAggregate
{
    /** @var array<string,mixed>|null */
    private ?array $facts = null;
    /** @var array<string,mixed>|null */
    private ?array $source = null;
    /** @var array<string,mixed>|null */
    private ?array $seat = null;
    /** @var list<array<string,mixed>> */
    private array $decisions = [];
    private ?string $decision = null;
    private ?string $provenance = null;
    public function acceptHistorical(#[\SensitiveParameter] DomainEvent $event): void
    {
        if (
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        EnrollmentEventSchema::validate($event::class, $event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenEnrollmentWasEstablished(EnrollmentWasEstablished $event): void
    {
        $p = $event->payload();
        if ($this->facts !== null || $p["enrollment"]["id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->facts = $p["enrollment"];
        $this->source = $p["source"];
        $this->provenance = $p["provenance"];
        $this->decision = $p["decision_id"];
        $this->seat = $p["seat"];
        if ($p["seat"] !== null) {
            $this->index($event);
        }
    }
    public function whenEnrollmentWasClosed(EnrollmentWasClosed $event): void
    {
        $p = $event->payload();
        if (
            $this->facts === null ||
            !in_array($this->facts["status"], ["A", "N"], true) ||
            $p["previous_decision_id"] !== $this->decision ||
            ($this->seat !== null && $p["seat"]["sequence"] <= $this->seat["sequence"])
        ) {
            throw new CorruptHistoryException();
        }
        $this->facts = array_replace($this->facts, [
            "status" => $p["status"],
            "status_at" => $p["status_at"],
            "version" => $this->facts["version"] + 1,
        ]);
        new EnrollmentFacts($this->facts);
        $this->decision = $p["decision_id"];
        $this->seat = $p["seat"];
        $this->index($event);
    }
    private function index(DomainEvent $event): void
    {
        $c = $this->facts ?? throw new CorruptHistoryException();
        $s = $this->seat ?? throw new CorruptHistoryException();
        $this->decisions[] = [
            "id" => $event->payload()["decision_id"],
            "enrollment_id" => $c["id"],
            "section_id" => $c["section_id"],
            "sequence" => $s["sequence"],
            "stream_version" => $event->playhead(),
        ];
    }
    /** @return array<string,mixed>|null */
    public function facts(): ?array
    {
        return $this->facts;
    }
    /** @return array<string,mixed>|null */
    public function source(): ?array
    {
        return $this->source;
    }
    /** @return array<string,mixed>|null */
    public function seat(): ?array
    {
        return $this->seat;
    }
    /** @return list<array<string,mixed>> */
    public function decisions(): array
    {
        return $this->decisions;
    }
    public function decision(): ?string
    {
        return $this->decision;
    }
    public function provenance(): ?string
    {
        return $this->provenance;
    }
}
