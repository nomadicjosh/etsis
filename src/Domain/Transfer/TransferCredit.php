<?php

declare(strict_types=1);

namespace Domain\Transfer;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Transfer\Event\TransferCreditWasEstablished;
use Domain\Transfer\Event\TransferCreditWasChanged;
use Domain\Transfer\Event\TransferAcademicPeriodWasRecorded;
use Domain\Transfer\Event\TransferCourseWasAccepted;
use Domain\Transfer\ValueObject\TransferEventSchema;
use Domain\Transfer\ValueObject\TransferCreditFacts;

final class TransferCredit extends EventSourcedAggregate
{
    /** @var array<string,mixed>|null */
    private ?array $credit = null;
    /** @var array<string,mixed>|null */
    private ?array $source = null;
    /** Last retained grading policy survives a void.
     * @var array<string,mixed>|null */
    private ?array $grade = null;
    private ?string $decision = null;
    private ?string $provenance = null;
    private ?string $academicOn = null;
    private ?string $courseVersion = null;
    private ?string $originalCourseVersion = null;
    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        TransferEventSchema::validate($event::class, $event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenTransferCreditWasEstablished(TransferCreditWasEstablished $event): void
    {
        $p = $event->payload();
        if ($this->credit !== null || $p["source"]["award_id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->courseVersion = $event->metaParam("course_version_id", null);
        if ($this->courseVersion !== null) {
            new \Domain\Shared\ValueObject\EntityId($this->courseVersion);
            if ($p["provenance"] !== "award") {
                throw new CorruptHistoryException();
            }
        }
        $this->originalCourseVersion = $this->courseVersion;
        $this->credit = $p["credit"];
        $this->source = $p["source"];
        $this->grade = $p["grade"];
        $this->decision = $p["decision_id"];
        $this->provenance = $p["provenance"];
    }
    public function whenTransferCreditWasChanged(TransferCreditWasChanged $event): void
    {
        $p = $event->payload();
        if (
            $this->credit === null ||
            $p["previous_decision_id"] !== $this->decision ||
            ($p["action"] === "reinstate"
                ? $this->credit["status"] !== "V"
                : !in_array($this->credit["status"], ["A", "N"], true))
        ) {
            throw new CorruptHistoryException();
        }
        if (
            $this->grade !== null &&
            $this->grade["scale_version_id"] !== null &&
            $p["grade"] !== null &&
            $this->grade["scale_version_id"] !== $p["grade"]["scale_version_id"]
        ) {
            throw new CorruptHistoryException();
        }
        $g = $p["grade"];
        $units = $p["credits_milli"] ?? 0;
        $this->credit = array_replace($this->credit, [
            "attempted_milli" => $units,
            "completed_milli" => $units,
            "grade_points_milli" =>
                $g !== null && $g["counts_gpa"] ? intdiv($units * $g["points_milli"], 1000) : 0,
            "grade_id" => $g["id"] ?? null,
            "status" => $p["action"] === "void" ? "V" : "A",
            "version" => $this->credit["version"] + 1,
        ]);
        if ($g !== null) {
            $this->grade = $g;
        }
        $this->decision = $p["decision_id"];
        new TransferCreditFacts($this->credit);
    }
    public function whenTransferAcademicPeriodWasRecorded(TransferAcademicPeriodWasRecorded $event): void
    {
        $p = $event->payload();
        if (
            $this->credit === null ||
            $this->source === null ||
            !in_array($this->credit["status"], ["A", "N"], true) ||
            $p["previous_decision_id"] !== $this->decision ||
            $p["previous_academic_on"] !== $this->academicOn ||
            ($p["academic_on"] !== null && $p["academic_on"] > $this->source["completed_on"])
        ) {
            throw new CorruptHistoryException();
        }
        $this->academicOn = $p["academic_on"];
        ++$this->credit["version"];
        $this->decision = $p["decision_id"];
        new TransferCreditFacts($this->credit);
    }
    public function whenTransferCourseWasAccepted(TransferCourseWasAccepted $event): void
    {
        $p = $event->payload();
        if (
            $this->credit === null ||
            !in_array($this->credit["status"], ["A", "N"], true) ||
            $p["previous_decision_id"] !== $this->decision ||
            $p["previous_course_version_id"] !== $this->courseVersion ||
            $p["course_id"] !== $this->credit["course_id"]
        ) {
            throw new CorruptHistoryException();
        }
        $this->courseVersion = $p["course_version_id"];
        $this->credit["course_code"] = $p["course_code"];
        $this->credit["course_title"] = $p["course_title"];
        ++$this->credit["version"];
        $this->decision = $p["decision_id"];
        new TransferCreditFacts($this->credit);
    }
    /** Original command retries compare the opening fact, never a later correction. */
    public function originalCourseVersion(): ?string
    {
        return $this->originalCourseVersion;
    }
    public function academicOn(): ?string
    {
        return $this->academicOn;
    }
    public function courseVersion(): ?string
    {
        return $this->courseVersion;
    }
    /** @return array<string,mixed>|null */
    public function credit(): ?array
    {
        return $this->credit;
    }
    /** @return array<string,mixed>|null */
    public function source(): ?array
    {
        return $this->source;
    }
    /** @return array<string,mixed>|null */
    public function grade(): ?array
    {
        return $this->grade;
    }
    public function decision(): ?string
    {
        return $this->decision;
    }
    public function provenance(): ?string
    {
        return $this->provenance;
    }
    /** @return array<string,mixed> */
    public function display(): array
    {
        $c = $this->credit ?? throw new CorruptHistoryException();
        return $c + [
            "grade_code" => $c["status"] === "V" ? null : $this->grade["code"] ?? null,
            "counts_gpa" => $c["status"] !== "V" && ($this->grade["counts_gpa"] ?? false),
        ];
    }
}
