<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Service;

use Domain\Gradebook\Service\CourseworkGradeService;
use Domain\Gradebook\ValueObject\CourseworkGradeEvidence;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished;
use Domain\AcademicRecord\Event\FinalGradeWasDecided;
use Domain\AcademicRecord\Event\InstitutionalCreditWasDropped;
use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\AcademicRecord\ValueObject\CreditSnapshot;
use Domain\AcademicRecord\ValueObject\CreditUnitCorrection;
use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\AcademicRecord\ValueObject\WithdrawnCreditQuantities;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Course;
use Domain\Communications\Service\MessageCipher;
use Domain\Gradebook\Service\AcademicGradeCatalog;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Registration\Enrollment;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Qubus\Exception\Data\TypeException;
use Throwable;

final readonly class InstitutionalCreditLedger
{
    public const int REVIEW_TOKEN_LIMIT = 262144;

    public function __construct(
        private AcademicCreditRepository $records,
        private HistoricalEventStore $history,
        private AcademicGradeCatalog $grades,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private \Domain\Registration\Repository\SectionVersionRepository $versions,
        private WorkPursuitLedger $pursuits,
        private \Domain\AcademicRecord\Repository\GradeChangeRepository $changes,
        private CourseworkGradeService $coursework,
    ) {
    }

    /** Internal registration command. Caller has already authorized/locked the student and section. */
    public function open(
        Enrollment $enrollment,
        Course $course,
        EntityId $actor,
        EntityId $assignment,
        ?EntityId $sectionVersion = null,
    ): void {
        $this->transactions->run(function () use (
            $enrollment,
            $course,
            $actor,
            $assignment,
            $sectionVersion,
        ): void {
            $this->actor($actor);
            $id = EntityId::generate();
            $facts = new CreditSnapshot([
                "id" => $id->value,
                "student_id" => $enrollment->studentId->value,
                "course_id" => $course->id->value,
                "term_id" => $enrollment->termId->value,
                "source_id" => $enrollment->id->value,
                "credit_type" => "institutional",
                "course_code" => $course->code,
                "course_title" => $course->publishedTranscriptTitle ?? $course->title,
                "attempted_milli" => $course->creditsMilli,
                "completed_milli" => 0,
                "grade_points_milli" => 0,
                "grade_id" => null,
                "status" => $enrollment->status->value,
                "version" => 0,
                "course_version_id" => $course->publicationVersionId?->value,
            ]);
            if ($this->versions->enrollmentVersion($enrollment->id)?->value !== $sectionVersion?->value) {
                throw new CorruptHistoryException();
            }
            $metadata = ["__aggregate_type" => "academic_credit"];
            if ($sectionVersion !== null) {
                $metadata["section_version_id"] = $sectionVersion->value;
            }
            $event = InstitutionalCreditWasEstablished::occur(
                new AcademicCreditId($id->value),
                [
                    "decision_id" => $id->value,
                    "provenance" => "registration",
                    "credit" => $facts->facts,
                    "section_id" => $enrollment->sectionId->value,
                    "policies" => $this->grades->capturePolicies($enrollment->studentId),
                    "grade" => null,
                ],
                $metadata,
            )->withPlayhead(1);
            $this->history->append(
                $this->stream($id),
                $id,
                0,
                new EventContext(
                    $actor,
                    $enrollment->registeredAt,
                    $enrollment->id,
                    $enrollment->id,
                    reason: "Academic credit opened by accepted registration.",
                ),
                [$event],
            );
            $root = AcademicCredit::root(new AcademicCreditId($id->value));
            $root->acceptHistorical($event);
            $this->records->project(
                $root->credit() ?? throw new CorruptHistoryException(),
                $enrollment->sectionId,
                null,
            );
            $this->pursuits->attributeNew($facts->facts, $assignment, $actor);
        });
    }
    /** Authorized teaching/office choice read; no baseline event is created.
     * @return array<string,mixed>
     */
    public function choices(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        ?EntityId $assignment = null,
    ): array {
        $actor = $this->readAuthority($section);
        [$id, $root, $facts] = $this->source($enrollment, $student, $section);
        $withdrawn = $facts["status"] === "W";
        $this->gradeEnrollmentScope(
            $enrollment,
            $student,
            $section,
            new EntityId($root->registrationTermId() ?? $facts["term_id"]),
            $withdrawn,
        );
        $policies = $root->policies();
        $pursuits =
        $policies === []
        ? $this->grades->pursuits($student)
        : array_map(
            static fn(array $p): array => [
                        "id" => $p["assignment_id"],
                        "program_id" => $p["program_id"],
                        "code" => $p["label"],
                        "title" => "Retained grading context",
                    ],
            $policies,
        );
        try {
            $items = $this->gradeChoices($root, $facts, $student, $assignment);
            $needs = false;
        } catch (\Domain\Gradebook\Exception\GradingContextRequiredException) {
            $items = [];
            $needs = true;
        }
        $observedGrade = $this->observedGrade($root, $facts);
        $canAmend = $this->canAmend($withdrawn);
        $result = [
            "items" => $items,
            "pursuits" => $pursuits,
            "needs_assignment" => $needs,
            "effective_on_default" => $this->clock->now()->format("Y-m-d"),
            "current_version" => $facts["version"],
            "enrollment_id" => $enrollment->value,
            "completed_on" => $root->completedOn(),
            "academic_on" => $root->academicOn(),
            "can_amend" => $canAmend,
            "withdrawal_grade_correction" => $withdrawn,
            "missing_withdrawal_grade" => $withdrawn && $facts["grade_id"] === null,
            "requires_quantity_reconciliation" => WithdrawnCreditQuantities::requiresReview(
                $facts,
                $observedGrade,
            ),
            "withdrawal_quantities" => $withdrawn
                ? WithdrawnCreditQuantities::summary($facts, $observedGrade)
                : null,
            "posting_term_id" => $facts["term_id"],
            "posting_term_code" =>
                $root->postingTerm()["term_code"] ??
                $this->records->term(new EntityId($facts["term_id"]))["code"],
            "can_correct_posting_term" =>
                $canAmend &&
                $root->playhead() > 0 &&
                $root->grade() !== null &&
                $root->canCorrectSource() &&
                $root->pendingGradeChange() === null &&
                $this->canCorrectPlacement(),
            "current_credit_quantities" => array_intersect_key(
                $facts,
                array_flip(["attempted_milli", "completed_milli", "grade_points_milli"]),
            ),
            "can_correct_credit_units" =>
                $canAmend &&
                $observedGrade !== null &&
                $facts["grade_id"] !== null &&
                $this->canCorrectUnits(),
            "requires_approval" => $this->changes->policy()["mode"] === "approval",
            "pending_grade_change" =>
                $root->pendingGradeChange() === null
                    ? null
                    : [
                        "id" => $root->pendingGradeChange()["details"]["decision_id"],
                        "status" => "pending",
                    ],
            "can_correct_course" => $canAmend && $root->canCorrectAwardedCourse() && $this->canCorrectCourse(),
            "course_id" => $facts["course_id"],
            "can_accept_course" =>
                $canAmend &&
                $root->canAcceptCourse() &&
                (!$withdrawn || $root->canCorrectSource()) &&
                ($root->playhead() > 0 || $facts["course_version_id"] === null),
            "course_version_id" => $facts["course_version_id"],
            "history_recorded" => $root->playhead() > 0,
            "posted" => $withdrawn || new CreditSnapshot($facts)->posted(),
            "can_record_academic_period" =>
                $canAmend &&
                $root->canCorrectSource() &&
                $root->sectionVersion() === null &&
                $root->playhead() > 0 &&
                $root->grade() !== null &&
                $facts["grade_id"] !== null,
            "current_grade_code" => $facts["grade_id"] === null ? null : $observedGrade["code"],
        ];
        if (!$result["posted"] && !$needs && $this->canReadCoursework($section)) {
            $suggestion = $this->coursework->assess($student, $section, $items);
            unset($suggestion["evidence"]);
            $result["coursework"] = $suggestion;
            $this->authority($section, false);
        }
        $this->actor($actor);
        $this->readAuthority($section);
        return $result;
    }
    /** @return array<string,mixed> */
    public function review(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        EntityId $grade,
        int $version,
        ?EntityId $assignment = null,
        #[\SensitiveParameter] string $reason = "",
        ?string $effective = null,
        bool $gradeEvidence = false,
        ?string $courseworkRevision = null,
        bool $initialOnly = false,
        bool $reconcileQuantities = false,
        ?int $attemptedMilli = null,
    ): array {
        $this->readAuthority($section);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $grade,
            $version,
            $assignment,
            $reason,
            $effective,
            $gradeEvidence,
            $courseworkRevision,
            $initialOnly,
            $reconcileQuantities,
            $attemptedMilli,
        ): array {
            $this->records->lockStudent($student);
            $this->records->lockSection($section);
            [$id, $root, $facts] = $this->source($enrollment, $student, $section);
            $withdrawn = $facts["status"] === "W";
            $this->gradeEnrollmentScope(
                $enrollment,
                $student,
                $section,
                new EntityId($root->registrationTermId() ?? $facts["term_id"]),
                $withdrawn,
            );
            if ($facts["version"] !== $version) {
                throw new ConcurrentModificationException(
                    "Reload the academic record before reviewing a grade.",
                );
            }
            $amend = $withdrawn || new CreditSnapshot($facts)->posted();
            if ($initialOnly && $amend) {
                throw new ConcurrentModificationException(
                    "This record already has a final grade. Use the individual correction workflow.",
                );
            }
            if ($root->pendingGradeChange() !== null) {
                throw new ConcurrentModificationException(
                    "Resolve the pending grade correction before requesting another.",
                );
            }
            $actor = $this->authority($section, $amend, $withdrawn, $attemptedMilli !== null);
            $observedGrade = $this->observedGrade($root, $facts);
            $units = null;
            if ($attemptedMilli !== null) {
                if (
                    !$amend ||
                    $facts["grade_id"] === null ||
                    $observedGrade === null ||
                    $attemptedMilli < 0 ||
                    $attemptedMilli > 999999 ||
                    $attemptedMilli === $facts["attempted_milli"]
                ) {
                    self::invalid(
                        "attempted_milli",
                        "Enter different credit units from 0 to 999.999 for a posted grade.",
                    );
                }
                $units = new CreditUnitCorrection($facts["attempted_milli"], $attemptedMilli);
            }
            $needsReconciliation = WithdrawnCreditQuantities::requiresReview($facts, $observedGrade);
            if ($needsReconciliation !== $reconcileQuantities) {
                self::invalid(
                    "reconcile_withdrawal_quantities",
                    $needsReconciliation
                        ? "Verify and explicitly confirm reconciliation of this withdrawn record's credit quantities."
                        : "This record does not need withdrawn-credit reconciliation. Reload its grading record.",
                );
            }
            if ($withdrawn && $facts["grade_id"] === null && !$gradeEvidence) {
                self::invalid(
                    "grade_evidence",
                    "Confirm that you verified this grade against institutional records.",
                );
            }
            $policy = $this->changes->policy();
            $reason = trim($reason);
            if ($reason === "" && !$amend) {
                $reason = "Initial final grade recorded.";
            }
            if (
                $reason === "" ||
                mb_strlen($reason) > 1000 ||
                !mb_check_encoding($reason, "UTF-8") ||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
            ) {
                self::invalid("reason", "Enter a correction reason of at most 1,000 characters.");
            }
            $when = $this->effective($enrollment, $effective);
            $selected = null;
            $available = $this->gradeChoices($root, $facts, $student, $assignment);
            foreach ($available as $r) {
                if ($r["id"] === $grade->value) {
                    $selected = GradeSnapshot::fromRow($r)->facts;
                }
            }
            if ($selected === null) {
                self::invalid("grade_id", "Choose a grade from this record’s retained grading policy.");
            }
            $evidence = null;
            if ($courseworkRevision !== null) {
                $this->authority($section, false);
                $assessment = $this->coursework->assess($student, $section, $available);
                $evidence = $assessment["evidence"] ?? null;
                if (
                    $amend ||
                    $evidence === null ||
                    $evidence->facts["grade_id"] !== $grade->value ||
                    !hash_equals($evidence->revision(), $courseworkRevision)
                ) {
                    self::invalid(
                        "coursework_revision",
                        "The coursework suggestion changed. Reload it or choose a grade manually.",
                    );
                }
            }
            $op = EntityId::generate();
            $baseline = $root->playhead() === 0;
            $baseOp = $this->operation($id, $op, "observation");
            $data = [
                "kind" => "academic-final-grade-review.v1",
                "student_id" => $student->value,
                "section_id" => $section->value,
                "enrollment_id" => $enrollment->value,
                "credit_id" => $id->value,
                "operation_id" => $op->value,
                "actor_id" => $actor->value,
                "amendment" => $amend,
                "withdrawal_grade_correction" => $withdrawn,
                "missing_withdrawal_grade" => $withdrawn && $facts["grade_id"] === null,
                "requires_approval" => $amend && $policy["mode"] === "approval",
                "grade_change_policy" => $policy,
                "expected_version" => $root->playhead(),
                "before" => $facts,
                "baseline" => $baseline,
                "baseline_operation_id" => $baseOp->value,
                "previous_decision_id" => $baseline ? $baseOp->value : $root->decision(),
                "grade" => $selected,
                "assignment_id" => $assignment?->value,
                "reason" => $reason,
                "effective_at" => $when->format(DATE_ATOM),
                "observed_at" => $this->clock->now()->format(DATE_ATOM),
                "expires_at" => $this->clock->now()->getTimestamp() + 1200,
                "earlier_history_unknown" => $baseline || $root->provenance() === "observed_existing",
                "observed_grade" => $observedGrade,
            ];
            if ($reconcileQuantities) {
                $data["withdrawal_quantity_reconciliation"] = true;
                $data["withdrawal_quantities"] = WithdrawnCreditQuantities::summary($facts, $selected);
            }
            if ($units !== null) {
                $data["credit_unit_correction"] = $units->facts();
                $data["credit_unit_summary"] = $units->summary($facts, $selected);
                if ($reconcileQuantities) {
                    $data["withdrawal_quantities"]["expected_grade_points_milli"] =
                        $data["credit_unit_summary"]["grade_points_milli"];
                }
            }
            if ($evidence !== null) {
                $data["coursework"] = $evidence->facts;
            }
            $this->actor($actor);
            $this->authority($section, $amend, $withdrawn, $units !== null);
            $token = $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR));
            if (strlen($token) > self::REVIEW_TOKEN_LIMIT) {
                self::invalid(
                    "coursework_revision",
                    "This calculation exceeds the review size limit. Choose a grade manually.",
                );
            }
            unset($data["coursework"]);
            return $data + [
                "review_token" => $token,
                ...$evidence === null ? [] : ["coursework_summary" => $evidence->summary()],
            ];
        });
    }
    /** @return array{operation_id:string,first_version:int,last_version:int,event_ids:list<string>} */
    public function commit(EntityId $student, EntityId $section, #[\SensitiveParameter] string $token): array
    {
        try {
            if (strlen($token) > self::REVIEW_TOKEN_LIMIT) {
                throw new InvalidArgumentException();
            }
            $d = json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($d) ||
                ($d["kind"] ?? null) !== "academic-final-grade-review.v1" ||
                ($d["student_id"] ?? null) !== $student->value ||
                ($d["section_id"] ?? null) !== $section->value
            ) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable) {
            self::invalid("review_token", "Review the final grade again.");
        }
        $actor = $this->authority(
            $section,
            $d["amendment"],
            $d["withdrawal_grade_correction"] ?? false,
            isset($d["credit_unit_correction"]),
        );
        if ($actor->value !== $d["actor_id"]) {
            throw new AccessDeniedException("This grade review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($student, $section, $d, $actor): array {
            $this->records->lockStudent($student);
            $this->records->lockSection($section);
            $this->actor($actor);
            $this->authority(
                $section,
                $d["amendment"],
                $d["withdrawal_grade_correction"] ?? false,
                isset($d["credit_unit_correction"]),
            );
            $id = new EntityId($d["credit_id"]);
            $root = $this->load($id);
            $op = new EntityId($d["operation_id"]);
            if ($d["baseline"]) {
                $baseOp = new EntityId($d["baseline_operation_id"]);
                $base = $this->baseline($id, $baseOp, $section, $d["before"], $d["observed_grade"]);
                $this->history->append(
                    $this->stream($id),
                    $baseOp,
                    0,
                    new EventContext(
                        $actor,
                        new DateTimeImmutable($d["observed_at"]),
                        $op,
                        reason: "Existing academic facts observed at a reviewed grade decision; " .
                            "earlier action times and actors are unknown.",
                    ),
                    [$base],
                );
            }
            $requested = ($d["requires_approval"] ?? false) === true;
            $class = $requested
                ? \Domain\AcademicRecord\Event\GradeChangeWasRequested::class
                : FinalGradeWasDecided::class;
            $payload = [
                "decision_id" => $op->value,
                "previous_decision_id" => $d["previous_decision_id"],
                "grade" => $d["grade"],
                "assignment_id" => $d["assignment_id"],
            ];
            $metadata = ["__aggregate_type" => "academic_credit"];
            if (isset($d["coursework"])) {
                $metadata["coursework"] = $d["coursework"];
            }
            if ($d["withdrawal_grade_correction"] ?? false) {
                $metadata["withdrawal_grade_correction"] = true;
            }
            if ($d["withdrawal_quantity_reconciliation"] ?? false) {
                $metadata["withdrawal_quantity_reconciliation"] = true;
            }
            if (isset($d["credit_unit_correction"])) {
                $metadata["credit_unit_correction"] = $d["credit_unit_correction"];
            }
            if ($requested) {
                $payload += [
                    "actor_id" => $actor->value,
                    "before_grade" => $d["observed_grade"],
                    "reason" => $d["reason"],
                    "effective_at" => $d["effective_at"],
                    "policy" => $d["grade_change_policy"],
                    "credit_version" => $d["before"]["version"],
                ];
            } elseif ($d["amendment"] && isset($d["grade_change_policy"])) {
                $metadata["grade_change_policy"] = $d["grade_change_policy"];
            }
            $event = $class
                ::occur(new AcademicCreditId($id->value), $payload, $metadata)
                ->withPlayhead($d["expected_version"] + ($d["baseline"] ? 2 : 1));
            $receipt = $this->history->append(
                $this->stream($id),
                $op,
                $event->playhead() - 1,
                new EventContext(
                    $actor,
                    new DateTimeImmutable($requested ? $d["observed_at"] : $d["effective_at"]),
                    $op,
                    new EntityId($d["previous_decision_id"]),
                    $d["reason"],
                ),
                [$event],
            );
            if ($receipt["event_ids"] === [(string) $event->eventId()]) {
                if ($d["amendment"]) {
                    if (
                        !$this->same($d["grade_change_policy"] ?? null, $this->changes->policy(current: true))
                    ) {
                        throw new ConcurrentModificationException(
                            "The grade-change policy changed. Review the correction again.",
                        );
                    }
                }
                if ($root->pendingGradeChange() !== null) {
                    throw new ConcurrentModificationException("Another grade correction is awaiting review.");
                }
                // Check new-command expiry after the journal recognizes completed original retries.
                // Older accepted tokens retain their exact event/receipt contract; an unaccepted
                // review with no expiry must be refreshed. Failure rolls back both baseline and grade.
                if (
                    !is_int($d["expires_at"] ?? null) ||
                    $d["expires_at"] <= $this->clock->now()->getTimestamp()
                ) {
                    throw new ConcurrentModificationException(
                        "This grade review expired. Change the review and review the final grade again.",
                    );
                }
                $current = $this->records->projection($id) ?? throw new CorruptHistoryException();
                $this->gradeEnrollmentScope(
                    new EntityId($d["enrollment_id"]),
                    $student,
                    $section,
                    new EntityId($root->registrationTermId() ?? $d["before"]["term_id"]),
                    $d["withdrawal_grade_correction"] ?? false,
                );
                if (!$this->same($current, $d["before"])) {
                    throw new ConcurrentModificationException(
                        "The academic facts changed. Review the grade again.",
                    );
                }
                if (
                    WithdrawnCreditQuantities::requiresReview($current, $d["observed_grade"]) !==
                    ($d["withdrawal_quantity_reconciliation"] ?? false)
                ) {
                    throw new ConcurrentModificationException(
                        "Review and explicitly confirm the withdrawn-credit quantities again.",
                    );
                }
                $selected = null;
                $available = $this->gradeChoices(
                    $root,
                    $current,
                    $student,
                    $d["assignment_id"] === null ? null : new EntityId($d["assignment_id"]),
                );
                foreach ($available as $row) {
                    if ($row["id"] === $d["grade"]["id"]) {
                        $selected = GradeSnapshot::fromRow($row)->facts;
                    }
                }
                if (!$this->same($selected, $d["grade"])) {
                    throw new ConcurrentModificationException(
                        "The grading policy changed. Review the grade again.",
                    );
                }
                if (isset($d["coursework"])) {
                    $this->verifyCourseworkEvidence($student, $section, $available, $d);
                }
                if ($d["baseline"]) {
                    $root->acceptHistorical($base);
                }
                $root->acceptHistorical($event);
                if ($requested) {
                    if ($d["baseline"]) {
                        $this->records->track($root->credit(), $section);
                    }
                    $this->projectGradeChange(
                        $root,
                        $op,
                        $student,
                        $section,
                        new EntityId($d["enrollment_id"]),
                        null,
                    );
                } else {
                    $this->records->project(
                        $root->credit() ?? throw new CorruptHistoryException(),
                        $section,
                        $current["version"],
                    );
                }
                $this->audit->record(
                    $actor,
                    $requested
                        ? "academic.grade_change_requested"
                        : ($d["amendment"]
                            ? "academic.grade_amended"
                            : "academic.grade_assigned"),
                    $id,
                );
                if (isset($d["coursework"])) {
                    $this->verifyCourseworkEvidence($student, $section, $available, $d);
                }
                if (
                    $d["amendment"] &&
                    !$this->same($d["grade_change_policy"], $this->changes->policy(current: true))
                ) {
                    throw new ConcurrentModificationException(
                        "The grade-change policy changed during confirmation. Review again.",
                    );
                }
                if ($requested) {
                    $this->verifyGradeChange($root, $this->changes->request($op));
                    if (!$this->same($root->credit(), $this->records->projection($id))) {
                        throw new CorruptHistoryException();
                    }
                }
                if ($d["expires_at"] <= $this->clock->now()->getTimestamp()) {
                    throw new ConcurrentModificationException(
                        "This grade review expired during confirmation. Review again.",
                    );
                }
            }
            $this->actor($actor);
            $this->authority(
                $section,
                $d["amendment"],
                $d["withdrawal_grade_correction"] ?? false,
                isset($d["credit_unit_correction"]),
            );
            return $receipt;
        });
    }

    /** @param list<array<string,mixed>> $grades
     * @param array<string,mixed> $review
     */
    private function verifyCourseworkEvidence(
        EntityId $student,
        EntityId $section,
        array $grades,
        array $review,
    ): void {
        $this->authority($section, false);
        $evidence = new CourseworkGradeEvidence($review["coursework"]);
        $assessment = $this->coursework->assess($student, $section, $grades);
        $currentEvidence = $assessment["evidence"] ?? null;
        if (
            $review["amendment"] ||
            $currentEvidence === null ||
            !hash_equals($evidence->revision(), $currentEvidence->revision())
        ) {
            throw new ConcurrentModificationException(
                "The coursework changed. Review the final grade again.",
            );
        }
    }

    private function canReadCoursework(EntityId $section): bool
    {
        try {
            $this->authority($section, false);
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
    /** @return array<string, mixed> */
    public function gradeChangePolicy(): array
    {
        $this->access->requirePermission("sis:gradebook:policy");
        return $this->changes->policy();
    }
    public function saveGradeChangePolicy(string $mode, int $revision): array
    {
        $actor = $this->access->requirePermission("sis:gradebook:policy");
        return $this->transactions->run(function () use ($mode, $revision, $actor): array {
            $this->changes->lockPolicy();
            $this->actor($actor);
            $this->access->requirePermission("sis:gradebook:policy");
            $this->changes->savePolicy($mode, $revision);
            $this->audit->record(
                $actor,
                "academic.grade_change_policy_updated",
                new EntityId(\Domain\AcademicRecord\Repository\GradeChangeRepository::POLICY_ID),
            );
            $this->actor($actor);
            $this->access->requirePermission("sis:gradebook:policy");
            return $this->changes->policy();
        });
    }
    public function gradeChangePage(?EntityId $after = null): array
    {
        $actor = $this->gradeChangeAuthority();
        $rows = $this->changes->page($after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                "id" => $row["id"],
                "student_id" => $row["student_id"],
                "institutional_id" => $row["institutional_id"],
                "course_code" => $row["course_code"],
                "course_title" => $row["course_title"],
                "before" => $row["details"]["before_grade"]["code"] ?? "Unknown definition",
                "proposed" => $row["details"]["grade"]["code"],
                "credit_unit_correction" => $row["details"]["credit_unit_correction"] ?? null,
            ];
        }
        $this->actor($actor);
        $this->gradeChangeAuthority();
        return ["items" => $items, "next_after" => $more ? $rows[49]["id"] : null];
    }
    public function gradeChangeRequest(EntityId $id): array
    {
        $actor = $this->gradeChangeAuthority();
        [$root, $row] = $this->gradeChangeRecord($id);
        $request = $row["details"];
        $stale =
        $row["status"] === "pending" &&
        ($root->decision() !== $request["previous_decision_id"] ||
        $root->credit()["version"] !== $request["credit_version"]);
        $canReview = false;
        try {
            if ($this->access->requirePermission("sis:gradebook:approve")->value !== $actor->value) {
                throw new AccessDeniedException("The grade-review actor changed.");
            }
            $canReview = $request["actor_id"] !== $actor->value;
        } catch (AccessDeniedException) {
        }
        $withdrawn = $root->gradeChange($id->value)["withdrawn"];
        $reconciliation = $root->gradeChange($id->value)["reconcile_quantities"];
        $unitCorrection = $root->gradeChange($id->value)["credit_unit_correction"];
        $canApprove = $canReview;
        if ($withdrawn) {
            try {
                $this->access->requirePermission("sis:gradebook:closed");
            } catch (AccessDeniedException) {
                $canApprove = false;
            }
        }
        if ($unitCorrection !== null) {
            try {
                $this->access->requirePermission(CreditUnitCorrection::PERMISSION);
            } catch (AccessDeniedException) {
                $canApprove = false;
            }
        }
        $this->audit->record($actor, "academic.grade_change_viewed", $id);
        $this->actor($actor);
        $this->gradeChangeAuthority();
        return [
            "id" => $id->value,
            "student_id" => $row["student_id"],
            "section_id" => $row["section_id"],
            "institutional_id" => $row["institutional_id"],
            "course_code" => $row["course_code"],
            "course_title" => $row["course_title"],
            "status" => $row["status"],
            "before" => $request["before_grade"],
            "grade" => $request["grade"],
            "reason" => $request["reason"],
            "effective_at" => $request["effective_at"],
            "stale" => $stale,
            "withdrawal_grade_correction" => $withdrawn,
            "missing_withdrawal_grade" => $withdrawn && $request["before_grade"] === null,
            "withdrawal_quantity_reconciliation" => $reconciliation,
            "withdrawal_quantities" => $reconciliation ? $root->gradeChange($id->value)["quantities"] : null,
            "credit_unit_correction" => $unitCorrection,
            "credit_unit_summary" => $root->gradeChange($id->value)["credit_unit_summary"],
            "can_approve" => $row["status"] === "pending" && $canApprove && !$stale,
            "can_reject" => $row["status"] === "pending" && $canReview,
            "can_cancel" => $row["status"] === "pending" && $request["actor_id"] === $actor->value,
        ];
    }
    public function reviewGradeChange(
        EntityId $id,
        string $outcome,
        #[\SensitiveParameter] string $reason,
    ): array {
        $this->gradeChangeAuthority();
        $initial = $this->changes->request($id);
        return $this->transactions->run(function () use ($id, $outcome, $reason, $initial): array {
            $student = new EntityId($initial["student_id"]);
            $section = new EntityId($initial["section_id"]);
            $this->records->lockStudent($student);
            $this->records->lockSection($section);
            [$root, $row] = $this->gradeChangeRecord($id);
            $actor = $this->resolutionAuthority(
                $row,
                $outcome,
                $root->gradeChange($id->value)["withdrawn"],
                $root->gradeChange($id->value)["credit_unit_correction"] !== null,
            );
            if ($row["status"] !== "pending") {
                throw new ConcurrentModificationException("This grade correction has already been resolved.");
            }
            $reason = trim($reason);
            if (
                $reason === "" ||
                mb_strlen($reason) > 1000 ||
                !mb_check_encoding($reason, "UTF-8") ||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
            ) {
                self::invalid("reason", "Enter a decision reason of at most 1,000 characters.");
            }
            $this->assertApprovalReady($root, $row, $outcome);
            $request = $row["details"];
            $data = [
                "kind" => "academic-grade-change-resolution",
                "request_id" => $id->value,
                "credit_id" => $row["credit_id"],
                "student_id" => $row["student_id"],
                "section_id" => $row["section_id"],
                "enrollment_id" => $row["enrollment_id"],
                "operation_id" => EntityId::generate()->value,
                "actor_id" => $actor->value,
                "outcome" => $outcome,
                "reason" => $reason,
                "expected_version" => $root->playhead(),
                "request_version" => $row["version"],
                "previous_decision_id" => $root->decision(),
                "grade" => $outcome === "approved" ? $request["grade"] : null,
                "assignment_id" => $outcome === "approved" ? $request["assignment_id"] : null,
                "effective_at" =>
                    $outcome === "approved"
                        ? $request["effective_at"]
                        : $this->clock->now()->format(DATE_ATOM),
                "expires_at" => $this->clock->now()->getTimestamp() + 1200,
            ];
            $this->actor($actor);
            $this->resolutionAuthority(
                $row,
                $outcome,
                $root->gradeChange($id->value)["withdrawn"],
                $root->gradeChange($id->value)["credit_unit_correction"] !== null,
            );
            return [
                "outcome" => $outcome,
                "reason" => $reason,
                "before" => $request["before_grade"],
                "grade" => $request["grade"],
                "review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
            ];
        });
    }
    public function resolveGradeChange(EntityId $id, #[\SensitiveParameter] string $token): array
    {
        $this->gradeChangeAuthority();
        try {
            if (strlen($token) > 65536) {
                throw new InvalidArgumentException();
            }
            $d = json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($d) ||
                ($d["kind"] ?? null) !== "academic-grade-change-resolution" ||
                ($d["request_id"] ?? null) !== $id->value
            ) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable) {
            self::invalid("review_token", "Review this grade correction again.");
        }
        return $this->transactions->run(function () use ($id, $d): array {
            $student = new EntityId($d["student_id"]);
            $section = new EntityId($d["section_id"]);
            $this->records->lockStudent($student);
            $this->records->lockSection($section);
            [$root, $row] = $this->gradeChangeRecord($id);
            $actor = $this->resolutionAuthority(
                $row,
                $d["outcome"],
                $root->gradeChange($id->value)["withdrawn"],
                $root->gradeChange($id->value)["credit_unit_correction"] !== null,
            );
            if (
                $actor->value !== $d["actor_id"] ||
                $row["credit_id"] !== $d["credit_id"] ||
                $row["student_id"] !== $d["student_id"] ||
                $row["section_id"] !== $d["section_id"] ||
                $row["enrollment_id"] !== $d["enrollment_id"]
            ) {
                throw new AccessDeniedException(
                    "This review belongs to another academic records officer or record.",
                );
            }
            $event = \Domain\AcademicRecord\Event\GradeChangeWasResolved::occur(
                new AcademicCreditId($d["credit_id"]),
                [
                    "decision_id" => $d["operation_id"],
                    "previous_decision_id" => $d["previous_decision_id"],
                    "actor_id" => $actor->value,
                    "request_id" => $id->value,
                    "outcome" => $d["outcome"],
                    "grade" => $d["grade"],
                    "assignment_id" => $d["assignment_id"],
                ],
                ["__aggregate_type" => "academic_credit"],
            )->withPlayhead($d["expected_version"] + 1);
            $receipt = $this->history->append(
                $this->stream(new EntityId($d["credit_id"])),
                new EntityId($d["operation_id"]),
                $d["expected_version"],
                new EventContext(
                    $actor,
                    new DateTimeImmutable($d["effective_at"]),
                    new EntityId($d["operation_id"]),
                    $id,
                    $d["reason"],
                ),
                [$event],
            );
            if ($receipt["event_ids"] === [(string) $event->eventId()]) {
                if (
                    !is_int($d["expires_at"] ?? null) ||
                    $d["expires_at"] <= $this->clock->now()->getTimestamp()
                ) {
                    throw new ConcurrentModificationException(
                        "This review expired. Review the grade correction again.",
                    );
                }
                if ($row["status"] !== "pending" || $row["version"] !== $d["request_version"]) {
                    throw new ConcurrentModificationException(
                        "This grade correction changed. Review it again.",
                    );
                }
                $this->assertApprovalReady($root, $row, $d["outcome"]);
                $before = $root->credit();
                $root->acceptHistorical($event);
                if ($d["outcome"] === "approved") {
                    $this->records->project($root->credit(), $section, $before["version"]);
                }
                $this->projectGradeChange(
                    $root,
                    $id,
                    $student,
                    $section,
                    new EntityId($row["enrollment_id"]),
                    $row["version"],
                );
                $this->audit->record($actor, "academic.grade_change_" . $d["outcome"], $id);
                if ($d["expires_at"] <= $this->clock->now()->getTimestamp()) {
                    throw new ConcurrentModificationException(
                        "This review expired. Review the grade correction again.",
                    );
                }
                $this->verifyGradeChange($root, $this->changes->request($id));
                if (
                    !$this->same($root->credit(), $this->records->projection(new EntityId($d["credit_id"])))
                ) {
                    throw new CorruptHistoryException();
                }
            }
            $this->actor($actor);
            $this->resolutionAuthority(
                $row,
                $d["outcome"],
                $root->gradeChange($id->value)["withdrawn"],
                $root->gradeChange($id->value)["credit_unit_correction"] !== null,
            );
            return $receipt;
        });
    }
    private function gradeChangeAuthority(): EntityId
    {
        $actor = $this->access->requirePermission("sis:gradebook:amend");
        if ($this->access->requirePermission("sis:student:read")->value !== $actor->value) {
            throw new AccessDeniedException("The grade-change actor changed.");
        }
        return $actor;
    }
    private function resolutionAuthority(
        array $row,
        string $outcome,
        bool $withdrawn,
        bool $units = false,
    ): EntityId {
        $actor = $this->gradeChangeAuthority();
        if ($outcome === "approved" && $withdrawn) {
            $this->access->requirePermission("sis:gradebook:closed");
        }
        if ($outcome === "approved" && $units) {
            if ($this->access->requirePermission(CreditUnitCorrection::PERMISSION)->value !== $actor->value) {
                throw new AccessDeniedException("The academic decision actor changed.");
            }
        }
        if (!in_array($outcome, ["approved", "rejected", "cancelled"], true)) {
            self::invalid("outcome", "Choose approve, reject or cancel.");
        }
        if ($outcome === "cancelled") {
            if ($row["details"]["actor_id"] !== $actor->value) {
                throw new AccessDeniedException("Only the requesting officer can cancel this request.");
            }
        } else {
            if ($this->access->requirePermission("sis:gradebook:approve")->value !== $actor->value) {
                throw new AccessDeniedException("The grade-review actor changed.");
            }
            if ($row["details"]["actor_id"] === $actor->value) {
                throw new AccessDeniedException("Another authorized registrar must review your request.");
            }
        }
        return $actor;
    }
    private function assertApprovalReady(AcademicCredit $root, array $row, string $outcome): void
    {
        if ($outcome !== "approved") {
            return;
        }
        $request = $row["details"];
        $facts = $root->credit();
        if (
            $root->decision() !== $request["previous_decision_id"] ||
            $facts["version"] !== $request["credit_version"]
        ) {
            throw new ConcurrentModificationException(
                "The academic record changed after this request. Reject or cancel it and request a fresh correction.",
            );
        }
        $student = new EntityId($row["student_id"]);
        $this->gradeEnrollmentScope(
            new EntityId($row["enrollment_id"]),
            $student,
            new EntityId($row["section_id"]),
            new EntityId($root->registrationTermId() ?? $facts["term_id"]),
            $root->gradeChange($row["id"])["withdrawn"],
        );
        $available = $this->gradeChoices(
            $root,
            $facts,
            $student,
            $request["assignment_id"] === null ? null : new EntityId($request["assignment_id"]),
        );
        foreach ($available as $grade) {
            if (
                $grade["id"] === $request["grade"]["id"] &&
                $this->same(GradeSnapshot::fromRow($grade)->facts, $request["grade"])
            ) {
                return;
            }
        }
        throw new ConcurrentModificationException(
            "The retained grading context changed. Request a fresh correction.",
        );
    }
    private function gradeChangeRecord(EntityId $id): array
    {
        $row = $this->changes->request($id);
        $root = $this->load(new EntityId($row["credit_id"]));
        $this->verifyGradeChange($root, $row);
        return [$root, $row];
    }
    private function verifyGradeChange(AcademicCredit $root, array $row): void
    {
        $expected = $root->gradeChange($row["id"]);
        $facts = $root->credit();
        if (
            $expected === null ||
            !$this->same($expected["details"], $row["details"]) ||
            $expected["status"] !== $row["status"] ||
            $expected["version"] !== $row["version"] ||
            $row["credit_id"] !== $facts["id"] ||
            $row["student_id"] !== $facts["student_id"] ||
            $row["section_id"] !== $root->section() ||
            $row["enrollment_id"] !== $facts["source_id"] ||
            $row["actor_id"] !== $expected["details"]["actor_id"]
        ) {
            throw new CorruptHistoryException();
        }
    }
    private function projectGradeChange(
        AcademicCredit $root,
        EntityId $id,
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        ?int $expected,
    ): void {
        $request = $root->gradeChange($id->value) ?? throw new CorruptHistoryException();
        $this->changes->project(
            [
                "id" => $id->value,
                "credit_id" => (string) $root->aggregateId(),
                "student_id" => $student->value,
                "section_id" => $section->value,
                "enrollment_id" => $enrollment->value,
                "actor_id" => $request["details"]["actor_id"],
                "status" => $request["status"],
                "version" => $request["version"],
                "details" => $request["details"],
            ],
            $expected,
        );
    }

    /** Internal drop command; caller owns registration authorization, effective-date validation and billing.
     * @param array<string,mixed> $outcome
     */
    public function drop(
        Enrollment $closed,
        array $outcome,
        EventContext $context,
        ?HistoryReadBudget $budget = null
    ): void {
        if ($context->actor === null || $context->correlation === null) {
            throw new InvalidArgumentException("A trusted drop context is required.");
        }
        $this->actor($context->actor);
        [$id, $root, $facts] = $this->source($closed->id, $closed->studentId, $closed->sectionId, $budget);
        $op = $this->operation($id, $context->correlation, "drop");
        $base = null;
        if ($root->playhead() === 0) {
            $baseOp = $this->operation($id, $context->correlation, "observation");
            $base = $this->baseline(
                $id,
                $baseOp,
                $closed->sectionId,
                $facts,
                $facts["grade_id"] === null
                    ? null
                    : GradeSnapshot::fromRow($this->grades->snapshot(new EntityId($facts["grade_id"])))
                        ->facts,
            );
            $this->history->append(
                $this->stream($id),
                $baseOp,
                0,
                new EventContext(
                    $context->actor,
                    $this->clock->now(),
                    $context->correlation,
                    reason: "Existing academic facts observed before the reviewed drop; " .
                        "earlier actors and times are unknown.",
                ),
                [$base],
            );
            $root->acceptHistorical($base);
        }
        $grade =
        $outcome["grade_id"] === null
        ? null
        : GradeSnapshot::fromRow($this->grades->snapshot(new EntityId($outcome["grade_id"])))->facts;
        $o = array_intersect_key(
            $outcome,
            array_flip([
                "delete_records",
                "grade_code",
                "grade_id",
                "points_milli",
                "counts_gpa",
                "policy_version",
                "term_code",
                "grace_ends_on",
            ]),
        );
        $event = InstitutionalCreditWasDropped::occur(
            new AcademicCreditId($id->value),
            [
                "decision_id" => $op->value,
                "previous_decision_id" => $root->decision(),
                "outcome" => $o,
                "grade" => $grade,
            ],
            ["__aggregate_type" => "academic_credit"] +
                (isset($outcome["policy_reference"]) ? ["drop_policy" => $outcome["policy_reference"]] : []),
        )->withPlayhead($root->playhead() + 1);
        $this->history->append($this->stream($id), $op, $root->playhead(), $context, [$event]);
        $root->acceptHistorical($event);
        $this->records->project(
            $root->credit() ?? throw new CorruptHistoryException(),
            $closed->sectionId,
            $facts["version"],
        );
    }
    /** Internal exact recorded withdrawal. This never observes an earlier untracked row. */
    public function reinstatementBasis(Enrollment $enrollment, HistoryReadBudget $budget): array
    {
        [$id, $root, $facts] = $this->source(
            $enrollment->id,
            $enrollment->studentId,
            $enrollment->sectionId,
            $budget,
        );
        if (!$root->canReinstate() || $facts["status"] !== "W") {
            throw new ValidationException([
                [
                    "field" => "enrollment",
                    "message" =>
                        "Only an unchanged tracked withdrawal can be reinstated. " .
                        "Resolve later academic changes and pending grade requests separately.",
                ],
            ]);
        }
        $pursuit = $this->pursuits->load($id, $budget)->projection();
        if ($pursuit === null || $pursuit["owner_assignment_id"] === null) {
            throw new ValidationException([
                [
                    "field" => "enrollment",
                    "message" =>
                        "The original academic pursuit must be verified before reinstatement.",
                ],
            ]);
        }
        return [
            "credit_id" => $id->value,
            "version" => $facts["version"],
            "history_version" => $root->playhead(),
            "assignment_id" => $pursuit["owner_assignment_id"],
            "before" => $facts,
            "restored" => $root->reinstatementBase(),
        ];
    }
    public function reinstate(
        Enrollment $enrollment,
        array $basis,
        EntityId $operation,
        EventContext $context,
        HistoryReadBudget $budget,
    ): array {
        $this->actor($context->actor ?? throw new CorruptHistoryException());
        $current = $this->reinstatementBasis($enrollment, $budget);
        if ($current !== $basis) {
            throw new ConcurrentModificationException("The withdrawn academic source changed.");
        }
        $id = new EntityId($basis["credit_id"]);
        $root = $this->load($id, $budget);
        $decision = \Domain\Shared\ValueObject\DerivedIdentity::from(
            $operation,
            "reinstatement.credit",
        );
        $event = \Domain\AcademicRecord\Event\InstitutionalCreditWasReinstated::occur(
            new AcademicCreditId($id->value),
            [
                "decision_id" => $decision->value,
                "operation_id" => $operation->value,
                "previous_decision_id" => $root->decision(),
                "enrollment_id" => $enrollment->id->value,
            ],
            ["__aggregate_type" => "academic_credit"],
        )->withPlayhead($root->playhead() + 1);
        $this->history->append($this->stream($id), $decision, $root->playhead(), $context, [
            $event,
        ]);
        $root->acceptHistorical($event);
        $this->records->project($root->credit(), $enrollment->sectionId, $basis["version"]);
        return [
            "credit_id" => $id->value,
            "version" => $root->credit()["version"],
            "assignment_id" => $basis["assignment_id"],
        ];
    }
    public function verifyReinstatementCompletion(
        Enrollment $enrollment,
        array $basis,
        EntityId $operation,
        HistoryReadBudget $budget,
    ): void {
        [$id, $root, $facts] = $this->source(
            $enrollment->id,
            $enrollment->studentId,
            $enrollment->sectionId,
            $budget,
        );
        $expected = array_replace($basis["restored"], ["version" => $basis["version"] + 1]);
        if (
            !$this->same($facts, $expected) ||
            $root->reinstatement($operation) === null ||
            $id->value !== $basis["credit_id"]
        ) {
            throw new ConcurrentModificationException(
                "The reinstated academic facts changed before completion.",
            );
        }
    }
    public function verifyReinstatementReceipt(
        EntityId $credit,
        EntityId $enrollment,
        EntityId $operation,
        int $version,
        HistoryReadBudget $budget,
    ): void {
        $root = $this->load($credit, $budget);
        $receipt = $root->reinstatement($operation);
        if (
            $receipt !== [
                "credit_id" => $credit->value,
                "enrollment_id" => $enrollment->value,
                "version" => $version,
            ]
        ) {
            throw new CorruptHistoryException();
        }
    }
    /** Internal policy read, after the drop workflow authorizes the student.
     * @return array<string,mixed>|null
     */
    public function dropGrade(
        EntityId $enrollment,
        EntityId $student,
        EntityId $section,
        string $code,
        ?HistoryReadBudget $budget = null,
    ): ?array {
        [$id, $root, $facts] = $this->source($enrollment, $student, $section, $budget);
        foreach ($this->gradeChoices($root, $facts, $student, null) as $row) {
            if ($row["code"] === $code) {
                return $row;
            }
        }
        return null;
    }
    /**
     * Internal audit input, after the caller authorizes and locks the student.
     * Deleted early-drop projections are deliberately unavailable here.
     * @return array<string,mixed>
     */
    public function auditFacts(EntityId $credit, EntityId $student, ?HistoryReadBudget $budget = null): array
    {
        $facts =
        $this->records->projection($credit) ??
        throw new RecordNotFoundException("Current academic record not found.");
        if ($facts["student_id"] !== $student->value || $facts["credit_type"] !== "institutional") {
            throw new AccessDeniedException("The academic record belongs to another scope.");
        }
        $root = $this->load($credit, $budget);
        return [
            "credit" => $root->credit() ?? $facts,
            "grade" => $root->grade(),
            "history_recorded" => $root->playhead() > 0,
            "earlier_history_unknown" =>
                $root->playhead() === 0 || $root->provenance() === "observed_existing",
            "history_version" => $root->playhead(),
            "section_version_id" => $root->sectionVersion(),
            "completed_on" => $root->completedOn(),
            "academic_on" => $root->academicOn(),
            "retained_term_code" => $root->postingTerm()["term_code"] ?? null,
            "registration_term_id" => $root->registrationTermId() ?? $facts["term_id"],
            "registration_course_id" => $root->registrationCourseId() ?? $facts["course_id"],
            "registration_course_version_id" => $root->registrationCourseVersion(),
        ];
    }
    /** Internal verification only; the caller authorizes and locks the student.
     * Unlike the paged work read, this also verifies a retained early-removal reference.
     * @return array<string,mixed>
     */
    public function evaluationFacts(EntityId $credit, EntityId $student, HistoryReadBudget $budget): array
    {
        $root = $this->load($credit, $budget);
        $facts =
        $root->credit() ?? ($this->records->projection($credit) ?? throw new CorruptHistoryException());
        if ($facts["student_id"] !== $student->value || $facts["credit_type"] !== "institutional") {
            throw new CorruptHistoryException();
        }
        return [
            "credit" => $facts,
            "grade" => $root->grade(),
            "history_recorded" => $root->playhead() > 0,
            "earlier_history_unknown" =>
                $root->playhead() === 0 || $root->provenance() === "observed_existing",
            "history_version" => $root->playhead(),
            "section_version_id" => $root->sectionVersion(),
            "completed_on" => $root->completedOn(),
            "academic_on" => $root->academicOn(),
            "retained_term_code" => $root->postingTerm()["term_code"] ?? null,
            "registration_term_id" => $root->registrationTermId() ?? $facts["term_id"],
            "registration_course_id" => $root->registrationCourseId() ?? $facts["course_id"],
            "registration_course_version_id" => $root->registrationCourseVersion(),
        ];
    }
    /**
     * Current load uses genuinely recorded registration quantities, not corrected award units or today's catalog.
     * Caller authorizes the student and owns its read transaction/lock.
     */
    public function registrationLoadFacts(Enrollment $enrollment, HistoryReadBudget $budget): array
    {
        [$id, $root, $facts] = $this->source(
            $enrollment->id,
            $enrollment->studentId,
            $enrollment->sectionId,
            $budget,
        );
        if (
            $facts["status"] !== $enrollment->status->value ||
            ($root->registrationTermId() ?? $facts["term_id"]) !== $enrollment->termId->value
        ) {
            throw new CorruptHistoryException();
        }
        $registered = $root->registeredCredits();
        return [
            "credit_id" => $id->value,
            "credits_milli" => $registered["attempted_milli"] ?? null,
            "course_code" => $registered["course_code"] ?? $facts["course_code"],
            "course_title" => $registered["course_title"] ?? $facts["course_title"],
            "history_version" => $root->playhead(),
        ];
    }

    /** Internal certification evidence; caller authorizes and owns student/section locks. */
    public function certificationFacts(Enrollment $enrollment, HistoryReadBudget $budget): array
    {
        [, $root, $facts] = $this->source(
            $enrollment->id,
            $enrollment->studentId,
            $enrollment->sectionId,
            $budget,
        );
        if (
            $facts["status"] !== $enrollment->status->value ||
            ($root->registrationTermId() ?? $facts["term_id"]) !== $enrollment->termId->value
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "credit" => $facts,
            "grade" => $root->grade(),
            "history_version" => $root->playhead(),
            "pending" => $root->pendingGradeChange() !== null,
            "section_version_id" => $root->sectionVersion(),
            "earlier_history_unknown" =>
                $root->playhead() === 0 || $root->provenance() === "observed_existing",
        ];
    }

    /** Internal registration completion check; caller owns authorization and student locks. */
    public function verifyOpenEnrollment(Enrollment $enrollment, HistoryReadBudget $budget): void
    {
        $row = $this->records->forEnrollment($enrollment->id) ?? throw new CorruptHistoryException();
        $facts = $this->auditFacts(new EntityId($row["id"]), $enrollment->studentId, $budget)["credit"];
        if (
            $facts["source_id"] !== $enrollment->id->value ||
            $facts["status"] !== $enrollment->status->value ||
            $facts["grade_id"] !== null ||
            $facts["completed_milli"] !== 0 ||
            $facts["grade_points_milli"] !== 0
        ) {
            throw new CorruptHistoryException();
        }
    }

    /**
     * Caller authorizes owner/assigned faculty/academic reader before requesting curated history.
     * @return array<string,mixed>
     */
    public function history(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        ?string $cursor = null,
    ): array {
        [$id, $root, $facts] = $this->source($enrollment, $student, $section);
        if ($root->playhead() === 0) {
            return ["items" => [], "next_cursor" => null, "earlier_history_unknown" => true];
        }
        $page = $this->history->page($this->stream($id), $cursor, 10);
        $items = [];
        foreach ($page->events as $stored) {
            $e = $stored->event;
            $p = $e->payload();
            $unitCorrection =
            $e instanceof FinalGradeWasDecided
            ? $e->metaParam("credit_unit_correction", null)
            : ($e instanceof \Domain\AcademicRecord\Event\GradeChangeWasResolved &&
                    $p["outcome"] === "approved"
                        ? $root->gradeChange($p["request_id"])["credit_unit_correction"]
                        : null);
            $reconciled =
            ($e instanceof FinalGradeWasDecided &&
            $e->metaParam("withdrawal_quantity_reconciliation", false)) ||
            ($e instanceof \Domain\AcademicRecord\Event\GradeChangeWasResolved &&
            $p["outcome"] === "approved" &&
            $root->gradeChange($p["request_id"])["reconcile_quantities"]);
            $items[] = [
                "withdrawal_quantity_reconciled" => $reconciled,
                "credit_unit_correction" => $unitCorrection,
                ...$e instanceof FinalGradeWasDecided && $e->metaParam("coursework", null) !== null
                    ? [
                        "coursework_summary" => array_intersect_key(
                            new CourseworkGradeEvidence($e->metaParam("coursework"))->summary(),
                            array_flip(["score_display", "mode", "grading_display", "grade_policy"]),
                        ),
                    ]
                    : [],
                "version" => $e->playhead(),
                "action" => match (true) {
                    $e instanceof InstitutionalCreditWasEstablished => $p["provenance"],
                    $e instanceof \Domain\AcademicRecord\Event\AcademicCompletionWasRecorded
                        => "completion_recorded",
                    $e instanceof \Domain\AcademicRecord\Event\InstitutionalAcademicPeriodWasRecorded
                        => "period_recorded",
                    $e instanceof \Domain\AcademicRecord\Event\InstitutionalCourseWasAccepted
                        => "course_accepted",
                    $e instanceof \Domain\AcademicRecord\Event\InstitutionalCourseWasCorrected
                        => "course_corrected",
                    $e instanceof \Domain\AcademicRecord\Event\InstitutionalPostingTermWasCorrected
                        => "posting_term_corrected",
                    $e instanceof \Domain\AcademicRecord\Event\RegisteredCreditsWereCorrected
                        => 'registered_credits_corrected',
                    $e instanceof FinalGradeWasDecided => "grade_decided",
                    $e instanceof \Domain\AcademicRecord\Event\GradeChangeWasRequested
                        => "correction_requested",
                    $e instanceof \Domain\AcademicRecord\Event\GradeChangeWasResolved => "correction_" .
                        $p["outcome"],
                    $e instanceof InstitutionalCreditWasDropped => "dropped",
                    $e instanceof \Domain\AcademicRecord\Event\InstitutionalCreditWasReinstated => "reinstated",
                    default => throw new CorruptHistoryException(),
                },
                ...$e instanceof \Domain\AcademicRecord\Event\RegisteredCreditsWereCorrected
                    ? ['registered_credit_correction' => [
                        'previous_registered_milli' => $p['previous_registered_milli'],
                        'registered_milli' => $p['registered_milli'],
                    ]] : [],
                "academic_on" => $p["academic_on"] ?? null,
                "previous_academic_on" => $p["previous_academic_on"] ?? null,
                "posting_term_code" => $p["term_code"] ?? null,
                "previous_posting_term_code" => $p["previous_term_code"] ?? null,
                "course_code" => $p["course_code"] ?? null,
                "course_title" => $p["course_title"] ?? null,
                "completed_on" => $p["completed_on"] ?? null,
                "previous_completed_on" => $p["previous_completed_on"] ?? null,
                "grade_code" =>
                    $e instanceof \Domain\AcademicRecord\Event\GradeChangeWasRequested
                        ? null
                        : $p["grade"]["code"] ??
                            (is_array($p["outcome"] ?? null) ? $p["outcome"]["grade_code"] : null),
                "grade_points_milli" =>
                    $e instanceof \Domain\AcademicRecord\Event\GradeChangeWasRequested
                        ? null
                        : $p["grade"]["points_milli"] ?? null,
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                "effective_at" => $stored->context->describe()["effective_at"],
            ];
        }
        return [
            "items" => $items,
            "next_cursor" => $page->nextCursor,
            "earlier_history_unknown" => $root->provenance() === "observed_existing",
        ];
    }
    /** @return array{EntityId,AcademicCredit,array<string,mixed>} */
    private function source(
        EntityId $enrollment,
        EntityId $student,
        EntityId $section,
        ?HistoryReadBudget $budget = null,
        bool $allowRemoved = false,
    ): array {
        $m =
        $this->records->forEnrollment($enrollment) ??
        throw new RecordNotFoundException("Academic record not found.");
        if ($m["student_id"] !== $student->value || $m["section_id"] !== $section->value) {
            throw new AccessDeniedException("The academic record belongs to another scope.");
        }
        $id = new EntityId($m["id"]);
        $root = $this->load($id, $budget);
        $facts =
        $this->records->projection($id) ??
        (($allowRemoved && ($root->credit()["status"] ?? null) === "D" ? $root->credit() : null) ??
        throw new RecordNotFoundException("Current academic record not found."));
        return [$id, $root, $facts];
    }
    /**
     * Internal command context. Caller must authorize and hold student/section locks.
     * @return array{EntityId,AcademicCredit,array<string,mixed>}
     */
    public function decisionRecord(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        bool $allowRemoved = false,
        ?HistoryReadBudget $budget = null,
    ): array {
        return $this->source($enrollment, $student, $section, $budget ?? new HistoryReadBudget(), $allowRemoved);
    }

    /**
     * @throws TypeException
     */
    private function load(EntityId $id, ?HistoryReadBudget $budget = null): AcademicCredit
    {
        $budget ??= new HistoryReadBudget();
        $root = AcademicCredit::root(new AcademicCreditId($id->value));
        $cursor = null;
        do {
            $page = $budget->page($this->history, $this->stream($id), $cursor);
            foreach ($page->events as $e) {
                $root->acceptHistorical($e->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $m = $this->records->mapping($id);
        $c = $root->credit();
        if ($c === null) {
            if ($m !== null || $this->records->postingTerm($id) !== null) {
                throw new CorruptHistoryException();
            }
            return $root;
        }
        if (
            $m === null ||
            $m["enrollment_id"] !== $c["source_id"] ||
            $m["student_id"] !== $c["student_id"] ||
            $m["section_id"] !== $root->section()
        ) {
            throw new CorruptHistoryException();
        }
        if (
            $this->versions->enrollmentVersion(new EntityId($c["source_id"]))?->value !==
            $root->sectionVersion()
        ) {
            throw new CorruptHistoryException();
        }
        $posting = $this->records->postingTerm($id);
        if (
            ($c["status"] === "D" && $posting !== null) ||
            ($c["status"] !== "D" && !$this->same($posting, $root->postingTerm()))
        ) {
            throw new CorruptHistoryException();
        }
        $p = $this->records->projection($id);
        if ($c["status"] === "D" ? $p !== null : !$this->same($p, $c)) {
            throw new CorruptHistoryException();
        }
        $pending = $this->changes->pending($id);
        $expected = $root->pendingGradeChange();
        if (count($pending) !== ($expected === null ? 0 : 1)) {
            throw new CorruptHistoryException();
        }
        if ($expected !== null) {
            $this->verifyGradeChange($root, $pending[0]);
        }
        return $root;
    }
    /**
     * @param array<string,mixed> $facts
     * @return list<array<string,mixed>>
     */
    private function gradeChoices(
        AcademicCredit $root,
        array $facts,
        EntityId $student,
        ?EntityId $assignment,
    ): array {
        return $this->grades->boundChoices(
            $student,
            $root->policies(),
            $facts["grade_id"] === null ? null : new EntityId($facts["grade_id"]),
            $assignment,
        );
    }

    /**
     * @param array<string,mixed> $facts
     * @param array<string,mixed>|null $grade
     * @throws TypeException
     */
    private function baseline(
        EntityId $id,
        EntityId $op,
        EntityId $section,
        array $facts,
        ?array $grade,
    ): \Codefy\Domain\EventSourcing\AggregateChanged {
        return InstitutionalCreditWasEstablished::occur(
            new AcademicCreditId($id->value),
            [
                "decision_id" => $op->value,
                "provenance" => "observed_existing",
                "credit" => $facts,
                "section_id" => $section->value,
                "policies" => [],
                "grade" => $grade,
            ],
            ["__aggregate_type" => "academic_credit"],
        )->withPlayhead(1);
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new AcademicCreditId($id->value), "academic_credit");
    }
    private function operation(EntityId $credit, EntityId $correlation, string $kind): EntityId
    {
        return PublishedGradeId::forVersion($correlation, "academic." . $kind . "." . $credit->value);
    }
    /** @param array<string,mixed> $facts
     * @return array<string,mixed>|null
     */
    private function observedGrade(AcademicCredit $root, array $facts): ?array
    {
        return $root->grade() ??
        ($facts["grade_id"] === null
        ? null
        : GradeSnapshot::fromRow($this->grades->snapshot(new EntityId($facts["grade_id"])))->facts);
    }
    private function gradeEnrollmentScope(
        EntityId $enrollment,
        EntityId $student,
        EntityId $section,
        EntityId $term,
        bool $withdrawn = false,
    ): void {
        if ($withdrawn) {
            $this->authority($section, true, true);
        }
        $r = $this->records->enrollment($enrollment);
        if (
            $r === null ||
            $r["student_id"] !== $student->value ||
            $r["section_id"] !== $section->value ||
            $r["term_id"] !== $term->value ||
            ($withdrawn ? $r["status"] !== "W" : !in_array($r["status"], ["A", "N"], true))
        ) {
            throw new ConcurrentModificationException(
                $withdrawn
                    ? "The withdrawn enrollment no longer matches this grade review."
                    : "The enrollment is no longer active.",
            );
        }
    }
    private function authority(
        EntityId $section,
        bool $amendment,
        bool $withdrawn = false,
        bool $units = false,
    ): EntityId {
        $unitActor = $units ? $this->access->requirePermission(CreditUnitCorrection::PERMISSION) : null;
        if ($withdrawn) {
            $this->access->requirePermission("sis:gradebook:closed");
        }
        if ($amendment) {
            $actor = $this->access->requirePermission("sis:gradebook:amend");
            if (
                $this->access->requirePermission("sis:student:read")->value !== $actor->value ||
                ($unitActor !== null && $unitActor->value !== $actor->value)
            ) {
                throw new AccessDeniedException("The academic decision actor changed.");
            }
            return $actor;
        }
        $row = $this->records->section($section);
        return $this->access->requireOwnerOrPermission(
            $row["faculty_user_id"] === null ? null : new EntityId($row["faculty_user_id"]),
            "sis:gradebook:manage",
        );
    }
    private function readAuthority(EntityId $section): EntityId
    {
        try {
            return $this->authority($section, false);
        } catch (AccessDeniedException) {
            return $this->authority($section, true);
        }
    }
    private function canAmend(bool $withdrawn = false): bool
    {
        try {
            $this->access->requirePermission("sis:gradebook:amend");
            $this->access->requirePermission("sis:student:read");
            if ($withdrawn) {
                $this->access->requirePermission("sis:gradebook:closed");
            }
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
    private function canCorrectCourse(): bool
    {
        try {
            $this->access->requirePermission(AcademicCourseService::CORRECTION_PERMISSION);
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    private function canCorrectPlacement(): bool
    {
        try {
            $this->access->requirePermission("sis:academic:placement:amend");
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
    private function canCorrectUnits(): bool
    {
        try {
            $this->access->requirePermission(CreditUnitCorrection::PERMISSION);
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
    private function actor(EntityId $actor): void
    {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException("The academic command actor changed.");
        }
    }
    /**
     * @throws DateMalformedStringException
     */
    private function effective(EntityId $enrollment, ?string $date): DateTimeImmutable
    {
        $now = $this->clock->now();
        if ($date === null) {
            $date = $now->format("Y-m-d");
        }
        $when = DateTimeImmutable::createFromFormat("!Y-m-d", $date, $now->getTimezone());
        $row =
        $this->records->enrollment($enrollment) ??
        throw new RecordNotFoundException("Enrollment not found.");
        $registered = new DateTimeImmutable($row["registered_at"], new DateTimeZone("UTC"))
            ->setTimezone($now->getTimezone())
            ->format("Y-m-d");
        if (
            $when === false ||
            $when->format("Y-m-d") !== $date ||
            $date > $now->format("Y-m-d") ||
            $date < $registered
        ) {
            self::invalid("effective_on", "Choose an effective date from registration through today.");
        }
        return $when;
    }
    private function same(mixed $a, mixed $b): bool
    {
        if (!is_array($a) || !is_array($b)) {
            return $a === $b;
        }
        if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) {
            return false;
        }
        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b) || !$this->same($v, $b[$k])) {
                return false;
            }
        }
        return true;
    }
    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
}
