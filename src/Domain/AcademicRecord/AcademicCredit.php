<?php

declare(strict_types=1);

namespace Domain\AcademicRecord;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished;
use Domain\AcademicRecord\Event\FinalGradeWasDecided;
use Domain\AcademicRecord\Event\InstitutionalCreditWasDropped;
use Domain\Shared\EventSourcing\CorruptHistoryException;

final class AcademicCredit extends EventSourcedAggregate
{
    /** @var array<string,mixed>|null */
    private ?array $credit = null;
    /** @var array<string,mixed>|null */
    private ?array $grade = null;
    /** @var list<array<string,mixed>> */
    private array $policies = [];
    private ?string $section = null;
    private ?string $decision = null;
    private ?string $provenance = null;
    private ?string $sectionVersion = null;
    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !in_array(
                $event::class,
                [
                    InstitutionalCreditWasEstablished::class,
                    FinalGradeWasDecided::class,
                    InstitutionalCreditWasDropped::class,
                ],
                true,
            ) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        \Domain\AcademicRecord\ValueObject\AcademicEventSchema::validate($event::class, $event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenInstitutionalCreditWasEstablished(InstitutionalCreditWasEstablished $event): void
    {
        $p = $event->payload();
        if ($this->credit !== null || $p["credit"]["id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->sectionVersion = $event->metaParam('section_version_id', null);
        if ($this->sectionVersion !== null) {
            new \Domain\Shared\ValueObject\EntityId($this->sectionVersion);
            if ($p['provenance'] !== 'registration') {
                throw new CorruptHistoryException();
            }
        }
        $this->provenance = $p["provenance"];
        $this->credit = $p["credit"];
        $this->grade = $p["grade"];
        $this->section = $p["section_id"];
        $this->policies = $p["policies"];
        $this->decision = $p["decision_id"];
    }
    public function whenFinalGradeWasDecided(FinalGradeWasDecided $event): void
    {
        $p = $event->payload();
        $this->assertNext($p);
        if ($this->credit === null || !in_array($this->credit["status"], ["A", "N"], true)) {
            throw new CorruptHistoryException();
        }
        $g = $p["grade"];
        $this->grade = $g;
        $this->credit["grade_id"] = $g["id"];
        $this->credit["completed_milli"] = $g["passes"] ? $this->credit["attempted_milli"] : 0;
        $this->credit["grade_points_milli"] = $g["counts_gpa"]
        ? intdiv($this->credit["attempted_milli"] * $g["points_milli"], 1000)
        : 0;
        ++$this->credit["version"];
        new \Domain\AcademicRecord\ValueObject\CreditSnapshot($this->credit);
        $this->decision = $p["decision_id"];
    }
    public function whenInstitutionalCreditWasDropped(InstitutionalCreditWasDropped $event): void
    {
        $p = $event->payload();
        $this->assertNext($p);
        if (
            $this->credit === null ||
            !in_array($this->credit["status"], ["A", "N"], true) ||
            $this->credit["grade_id"] !== null ||
            $this->credit["completed_milli"] !== 0 ||
            $this->credit["grade_points_milli"] !== 0
        ) {
            throw new CorruptHistoryException();
        }
        $o = $p["outcome"];
        $this->credit["status"] = $o["delete_records"] ? "D" : "W";
        $this->credit["grade_id"] = $o["grade_id"];
        $this->credit["completed_milli"] = 0;
        $this->credit["grade_points_milli"] = $o["counts_gpa"]
        ? intdiv($this->credit["attempted_milli"] * $o["points_milli"], 1000)
        : 0;
        $this->grade = $p["grade"];
        ++$this->credit["version"];
        new \Domain\AcademicRecord\ValueObject\CreditSnapshot($this->credit);
        $this->decision = $p["decision_id"];
    }
    /** @param array<string,mixed> $p */
    private function assertNext(array $p): void
    {
        if (
            $this->credit === null ||
            $p["previous_decision_id"] !== $this->decision ||
            $p["decision_id"] === $this->decision
        ) {
            throw new CorruptHistoryException();
        }
    }
    /** @return array<string,mixed>|null */
    public function credit(): ?array
    {
        return $this->credit;
    }
    /** @return array<string,mixed>|null */
    public function grade(): ?array
    {
        return $this->grade;
    }
    /** @return list<array<string,mixed>> */
    public function policies(): array
    {
        return $this->policies;
    }
    public function section(): ?string
    {
        return $this->section;
    }
    public function provenance(): ?string
    {
        return $this->provenance;
    }
    public function decision(): ?string
    {
        return $this->decision;
    }
    public function sectionVersion(): ?string
    {
        return $this->sectionVersion;
    }
}
