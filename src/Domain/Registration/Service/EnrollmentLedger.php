<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Catalog\Course;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Registration\Enrollment;
use Domain\Registration\EnrollmentHistory;
use Domain\Registration\Event\EnrollmentWasEstablished;
use Domain\Registration\Event\EnrollmentWasClosed;
use Domain\Registration\Repository\EnrollmentHistoryRepository;
use Domain\Registration\Section;
use Domain\Registration\ValueObject\EnrollmentFacts;
use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Registration\ValueObject\PrerequisiteDecision;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Registration\ValueObject\GraduateEnrollmentEvidence;

/** Internal ledger: registration/drop services own current authorization and student/person/section locks. */
final readonly class EnrollmentLedger
{
    public function __construct(
        private EnrollmentHistoryRepository $records,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private Clock $clock,
        private \Domain\Registration\Repository\SectionVersionRepository $versions,
        private GraduateEvidenceReader $graduate,
    ) {
    }
    /** @param array<string,mixed> $eligibility */
    public function accept(
        Enrollment $enrollment,
        Section $section,
        Course $course,
        #[\SensitiveParameter] array $eligibility,
        EntityId $actor,
        PrerequisiteDecision $prerequisites,
        #[\SensitiveParameter]
        \Domain\Registration\ValueObject\RegistrationScheduleDecision $schedule,
        ?HistoryReadBudget $budget = null,
        ?RegistrationCourseRead $courseRead = null,
        ?\Domain\Registration\ValueObject\CoRequisiteDecision $corequisites = null,
        ?\Domain\Registration\RegistrationWindow $window = null,
        ?array $graduateEvidence = null,
    ): void {
        $this->actor($actor);
        if (
            $enrollment->sectionId->value !== $section->id->value ||
            $enrollment->termId->value !== $section->termId->value ||
            $course->id->value !== $section->courseId->value
        ) {
            throw new CorruptHistoryException();
        }
        $this->verifyCourse($course, $budget ?? new HistoryReadBudget(), $courseRead);
        $selected = $this->verifyOffering($section, $course);
        $this->verifyCompletionAnchor($prerequisites, $selected);
        if (
            $schedule->blocked() ||
            $schedule->facts["student_id"] !== $enrollment->studentId->value ||
            $schedule->facts["target_section_id"] !== $section->id->value
        ) {
            throw new CorruptHistoryException();
        }
        if ($prerequisites->permission !== null) {
            $permission = $prerequisites->permission->data;
            if (
                $permission['student_id'] !== $enrollment->studentId->value ||
                $permission['section_id'] !== $section->id->value ||
                $permission['term_id'] !== $section->termId->value ||
                $permission['course_version_id'] !== $course->publicationVersionId?->value ||
                new \DateTimeImmutable($permission['expires_at']) <= $this->clock->now()
            ) {
                throw new CorruptHistoryException();
            }
        }
        $metadata = [
            "__aggregate_type" => "enrollment",
            "prerequisite_evidence" => $prerequisites->retained(),
            "schedule_evidence" => $schedule->facts,
        ];
        if ($graduateEvidence !== null) {
            $proof = new GraduateEnrollmentEvidence($graduateEvidence);
            $proof->routing(
                $enrollment->studentId->value,
                $section->id->value,
                $course->publicationVersionId?->value,
                $selected?->publication->value,
                $prerequisites->assignment->value
            );
            $this->graduate->verify($proof, $budget ?? new HistoryReadBudget());
            $metadata['graduate_course_evidence'] = $graduateEvidence;
        }
        $required = $course->prerequisiteGroups?->corequisites ?? [];
        if (
            $required !== ($corequisites?->facts['required'] ?? []) ||
            ($corequisites !== null && ($corequisites->facts['student_id'] !== $enrollment->studentId->value ||
                $corequisites->facts['term_id'] !== $section->termId->value ||
                $corequisites->facts['assignment_id'] !== $prerequisites->assignment->value))
        ) {
            throw new CorruptHistoryException();
        }
        if ($corequisites !== null) {
            $metadata['corequisite_evidence'] = $corequisites->retained();
        }
        if ($window?->reference !== null) {
            if (
                $window->reference['term_id'] !== $enrollment->termId->value ||
                $window->values() != array_intersect_key(
                    $eligibility,
                    array_flip(['opens_at', 'closes_at', 'course_limit']),
                )
            ) {
                throw new CorruptHistoryException();
            }
            $metadata['registration_window'] = $window->reference;
        }
        if ($selected !== null) {
            $metadata["section_version_id"] = $selected->publication->value;
        }
        $seat = $this->nextSeat($section, 1);
        $event = EnrollmentWasEstablished::occur(
            new EnrollmentHistoryId($enrollment->id->value),
            [
                "decision_id" => $enrollment->id->value,
                "provenance" => "registered",
                "enrollment" => EnrollmentFacts::fromEnrollment($enrollment)
                    ->facts,
                "source" => $this->source(
                    $section,
                    $enrollment->termId,
                    $course->publicationVersionId,
                ),
                "eligibility" => $eligibility,
                "seat" => $seat,
            ],
            $metadata,
        )->withPlayhead(1);
        $this->history->append(
            $this->stream($enrollment->id),
            $enrollment->id,
            0,
            new EventContext(
                $actor,
                $enrollment->registeredAt,
                $enrollment->id,
                reason: "Registration accepted after eligibility and seat checks.",
            ),
            [$event],
        );
        $root = EnrollmentHistory::root(
            new EnrollmentHistoryId($enrollment->id->value),
        );
        $root->acceptHistorical($event);
        $this->project($root, null);
    }
    public function verifyExisting(
        Enrollment $enrollment,
        Section $section,
        ?HistoryReadBudget $budget = null,
    ): void {
        $this->load($enrollment->id, $budget);
        $this->head($section);
    }
    /** @param array<string,mixed> $outcome */
    public function close(
        Enrollment $closed,
        Section $section,
        array $outcome,
        EventContext $context,
        ?HistoryReadBudget $budget = null,
        ?\Domain\Registration\ValueObject\CoRequisiteDropDecision $corequisites = null,
    ): void {
        if ($context->actor === null || $context->correlation === null) {
            throw new \InvalidArgumentException(
                "A trusted registration context is required.",
            );
        }
        $this->actor($context->actor);
        $root = $this->load($closed->id, $budget);
        $before =
        $this->records->current($closed->id) ??
        throw new CorruptHistoryException();
        if ($root->playhead() === 0) {
            $op = PublishedGradeId::forVersion(
                $context->correlation,
                "registration.observe." . $closed->id->value,
            );
            $base = EnrollmentWasEstablished::occur(
                new EnrollmentHistoryId($closed->id->value),
                [
                    "decision_id" => $op->value,
                    "provenance" => "observed_existing",
                    "enrollment" => $before,
                    "source" => $this->source($section, $closed->termId, null),
                    "eligibility" => null,
                    "seat" => null,
                ],
                ["__aggregate_type" => "enrollment"],
            )->withPlayhead(1);
            $this->history->append(
                $this->stream($closed->id),
                $op,
                0,
                new EventContext(
                    $context->actor,
                    $this->clock->now(),
                    $context->correlation,
                    reason: "Existing enrollment observed before its reviewed closure; " .
                        "earlier decision context is unknown.",
                ),
                [$base],
            );
            $root->acceptHistorical($base);
        }
        $operation = PublishedGradeId::forVersion(
            $context->correlation,
            "registration.close." . $closed->id->value,
        );
        $event = EnrollmentWasClosed::occur(
            new EnrollmentHistoryId($closed->id->value),
            [
                "decision_id" => $operation->value,
                "previous_decision_id" => $root->decision(),
                "status" => $closed->status->value,
                "status_at" => EnrollmentFacts::utc($closed->statusAt),
                "policy" => [
                    "grace_ends_on" => $outcome["grace_ends_on"],
                    "version" => $outcome["policy_version"],
                    "remove_current" => $outcome["delete_records"],
                ],
                "seat" => $this->nextSeat($section, -1),
            ],
            ["__aggregate_type" => "enrollment"] + ($corequisites === null ? [] :
                ['corequisite_drop_evidence' => $corequisites->facts]),
        )->withPlayhead($root->playhead() + 1);
        $this->history->append(
            $this->stream($closed->id),
            $operation,
            $root->playhead(),
            new EventContext(
                $context->actor,
                $context->effectiveAt,
                $context->correlation,
                new EntityId(
                    $root->decision() ?? throw new CorruptHistoryException(),
                ),
                $context->reason,
            ),
            [$event],
        );
        $root->acceptHistorical($event);
        if (
            !$this->same(
                $root->facts(),
                EnrollmentFacts::fromEnrollment($closed)->facts,
            )
        ) {
            throw new CorruptHistoryException();
        }
        $this->project($root, $before["version"]);
    }
    /** Internal exact tracked withdrawal basis; no reconstruction of unknown earlier admission. */
    public function reinstatementBasis(
        Enrollment $enrollment,
        Section $section,
        Course $course,
        HistoryReadBudget $budget,
    ): array {
        $root = $this->load($enrollment->id, $budget);
        if (
            !$root->canReinstate() ||
            $root->source()["course_version_id"] === null ||
            $root->sectionVersion() !== $section->publicationVersionId?->value ||
            !$this->same(
                $root->source(),
                $this->source($section, $enrollment->termId, $course->publicationVersionId),
            )
        ) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "enrollment",
                    "message" =>
                        "Reinstatement needs a tracked withdrawal, its original published course " .
                        "and unchanged offering facts. Review unknown or amended sources with the registrar.",
                ],
            ]);
        }
        return [
            "facts" => $root->facts(),
            "status" => $root->reinstatementStatus(),
            "history_version" => $root->playhead(),
            "section_version_id" => $root->sectionVersion(),
        ];
    }
    public function verifyReinstatementOffering(
        Enrollment $enrollment,
        Section $section,
        Course $course,
        HistoryReadBudget $budget,
    ): void {
        $root = $this->load($enrollment->id, $budget);
        if (
            $root->sectionVersion() !== $section->publicationVersionId?->value ||
            !$this->same(
                $root->source(),
                $this->source($section, $enrollment->termId, $course->publicationVersionId),
            )
        ) {
            throw new \Domain\Shared\Exception\ConcurrentModificationException(
                "The reinstated offering facts changed before completion.",
            );
        }
    }
    public function reinstatementReceipt(
        EntityId $enrollment,
        EntityId $operation,
        HistoryReadBudget $budget,
    ): ?array {
        return $this->load($enrollment, $budget)->reinstatement($operation);
    }
    /** Caller owns authorization, locks and the common transaction. Academic reinstatement has already succeeded. */
    public function reinstate(
        Enrollment $enrollment,
        Section $section,
        array $academic,
        EntityId $operation,
        string $revision,
        array $evidence,
        EventContext $context,
        HistoryReadBudget $budget,
    ): Enrollment {
        $this->actor($context->actor ?? throw new CorruptHistoryException());
        $root = $this->load($enrollment->id, $budget);
        if (
            !$root->canReinstate() ||
            $root->facts()["version"] !== $enrollment->version ||
            $evidence["prerequisites"]->assignment->value !== $academic["assignment_id"]
        ) {
            throw new CorruptHistoryException();
        }
        if ($evidence['prerequisites']->groups?->hasRecency()) {
            $this->verifyCompletionAnchor($evidence['prerequisites'], $this->selectedOffering($section->id));
        }
        $metadata = [
            "__aggregate_type" => "enrollment",
            "prerequisite_evidence" => $evidence["prerequisites"]->retained(),
            "schedule_evidence" => $evidence["schedule"]->facts,
        ];
        if ($root->sectionVersion() !== null) {
            $metadata["section_version_id"] = $root->sectionVersion();
        }
        if (($evidence['graduate_evidence'] ?? null) !== null) {
            $metadata['graduate_course_evidence'] = $evidence['graduate_evidence'];
        }
        if ($evidence["corequisites"] !== null) {
            $metadata["corequisite_evidence"] = $evidence["corequisites"]->retained();
        }
        if ($evidence["window"]->reference !== null) {
            $metadata["registration_window"] = $evidence["window"]->reference;
        }
        unset($metadata["__aggregate_type"]);
        $metadata = ["__aggregate_type" => "enrollment", "reinstatement_evidence" => $metadata];
        $decision = \Domain\Shared\ValueObject\DerivedIdentity::from(
            $operation,
            "reinstatement.enrollment",
        );
        $event = \Domain\Registration\Event\EnrollmentWasReinstated::occur(
            new EnrollmentHistoryId($enrollment->id->value),
            [
                "decision_id" => $decision->value,
                "operation_id" => $operation->value,
                "previous_decision_id" => $root->decision(),
                "status" => $root->reinstatementStatus(),
                "status_at" => EnrollmentFacts::utc(
                    $context->effectiveAt ?? throw new CorruptHistoryException(),
                ),
                "enrollment_version" => $enrollment->version + 1,
                "academic_credit_id" => $academic["credit_id"],
                "academic_version" => $academic["version"],
                "revision" => $revision,
                "intent" => \Domain\Registration\ValueObject\ReinstatementIntent::hash(
                    $enrollment->studentId,
                    $enrollment->sectionId,
                    $enrollment->id,
                    $operation,
                    $revision,
                    $context->reason ?? "",
                ),
                "eligibility" => $evidence["eligibility"],
                "seat" => $this->nextSeat($section, 1, $budget),
            ],
            $metadata,
        )->withPlayhead($root->playhead() + 1);
        $this->history->append(
            $this->stream($enrollment->id),
            $decision,
            $root->playhead(),
            $context,
            [$event],
        );
        $root->acceptHistorical($event, $context);
        $this->project($root, $enrollment->version);
        $facts = $root->facts();
        return new Enrollment(
            $enrollment->id,
            $enrollment->studentId,
            $enrollment->sectionId,
            $enrollment->termId,
            \Domain\Registration\Enum\RegistrationStatus::from($facts["status"]),
            $enrollment->registeredAt,
            $context->effectiveAt,
            $facts["version"],
        );
    }
    /** Restricted curated read, after caller authorizes and locks the student/section.
     * @return array<string,mixed> */
    public function summary(
        EntityId $id,
        ?HistoryReadBudget $budget = null,
    ): array {
        $budget ??= new HistoryReadBudget();
        $root = $this->load($id, $budget);
        $f = $root->facts() ?? throw new CorruptHistoryException();
        $s = $root->source() ?? throw new CorruptHistoryException();
        $page = $budget->page($this->history, $this->stream($id));
        $items = [];
        foreach ($page->events as $stored) {
            $e = $stored->event;
            $p = $e->payload();
            $items[] = [
                "action" => match (true) {
                    $e instanceof \Domain\Registration\Event\EnrollmentWasReinstated => 'reinstated',
                    $e instanceof EnrollmentWasEstablished => $p['provenance'],
                    default => $p['policy']['remove_current'] ? 'early_drop' : 'late_drop',
                },
                "section_sequence" => $p["seat"]["sequence"] ?? null,
                "effective_at" => $stored->context->describe()["effective_at"],
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
            ];
        }
        return [
            "enrollment_id" => $id->value,
            "section_version_id" => $root->sectionVersion(),
            "prerequisites" => $root->prerequisiteSummary(),
            "schedule" => $root->scheduleSummary(),
            "registration_window" => $root->registrationWindowSummary(),
            "student_id" => $f["student_id"],
            "section_id" => $f["section_id"],
            "section_code" => $s["section_code"],
            "term_code" => $s["term_code"],
            "status" => $f["status"],
            "current_records_removed" => $f["status"] === "D",
            "earlier_history_unknown" =>
                $root->provenance() === "observed_existing",
            "items" => $items,
        ];
    }
    /** Internal verification, after the caller authorizes and locks the student.
     * Includes removed enrollment facts for cross-checking, never for ordinary display.
     * @return array<string,mixed>
     */
    public function evaluationFacts(
        EntityId $enrollment,
        EntityId $student,
        HistoryReadBudget $budget,
    ): array {
        $root = $this->load($enrollment, $budget);
        $facts =
        $root->facts() ??
        ($this->records->current($enrollment) ??
        throw new CorruptHistoryException());
        if ($facts["student_id"] !== $student->value) {
            throw new CorruptHistoryException();
        }
        return [
            "facts" => $facts,
            "history_recorded" => $root->playhead() > 0,
            "earlier_history_unknown" =>
                $root->playhead() === 0 ||
                $root->provenance() === "observed_existing",
            "history_version" => $root->playhead(),
        ] + ($root->corequisites() === null ? [] : ["corequisites" => $root->corequisites()]);
    }
    private function load(
        EntityId $id,
        ?HistoryReadBudget $budget = null,
    ): EnrollmentHistory {
        $budget ??= new HistoryReadBudget();
        $root = EnrollmentHistory::root(new EnrollmentHistoryId($id->value));
        $cursor = null;
        do {
            $page = $budget->page($this->history, $this->stream($id), $cursor);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event, $stored->context);
                $graduate = $stored->event->metadata()['graduate_course_evidence']
                ?? $stored->event->metadata()['reinstatement_evidence']['graduate_course_evidence'] ?? null;
                if ($graduate !== null) {
                    $this->graduate->verify(new GraduateEnrollmentEvidence($graduate), $budget);
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $f = $root->facts();
        $m = $this->records->mapping($id);
        if ($f === null) {
            if ($m !== null) {
                throw new CorruptHistoryException();
            }
            return $root;
        }
        $seat = $root->seat() ?? throw new CorruptHistoryException();
        if (
            $m === null ||
            count($m) !== 5 ||
            $m["student_id"] !== $f["student_id"] ||
            $m["section_id"] !== $f["section_id"] ||
            $m["term_id"] !== $f["term_id"] ||
            $m["last_sequence"] !== $seat["sequence"]
        ) {
            throw new CorruptHistoryException();
        }
        if (
            $this->versions->enrollmentVersion($id)?->value !==
            $root->sectionVersion()
        ) {
            throw new CorruptHistoryException();
        }
        $current = $this->records->current($id);
        if (
            $f["status"] === "D"
                ? $current !== null
                : !$this->same($f, $current)
        ) {
            throw new CorruptHistoryException();
        }
        $indices = $this->records->decisions($id);
        if (count($indices) !== count($root->decisions())) {
            throw new CorruptHistoryException();
        }
        foreach ($root->decisions() as $i => $d) {
            if (!$this->same($indices[$i], $d)) {
                throw new CorruptHistoryException();
            }
        }
        return $root;
    }
    /** @return array<string,mixed>|null */
    private function head(Section $section, ?HistoryReadBudget $budget = null): ?array
    {
        $last = $this->records->latest($section->id);
        $maximum = $this->records->maximumSequence($section->id);
        if ($last === null) {
            if ($maximum !== 0) {
                throw new CorruptHistoryException();
            }
            return null;
        }
        if ($last["sequence"] !== $maximum) {
            throw new CorruptHistoryException();
        }
        $root = $this->load(new EntityId($last["enrollment_id"]), $budget);
        $seat = $root->seat();
        if (
            $root->decision() !== $last["id"] ||
            $root->playhead() !== $last["stream_version"] ||
            ($root->facts() ?? throw new CorruptHistoryException())[
                "section_id"
            ] !== $section->id->value ||
            $seat === null ||
            $seat["sequence"] !== $last["sequence"] ||
            $seat["used_after"] !== $this->records->usedSeats($section->id)
        ) {
            throw new CorruptHistoryException();
        }
        return $last;
    }
    /** @return array<string,mixed> */
    private function nextSeat(Section $section, int $delta, ?HistoryReadBudget $budget = null): array
    {
        $last = $this->head($section, $budget);
        $before = $this->records->usedSeats($section->id);
        if (
            ($delta === 1 &&
                ($before >= $section->capacity || !$section->active)) ||
            ($delta === -1 && $before < 1)
        ) {
            throw new \Domain\Shared\Exception\ConcurrentModificationException(
                "The section no longer has the reviewed seat availability.",
            );
        }
        return [
            "sequence" => ($last["sequence"] ?? 0) + 1,
            "previous_decision_id" => $last["id"] ?? null,
            "used_before" => $before,
            "used_after" => $before + $delta,
            "capacity" => $section->capacity,
        ];
    }
    /** Internal coordinator read: callers own current student/section authorization. */
    public function selectedOffering(EntityId $section): ?\Domain\Registration\ValueObject\PublishedSectionDefinition
    {
        return SectionOfferingSelection::verify($section, $this->versions, $this->history);
    }

    /** Internal command check; the coordinator owns authorization and offering locks. */
    public function verifyOffering(
        Section $section,
        Course $course,
    ): ?\Domain\Registration\ValueObject\PublishedSectionDefinition {
        $selected = $this->selectedOffering($section->id);
        if (
            $selected?->publication->value !==
            $section->publicationVersionId?->value
        ) {
            throw new CorruptHistoryException();
        }
        if (
            $selected === null &&
            ($section->continuingEducation->mode !== 'inherit' || $course->continuingEducation?->milli !== null)
        ) {
            throw new \DomainException('Publish and select the offered CEUs before registration.');
        }
        if (
            $selected !== null &&
            !$selected->matchesOffering($section, $course)
        ) {
            throw new \DomainException(
                "This offering changed after version selection. Registrar review is required.",
            );
        }
        return $selected;
    }

    public function verifyCourse(Course $course, HistoryReadBudget $budget, ?RegistrationCourseRead $read = null): void
    {
        ($read ?? new RegistrationCourseRead())->verify(
            $course,
            new \Domain\Catalog\Service\PublishedCourseReader($this->history),
            $budget
        );
    }

    private function verifyCompletionAnchor(
        PrerequisiteDecision $decision,
        ?\Domain\Registration\ValueObject\PublishedSectionDefinition $selected,
    ): void {
        if (!$decision->groups?->hasRecency()) {
            return;
        }
        if (
            ($decision->completionAnchor['section_version_id'] ?? null) !== $selected?->publication->value
            || ($decision->completionAnchor['starts_on'] ?? null)
                !== ($selected?->definition->data['instruction_starts_on'] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
    }

    /** @return array<string,mixed> */
    private function source(
        Section $s,
        EntityId $term,
        ?EntityId $courseVersion,
    ): array {
        return [
            "course_id" => $s->courseId->value,
            "course_version_id" => $courseVersion?->value,
            "section_code" => $s->code,
            "term_code" => $this->records->termCode($term),
            "capacity" => $s->capacity,
            "active" => $s->active,
            "web_registration" => $s->webRegistration,
            "fee_minor" => $s->fee->minor,
            "currency" => $s->fee->currency,
            "delivery_mode" => $s->deliveryMode->value,
            "faculty_user_id" => $s->facultyUserId?->value,
        ];
    }
    private function project(
        EnrollmentHistory $root,
        ?int $expectedVersion,
    ): void {
        $f = $root->facts() ?? throw new CorruptHistoryException();
        $seat = $root->seat() ?? throw new CorruptHistoryException();
        $this->records->project($f, $seat["sequence"], $expectedVersion);
        $this->versions->retainEnrollmentVersion(
            new EntityId($f["id"]),
            $root->sectionVersion() === null
                ? null
                : new EntityId($root->sectionVersion()),
        );
        $last = $root->decisions();
        $this->records->index($last[count($last) - 1]);
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(
            new EnrollmentHistoryId($id->value),
            "enrollment",
        );
    }
    private function actor(EntityId $actor): void
    {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException(
                "The registration actor changed.",
            );
        }
    }
    /** @param array<string,mixed>|null $a
     * @param array<string,mixed>|null $b */
    private function same(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $key => $v) {
            if (!array_key_exists($key, $b) || $b[$key] !== $v) {
                return false;
            }
        }
        return true;
    }
}
