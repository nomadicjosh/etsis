<?php

declare(strict_types=1);

namespace Domain\AcademicRecord;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished;
use Domain\AcademicRecord\Event\FinalGradeWasDecided;
use Domain\AcademicRecord\Event\InstitutionalCreditWasDropped;
use Domain\AcademicRecord\Event\AcademicCompletionWasRecorded;
use Domain\AcademicRecord\Event\InstitutionalAcademicPeriodWasRecorded;
use Domain\AcademicRecord\Event\InstitutionalCourseWasAccepted;
use Domain\AcademicRecord\Event\InstitutionalCourseWasCorrected;
use Domain\AcademicRecord\Event\InstitutionalPostingTermWasCorrected;
use Domain\AcademicRecord\ValueObject\CreditSnapshot;
use Domain\AcademicRecord\ValueObject\CreditUnitCorrection;
use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\AcademicRecord\ValueObject\WithdrawnCreditQuantities;
use Domain\Gradebook\ValueObject\CourseworkGradeEvidence;
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
    private ?string $completedOn = null;
    private ?string $academicOn = null;
    private ?string $registrationTermId = null;
    private ?array $registeredCredits = null;
    /** @var array{term_id:string,term_code:string,decision_id:string}|null */
    private ?array $postingTerm = null;
    private ?string $originalCourseVersion = null;
    private ?string $registrationCourseId = null;
    private bool $awardedCourseCorrected = false;
    /** @var array<string, array<string, mixed>> */
    private array $gradeChanges = [];
    private ?string $pendingGradeChange = null;
    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !in_array(
                $event::class,
                [
                    InstitutionalCreditWasEstablished::class,
                    FinalGradeWasDecided::class,
                    \Domain\AcademicRecord\Event\GradeChangeWasRequested::class,
                    \Domain\AcademicRecord\Event\GradeChangeWasResolved::class,
                    InstitutionalCreditWasDropped::class,
                    AcademicCompletionWasRecorded::class,
                    InstitutionalAcademicPeriodWasRecorded::class,
                    InstitutionalCourseWasAccepted::class,
                    InstitutionalCourseWasCorrected::class,
                    InstitutionalPostingTermWasCorrected::class,
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
        $this->sectionVersion = $event->metaParam("section_version_id", null);
        if ($this->sectionVersion !== null) {
            new \Domain\Shared\ValueObject\EntityId($this->sectionVersion);
            if ($p["provenance"] !== "registration") {
                throw new CorruptHistoryException();
            }
        }
        if ($p["provenance"] === "registration") {
            $this->registeredCredits = array_intersect_key(
                $p["credit"],
                array_flip(["course_code", "course_title", "attempted_milli"]),
            );
        }
        $this->provenance = $p["provenance"];
        $this->credit = $p["credit"];
        $this->registrationTermId = $p["credit"]["term_id"];
        $this->originalCourseVersion = $p["credit"]["course_version_id"];
        $this->registrationCourseId = $p["credit"]["course_id"];
        $this->grade = $p["grade"];
        $this->section = $p["section_id"];
        $this->policies = $p["policies"];
        $this->decision = $p["decision_id"];
    }
    public function whenFinalGradeWasDecided(FinalGradeWasDecided $event): void
    {
        $p = $event->payload();
        $this->assertNext($p);
        if (
            !$this->allowsGrade(
                $event->metaParam("withdrawal_grade_correction", false),
                $event->metaParam("withdrawal_quantity_reconciliation", false),
            ) ||
            $this->pendingGradeChange !== null ||
            $this->credit === null
        ) {
            throw new CorruptHistoryException();
        }
        if ($event->metaParam("coursework", null) !== null) {
            $evidence = new CourseworkGradeEvidence($event->metaParam("coursework"));
            if (
                $this->grade !== null ||
                new CreditSnapshot($this->credit)->posted() ||
                $evidence->facts["student_id"] !== $this->credit["student_id"] ||
                $evidence->facts["section_id"] !== $this->section ||
                $evidence->facts["grade_id"] !== $p["grade"]["id"]
            ) {
                throw new CorruptHistoryException();
            }
        }
        $units = $this->unitCorrection($event->metaParam("credit_unit_correction", null));
        $this->decideGrade($p["grade"], $p["decision_id"], $units);
    }
    private function decideGrade(array $grade, string $decision, ?CreditUnitCorrection $units = null): void
    {
        $this->grade = $grade;
        $this->credit["grade_id"] = $grade["id"];
        $this->credit = array_replace(
            $this->credit,
            new GradeSnapshot($grade)->quantities(
                $units?->attemptedMilli ?? $this->credit["attempted_milli"],
                $this->credit["status"],
            ),
        );
        ++$this->credit["version"];
        new CreditSnapshot($this->credit);
        $this->decision = $decision;
    }
    public function whenGradeChangeWasRequested(
        \Domain\AcademicRecord\Event\GradeChangeWasRequested $event,
    ): void {
        $p = $event->payload();
        $this->assertNext($p);
        if (
            $this->pendingGradeChange !== null ||
            isset($this->gradeChanges[$p["decision_id"]]) ||
            ($this->grade === null && !$event->metaParam("withdrawal_grade_correction", false)) ||
            $this->credit === null ||
            !$this->allowsGrade(
                $event->metaParam("withdrawal_grade_correction", false),
                $event->metaParam("withdrawal_quantity_reconciliation", false),
            ) ||
            $p["credit_version"] !== $this->credit["version"] ||
            $p["before_grade"] != $this->grade
        ) {
            throw new CorruptHistoryException();
        }
        $units = $this->unitCorrection($event->metaParam("credit_unit_correction", null));
        $this->pendingGradeChange = $p["decision_id"];
        $this->gradeChanges[$p["decision_id"]] = [
            "details" => $p,
            "status" => "pending",
            "version" => $event->playhead(),
            "withdrawn" => $event->metaParam("withdrawal_grade_correction", false),
            "reconcile_quantities" => $event->metaParam("withdrawal_quantity_reconciliation", false),
            "quantities" => WithdrawnCreditQuantities::summary($this->credit, $p["grade"]),
            "credit_unit_correction" => $units?->facts(),
            "credit_unit_summary" => $units?->summary($this->credit, $p["grade"]),
        ];
        if ($units !== null) {
            $this->gradeChanges[$p["decision_id"]]["details"]["credit_unit_correction"] = $units->facts();
            $this->gradeChanges[$p["decision_id"]]["quantities"]["expected_grade_points_milli"] =
            $this->gradeChanges[$p["decision_id"]]["credit_unit_summary"]["grade_points_milli"];
        }
    }
    public function whenGradeChangeWasResolved(
        \Domain\AcademicRecord\Event\GradeChangeWasResolved $event,
    ): void {
        $p = $event->payload();
        $this->assertNext($p);
        $request = $this->gradeChanges[$p["request_id"]]["details"] ?? null;
        if (
            $request === null ||
            $this->pendingGradeChange !== $p["request_id"] ||
            ($p["outcome"] === "cancelled") !== ($p["actor_id"] === $request["actor_id"])
        ) {
            throw new CorruptHistoryException();
        }
        if ($p["outcome"] === "approved") {
            if (
                $this->credit === null ||
                !$this->allowsGrade(
                    $this->gradeChanges[$p["request_id"]]["withdrawn"],
                    $this->gradeChanges[$p["request_id"]]["reconcile_quantities"],
                ) ||
                $this->decision !== $request["previous_decision_id"] ||
                $this->credit["version"] !== $request["credit_version"] ||
                $p["grade"] != $request["grade"] ||
                $p["assignment_id"] !== $request["assignment_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $units = $this->unitCorrection($this->gradeChanges[$p["request_id"]]["credit_unit_correction"]);
            $this->decideGrade($p["grade"], $p["decision_id"], $units);
        }
        $this->gradeChanges[$p["request_id"]]["status"] = $p["outcome"];
        $this->gradeChanges[$p["request_id"]]["version"] = $event->playhead();
        $this->pendingGradeChange = null;
    }
    public function pendingGradeChange(): ?array
    {
        return $this->pendingGradeChange === null ? null : $this->gradeChanges[$this->pendingGradeChange];
    }
    private function unitCorrection(mixed $facts): ?CreditUnitCorrection
    {
        if ($facts === null) {
            return null;
        }
        if (
            !is_array($facts) ||
            $this->credit === null ||
            $this->credit["grade_id"] === null ||
            $this->grade === null ||
            !in_array($this->credit["status"], ["A", "N", "W"], true)
        ) {
            throw new CorruptHistoryException();
        }
        $units = CreditUnitCorrection::fromArray($facts);
        if ($units->previousMilli !== $this->credit["attempted_milli"]) {
            throw new CorruptHistoryException();
        }
        return $units;
    }
    private function allowsGrade(mixed $withdrawn, mixed $reconcileQuantities = false): bool
    {
        if (
            !is_bool($withdrawn) ||
            !is_bool($reconcileQuantities) ||
            ($reconcileQuantities && !$withdrawn) ||
            $this->credit === null
        ) {
            return false;
        }
        if (!$withdrawn) {
            return in_array($this->credit["status"], ["A", "N"], true);
        }
        return $this->credit["status"] === "W" &&
        ($reconcileQuantities ||
        ($this->credit["completed_milli"] === 0 &&
        ($this->credit["grade_id"] !== null || $this->credit["grade_points_milli"] === 0)));
    }
    public function gradeChange(string $id): ?array
    {
        return $this->gradeChanges[$id] ?? null;
    }
    public function whenAcademicCompletionWasRecorded(AcademicCompletionWasRecorded $event): void
    {
        $p = $event->payload();
        $this->assertNext($p);
        if (
            $this->credit === null ||
            !in_array($this->credit["status"], ["A", "N"], true) ||
            $this->grade === null ||
            $this->credit["grade_id"] === null ||
            $p["previous_completed_on"] !== $this->completedOn ||
            ($p["completed_on"] !== null &&
                $this->academicOn !== null &&
                $p["completed_on"] < $this->academicOn)
        ) {
            throw new CorruptHistoryException();
        }
        $this->completedOn = $p["completed_on"];
        ++$this->credit["version"];
        $this->decision = $p["decision_id"];
        new CreditSnapshot($this->credit);
    }
    public function whenInstitutionalAcademicPeriodWasRecorded(
        InstitutionalAcademicPeriodWasRecorded $event,
    ): void {
        $p = $event->payload();
        $this->assertNext($p);
        if (
            $this->credit === null ||
            $this->sectionVersion !== null ||
            $this->grade === null ||
            $this->credit["grade_id"] === null ||
            !$this->canCorrectSource() ||
            $p["previous_academic_on"] !== $this->academicOn ||
            ($p["academic_on"] !== null &&
                $this->completedOn !== null &&
                $p["academic_on"] > $this->completedOn)
        ) {
            throw new CorruptHistoryException();
        }
        $this->academicOn = $p["academic_on"];
        ++$this->credit["version"];
        $this->decision = $p["decision_id"];
        new CreditSnapshot($this->credit);
    }
    public function academicOn(): ?string
    {
        return $this->academicOn;
    }
    public function registeredCredits(): ?array
    {
        return $this->registeredCredits;
    }

    public function registrationTermId(): ?string
    {
        return $this->registrationTermId;
    }

    /** @return array{term_id:string,term_code:string,decision_id:string}|null */
    public function postingTerm(): ?array
    {
        return $this->postingTerm;
    }

    public function whenInstitutionalPostingTermWasCorrected(
        InstitutionalPostingTermWasCorrected $event,
    ): void {
        $p = $event->payload();
        $this->assertNext($p);
        if (
            !$this->canCorrectSource() ||
            $this->grade === null ||
            $this->credit["grade_id"] === null ||
            $this->pendingGradeChange !== null ||
            $p["previous_term_id"] !== $this->credit["term_id"] ||
            $p["previous_term_code"] !== ($this->postingTerm["term_code"] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        $this->credit["term_id"] = $p["term_id"];
        ++$this->credit["version"];
        $this->decision = $p["decision_id"];
        $this->postingTerm = [
            "term_id" => $p["term_id"],
            "term_code" => $p["term_code"],
            "decision_id" => $p["decision_id"],
        ];
        new CreditSnapshot($this->credit);
    }

    public function registrationCourseId(): ?string
    {
        return $this->registrationCourseId;
    }

    public function registrationCourseVersion(): ?string
    {
        return $this->originalCourseVersion;
    }

    public function canCorrectAwardedCourse(): bool
    {
        return $this->canCorrectSource() && $this->grade !== null &&
        $this->credit["grade_id"] !== null && $this->pendingGradeChange === null;
    }

    public function whenInstitutionalCourseWasCorrected(InstitutionalCourseWasCorrected $event): void
    {
        $p = $event->payload();
        $this->assertNext($p);
        if (
            !$this->canCorrectAwardedCourse() ||
            $p["previous_course_id"] !== $this->credit["course_id"] ||
            $p["previous_course_version_id"] !== $this->credit["course_version_id"]
        ) {
            throw new CorruptHistoryException();
        }
        $this->awardedCourseCorrected = true;
        $this->credit["course_id"] = $p["course_id"];
        $this->credit["course_version_id"] = $p["course_version_id"];
        $this->credit["course_code"] = $p["course_code"];
        $this->credit["course_title"] = $p["course_title"];
        ++$this->credit["version"];
        $this->decision = $p["decision_id"];
        new CreditSnapshot($this->credit);
    }

    public function whenInstitutionalCourseWasAccepted(InstitutionalCourseWasAccepted $event): void
    {
        $p = $event->payload();
        $this->assertNext($p);
        if (
            !$this->canAcceptCourse() ||
            $this->credit === null ||
            !$this->canCorrectSource() ||
            $p["previous_course_version_id"] !== $this->credit["course_version_id"]
        ) {
            throw new CorruptHistoryException();
        }
        $this->credit["course_version_id"] = $p["course_version_id"];
        $this->credit["course_code"] = $p["course_code"];
        $this->credit["course_title"] = $p["course_title"];
        ++$this->credit["version"];
        new CreditSnapshot($this->credit);
        $this->decision = $p["decision_id"];
    }

    /** Acceptances resolve an unknown inception reference; they never replace known offering facts. */
    public function canAcceptCourse(): bool
    {
        return !$this->awardedCourseCorrected && $this->pendingGradeChange === null &&
        $this->originalCourseVersion === null &&
        $this->sectionVersion === null &&
        ($this->credit === null || $this->canCorrectSource());
    }

    /** Source corrections preserve the award; a withdrawn attempt never gains completed credits. */
    public function canCorrectSource(): bool
    {
        if ($this->credit === null) {
            return false;
        }
        if (in_array($this->credit["status"], ["A", "N"], true)) {
            return true;
        }
        return $this->credit["status"] === "W" &&
        $this->grade !== null &&
        $this->grade["id"] === $this->credit["grade_id"] &&
        !WithdrawnCreditQuantities::requiresReview($this->credit, $this->grade) &&
        $this->pendingGradeChange === null;
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
        $reference = $event->metaParam("drop_policy", null);
        if ($reference !== null) {
            \Domain\Registration\ValueObject\DropPolicyReference::validate($reference, $o["policy_version"]);
            if (
                $reference["term_id"] !== $this->credit["term_id"] ||
                (isset($reference['section_id']) && $reference['section_id'] !== $this->section)
            ) {
                throw new CorruptHistoryException();
            }
        }
        $this->credit["status"] = $o["delete_records"] ? "D" : "W";
        $this->credit["grade_id"] = $o["grade_id"];
        $this->credit["completed_milli"] = 0;
        $this->credit["grade_points_milli"] = $o["counts_gpa"]
        ? intdiv($this->credit["attempted_milli"] * $o["points_milli"], 1000)
        : 0;
        $this->grade = $p["grade"];
        ++$this->credit["version"];
        new CreditSnapshot($this->credit);
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
    public function completedOn(): ?string
    {
        return $this->completedOn;
    }
}
