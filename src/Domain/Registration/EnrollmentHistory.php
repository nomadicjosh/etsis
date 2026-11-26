<?php

declare(strict_types=1);

namespace Domain\Registration;

use Domain\Registration\ValueObject\GraduateEnrollmentEvidence;
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
    private ?\Domain\Registration\ValueObject\SelectedRegistrationCredits $creditChoice = null;
    private ?array $facts = null;
    /** @var array<string,mixed>|null */
    private ?array $source = null;
    /** @var array<string,mixed>|null */
    private ?array $seat = null;
    /** @var list<array<string,mixed>> */
    private array $decisions = [];
    private ?string $decision = null;
    private ?string $provenance = null;
    private ?string $sectionVersion = null;
    private ?\Domain\Registration\ValueObject\PrerequisiteDecision $prerequisites = null;
    private ?\Domain\Registration\ValueObject\RegistrationScheduleDecision $schedule = null;
    private ?\Domain\Registration\ValueObject\CoRequisiteDecision $corequisites = null;
    private array $registrationWindow = ["status" => "not_recorded"];
    private ?string $withdrawalStatus = null;
    private array $reinstatements = [];
    private ?\Domain\Registration\ValueObject\ProgramCampusEvidence $campus = null;
    public function acceptHistorical(
        #[\SensitiveParameter] DomainEvent $event,
        ?\Domain\Shared\EventSourcing\EventContext $context = null,
    ): void {
        if (
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        EnrollmentEventSchema::validate($event::class, $event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
        if (
            $event instanceof \Domain\Registration\Event\EnrollmentWasReinstated &&
            $context !== null
        ) {
            $p = $event->payload();
            $operation = new \Domain\Shared\ValueObject\EntityId($p["operation_id"]);
            if (
                $context->actor === null ||
                $context->origin !== "command" ||
                $context->reason === null ||
                $context->effectiveAt === null ||
                $context->correlation?->value !== $operation->value ||
                $context->causation?->value !== $this->facts["id"] ||
                EnrollmentFacts::utc($context->effectiveAt) !== $p["status_at"] ||
                !hash_equals(
                    $p["intent"],
                    \Domain\Registration\ValueObject\ReinstatementIntent::hash(
                        new \Domain\Shared\ValueObject\EntityId($this->facts["student_id"]),
                        new \Domain\Shared\ValueObject\EntityId($this->facts["section_id"]),
                        new \Domain\Shared\ValueObject\EntityId($this->facts["id"]),
                        $operation,
                        $p["revision"],
                        $context->reason,
                    ),
                )
            ) {
                throw new CorruptHistoryException();
            }
            $this->reinstatements[$operation->value] = [
                "actor_id" => $context->actor->value,
                "intent" => $p["intent"],
                "result" => [
                    "operation_id" => $operation->value,
                    "enrollment_id" => $this->facts["id"],
                    "status" => $p["status"],
                    "version" => $p["enrollment_version"],
                    "academic_credit_id" => $p["academic_credit_id"],
                    "academic_version" => $p["academic_version"],
                ],
            ];
        }
    }
    public function whenEnrollmentWasEstablished(EnrollmentWasEstablished $event): void
    {
        $p = $event->payload();
        if ($this->facts !== null || $p["enrollment"]["id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->eligibilityEvidence($event->metadata(), $p);
        if (array_key_exists("student_credit_choice", $event->metadata())) {
            if ($p["provenance"] !== "registered") {
                throw new CorruptHistoryException();
            }
            $this->creditChoice = \Domain\Registration\ValueObject\SelectedRegistrationCredits::from(
                $event->metaParam("student_credit_choice")
            );
            $this->creditChoice->routing($p["source"]["course_version_id"], $this->sectionVersion);
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
    private function eligibilityEvidence(array $metadata, array $p): void
    {
        $this->campus = isset($metadata['program_campus_evidence'])
        ? new \Domain\Registration\ValueObject\ProgramCampusEvidence($metadata['program_campus_evidence']) : null;
        $this->campus?->routing(
            $p['enrollment']['student_id'],
            $p['enrollment']['section_id'],
            $metadata['section_version_id'] ?? null,
            $metadata['prerequisite_evidence']['assignment_id'] ?? ''
        );
        $this->sectionVersion = $metadata["section_version_id"] ?? null;
        if ($this->sectionVersion !== null) {
            new \Domain\Shared\ValueObject\EntityId($this->sectionVersion);
            if ($p["provenance"] !== "registered") {
                throw new CorruptHistoryException();
            }
        }
        if (isset($metadata['graduate_course_evidence'])) {
            $graduate = new GraduateEnrollmentEvidence($metadata['graduate_course_evidence']);
            $graduate->routing(
                $p['enrollment']['student_id'],
                $p['enrollment']['section_id'],
                $p['source']['course_version_id'],
                $metadata['section_version_id'] ?? null,
                $metadata['prerequisite_evidence']['assignment_id'] ?? ''
            );
        }
        $evidence = $metadata["prerequisite_evidence"] ?? null;
        if (array_key_exists("prerequisite_evidence", $metadata)) {
            if (!is_array($evidence) || $p["provenance"] !== "registered") {
                throw new CorruptHistoryException();
            }
            $decision = \Domain\Registration\ValueObject\PrerequisiteDecision::fromRetained(
                $evidence,
            );
            if ($decision->required !== $p["eligibility"]["required_course_ids"]) {
                throw new CorruptHistoryException();
            }
            if (
                $decision->groups?->hasRecency()
                && ($decision->completionAnchor['section_version_id'] ?? null) !== $this->sectionVersion
            ) {
                throw new CorruptHistoryException();
            }
            if ($decision->permission !== null) {
                $permission = $decision->permission->data;
                if (
                    $permission["student_id"] !== $p["enrollment"]["student_id"] ||
                    $permission["section_id"] !== $p["enrollment"]["section_id"] ||
                    $permission["term_id"] !== $p["enrollment"]["term_id"] ||
                    $permission["course_version_id"] !== $p["source"]["course_version_id"] ||
                    new \DateTimeImmutable($permission["expires_at"]) <=
                        new \DateTimeImmutable($p["enrollment"]["registered_at"])
                ) {
                    throw new CorruptHistoryException();
                }
            }
            $this->prerequisites = $decision;
        }
        if (array_key_exists("schedule_evidence", $metadata)) {
            $evidence = $metadata["schedule_evidence"];
            if (!is_array($evidence) || $p["provenance"] !== "registered") {
                throw new CorruptHistoryException();
            }
            $decision = new \Domain\Registration\ValueObject\RegistrationScheduleDecision(
                $evidence,
            );
            if (
                $decision->blocked() ||
                $evidence["student_id"] !== $p["enrollment"]["student_id"] ||
                $evidence["target_section_id"] !== $p["enrollment"]["section_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $this->schedule = $decision;
        }
        if (array_key_exists("corequisite_evidence", $metadata)) {
            $evidence = $metadata["corequisite_evidence"];
            if (!is_array($evidence) || $p["provenance"] !== "registered") {
                throw new CorruptHistoryException();
            }
            $this->corequisites = \Domain\Registration\ValueObject\CoRequisiteDecision::fromRetained(
                $evidence,
            );
            if (
                $evidence["student_id"] !== $p["enrollment"]["student_id"] ||
                $evidence["term_id"] !== $p["enrollment"]["term_id"] ||
                $evidence["assignment_id"] !== $this->prerequisites?->assignment->value
            ) {
                throw new CorruptHistoryException();
            }
        }
        if (array_key_exists("registration_window", $metadata)) {
            $reference = $metadata["registration_window"];
            \Domain\Registration\ValueObject\RegistrationWindowDefinition::reference($reference);
            if (
                $p["provenance"] !== "registered" ||
                $reference["term_id"] !== $p["enrollment"]["term_id"]
            ) {
                throw new CorruptHistoryException();
            }
        }
        if ($p["provenance"] === "registered") {
            $reference = $metadata["registration_window"] ?? null;
            $this->registrationWindow =
            [
                    "status" => $reference === null ? "unpublished" : "published",
                    "version" => $reference["version"] ?? null,
                ] +
            array_intersect_key(
                $p["eligibility"],
                array_flip(["opens_at", "closes_at", "course_limit"]),
            );
        }
    }
    public function creditChoice(): ?\Domain\Registration\ValueObject\SelectedRegistrationCredits
    {
        return $this->creditChoice;
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
        if (array_key_exists("corequisite_drop_evidence", $event->metadata())) {
            $evidence = $event->metaParam("corequisite_drop_evidence");
            if (!is_array($evidence)) {
                throw new CorruptHistoryException();
            }
            $drop = new \Domain\Registration\ValueObject\CoRequisiteDropDecision($evidence);
            if (
                $drop->blocked() ||
                $evidence["student_id"] !== $this->facts["student_id"] ||
                !in_array($this->facts["section_id"], $evidence["dropping"], true)
            ) {
                throw new CorruptHistoryException();
            }
        }
        $this->withdrawalStatus = $this->facts["status"];
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
    public function whenEnrollmentWasReinstated(
        \Domain\Registration\Event\EnrollmentWasReinstated $event,
    ): void {
        $p = $event->payload();
        if (
            !$this->canReinstate() ||
            $p["previous_decision_id"] !== $this->decision ||
            $p["status"] !== $this->withdrawalStatus ||
            $p["enrollment_version"] !== $this->facts["version"] + 1 ||
            $p["status_at"] < $this->facts["status_at"] ||
            $p["seat"]["sequence"] <= $this->seat["sequence"] ||
            !is_array($event->metaParam("reinstatement_evidence", null))
        ) {
            throw new CorruptHistoryException();
        }
        $restored = array_replace($this->facts, [
            "status" => $p["status"],
            "status_at" => $p["status_at"],
            "version" => $p["enrollment_version"],
        ]);
        $offering = $this->sectionVersion;
        $this->eligibilityEvidence($event->metaParam("reinstatement_evidence"), [
            "provenance" => "registered",
            "enrollment" => $restored,
            "source" => $this->source,
            "eligibility" => $p["eligibility"],
        ]);
        if (
            $offering !== $this->sectionVersion ||
            ($this->prerequisites?->permission !== null &&
                new \DateTimeImmutable($this->prerequisites->permission->data["expires_at"]) <=
                    new \DateTimeImmutable($p["status_at"]))
        ) {
            throw new CorruptHistoryException();
        }
        $this->facts = $restored;
        new EnrollmentFacts($this->facts);
        $this->seat = $p["seat"];
        $this->decision = $p["decision_id"];
        $this->withdrawalStatus = null;
        $this->index($event);
    }
    public function canReinstate(): bool
    {
        return $this->provenance === "registered" &&
        $this->facts !== null &&
        $this->facts["status"] === "W" &&
        in_array($this->withdrawalStatus, ["A", "N"], true);
    }
    public function reinstatementStatus(): ?string
    {
        return $this->canReinstate() ? $this->withdrawalStatus : null;
    }
    public function reinstatement(\Domain\Shared\ValueObject\EntityId $operation): ?array
    {
        return $this->reinstatements[$operation->value] ?? null;
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
    /** @return array{
     * status:string,required_course_count:?int,supporting_record_count:?int,requirement_group_count?:int
     * } */
    public function prerequisiteSummary(): array
    {
        return [
            "status" =>
                $this->prerequisites === null
                    ? "not_recorded"
                    : ($this->prerequisites->required === []
                        ? "not_required"
                        : ($this->prerequisites->permission !== null
                            ? "permission"
                            : "verified")),
            "required_course_count" =>
                $this->prerequisites === null ? null : count($this->prerequisites->required),
            "supporting_record_count" =>
                $this->prerequisites === null ? null : count($this->prerequisites->support),
        ] +
        ($this->prerequisites?->groups === null
        ? []
        : [
                        "requirement_group_count" => count($this->prerequisites->groups->groups),
                        "required_group_count" =>
                            $this->prerequisites->groups->rule === null
                                ? $this->prerequisites->groups->requiredRequirements()
                                : null,
                    ] +
        ($this->prerequisites->groups->rule === null ? [] : ["nested_rule" => true]));
    }

    public function scheduleSummary(): array
    {
        return $this->schedule?->summary() ?? [
            "status" => "not_recorded",
            "mode" => null,
            "blocked" => false,
            "conflict_count" => null,
            "unpublished_count" => null,
        ];
    }

    /** Restricted dependency evidence for authorized registration commands only. */
    public function corequisites(): ?array
    {
        return $this->corequisites?->facts;
    }

    public function registrationWindowSummary(): array
    {
        $summary = $this->registrationWindow;
        foreach (["opens_at", "closes_at"] as $key) {
            if (isset($summary[$key])) {
                $summary[$key] = str_replace(" ", "T", $summary[$key]) . "Z";
            }
        }
        return $summary;
    }
    public function sectionVersion(): ?string
    {
        return $this->sectionVersion;
    }

    public function campusEvidence(): ?\Domain\Registration\ValueObject\ProgramCampusEvidence
    {
        return $this->campus;
    }
}
