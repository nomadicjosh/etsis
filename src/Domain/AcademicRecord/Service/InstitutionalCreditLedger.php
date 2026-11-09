<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished;
use Domain\AcademicRecord\Event\FinalGradeWasDecided;
use Domain\AcademicRecord\Event\InstitutionalCreditWasDropped;
use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\AcademicRecord\ValueObject\CreditSnapshot;
use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Course;
use Domain\Communications\Service\MessageCipher;
use Domain\Gradebook\Service\AcademicGradeCatalog;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Registration\Enrollment;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
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
use Throwable;

final readonly class InstitutionalCreditLedger
{
    public function __construct(
        private AcademicCreditRepository $records,
        private HistoricalEventStore $history,
        private AcademicGradeCatalog $grades,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** Internal registration command. Caller has already authorized/locked the student and section. */
    public function open(Enrollment $enrollment, Course $course, EntityId $actor): void
    {
        $this->transactions->run(function () use ($enrollment, $course, $actor): void {
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
                ["__aggregate_type" => "academic_credit"],
            )->withPlayhead(1);
            $this->history->append(
                $this->stream($id),
                $id,
                0,
                new EventContext(
                    $actor,
                    $enrollment->registeredAt,
                    $id,
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
        $this->readAuthority($section);
        [$id, $root, $facts] = $this->source($enrollment, $student, $section);
        $this->active($enrollment, $student, $section, new EntityId($facts["term_id"]));
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
        return [
            "items" => $items,
            "pursuits" => $pursuits,
            "needs_assignment" => $needs,
            "effective_on_default" => $this->clock->now()->format("Y-m-d"),
            "current_version" => $facts["version"],
            "can_amend" => $this->canAmend(),
            "history_recorded" => $root->playhead() > 0,
            "posted" => new CreditSnapshot($facts)->posted(),
            "current_grade_code" =>
                $facts["grade_id"] === null
                    ? null
                    : $root->grade()["code"] ??
                        $this->grades->snapshot(new EntityId($facts["grade_id"]))["code"],
        ];
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
        ): array {
            $this->records->lockStudent($student);
            $this->records->lockSection($section);
            [$id, $root, $facts] = $this->source($enrollment, $student, $section);
            $this->active($enrollment, $student, $section, new EntityId($facts["term_id"]));
            if ($facts["version"] !== $version) {
                throw new ConcurrentModificationException(
                    "Reload the academic record before reviewing a grade.",
                );
            }
            $amend = new CreditSnapshot($facts)->posted();
            $actor = $this->authority($section, $amend);
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
            foreach ($this->gradeChoices($root, $facts, $student, $assignment) as $r) {
                if ($r["id"] === $grade->value) {
                    $selected = GradeSnapshot::fromRow($r)->facts;
                }
            }
            if ($selected === null) {
                self::invalid("grade_id", "Choose a grade from this record’s retained grading policy.");
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
                "earlier_history_unknown" => $baseline || $root->provenance() === "observed_existing",
                "observed_grade" =>
                    $root->grade() ??
                    ($facts["grade_id"] === null
                        ? null
                        : GradeSnapshot::fromRow($this->grades->snapshot(new EntityId($facts["grade_id"])))
                            ->facts),
            ];
            return $data + ["review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR))];
        });
    }
    /** @return array{operation_id:string,first_version:int,last_version:int,event_ids:list<string>} */
    public function commit(EntityId $student, EntityId $section, #[\SensitiveParameter] string $token): array
    {
        try {
            if (strlen($token) > 65536) {
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
        $actor = $this->authority($section, $d["amendment"]);
        if ($actor->value !== $d["actor_id"]) {
            throw new AccessDeniedException("This grade review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($student, $section, $d, $actor): array {
            $this->records->lockStudent($student);
            $this->records->lockSection($section);
            $this->actor($actor);
            $this->authority($section, $d["amendment"]);
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
            $event = FinalGradeWasDecided::occur(
                new AcademicCreditId($id->value),
                [
                    "decision_id" => $op->value,
                    "previous_decision_id" => $d["previous_decision_id"],
                    "grade" => $d["grade"],
                    "assignment_id" => $d["assignment_id"],
                ],
                ["__aggregate_type" => "academic_credit"],
            )->withPlayhead($d["expected_version"] + ($d["baseline"] ? 2 : 1));
            $receipt = $this->history->append(
                $this->stream($id),
                $op,
                $event->playhead() - 1,
                new EventContext(
                    $actor,
                    new DateTimeImmutable($d["effective_at"]),
                    $op,
                    new EntityId($d["previous_decision_id"]),
                    $d["reason"],
                ),
                [$event],
            );
            if ($receipt["event_ids"] === [(string) $event->eventId()]) {
                $current = $this->records->projection($id) ?? throw new CorruptHistoryException();
                $this->active(
                    new EntityId($d["enrollment_id"]),
                    $student,
                    $section,
                    new EntityId($d["before"]["term_id"]),
                );
                if (!$this->same($current, $d["before"])) {
                    throw new ConcurrentModificationException(
                        "The academic facts changed. Review the grade again.",
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
                if ($d["baseline"]) {
                    $root->acceptHistorical($base);
                }
                $root->acceptHistorical($event);
                $this->records->project(
                    $root->credit() ?? throw new CorruptHistoryException(),
                    $section,
                    $current["version"],
                );
                $this->audit->record(
                    $actor,
                    $d["amendment"] ? "academic.grade_amended" : "academic.grade_assigned",
                    $id,
                );
            }
            return $receipt;
        });
    }
    /** Internal drop command; caller owns registration authorization, effective-date validation and billing.
     * @param array<string,mixed> $outcome
     */
    public function drop(Enrollment $closed, array $outcome, EventContext $context): void
    {
        if ($context->actor === null || $context->correlation === null) {
            throw new InvalidArgumentException("A trusted drop context is required.");
        }
        $this->actor($context->actor);
        [$id, $root, $facts] = $this->source($closed->id, $closed->studentId, $closed->sectionId);
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
            ["__aggregate_type" => "academic_credit"],
        )->withPlayhead($root->playhead() + 1);
        $this->history->append($this->stream($id), $op, $root->playhead(), $context, [$event]);
        $root->acceptHistorical($event);
        $this->records->project(
            $root->credit() ?? throw new CorruptHistoryException(),
            $closed->sectionId,
            $facts["version"],
        );
    }
    /** Internal policy read, after the drop workflow authorizes the student.
     * @return array<string,mixed>|null
     */
    public function dropGrade(
        EntityId $enrollment,
        EntityId $student,
        EntityId $section,
        string $code,
    ): ?array {
        [$id, $root, $facts] = $this->source($enrollment, $student, $section);
        foreach ($this->gradeChoices($root, $facts, $student, null) as $row) {
            if ($row["code"] === $code) {
                return $row;
            }
        }
        return null;
    }
    /** Caller authorizes owner/assigned faculty/academic reader before requesting curated history.
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
            $items[] = [
                "version" => $e->playhead(),
                "action" =>
                    $e instanceof InstitutionalCreditWasEstablished
                        ? $p["provenance"]
                        : ($e instanceof FinalGradeWasDecided
                            ? "grade_decided"
                            : "dropped"),
                "grade_code" => $p["grade"]["code"] ?? ($p["outcome"]["grade_code"] ?? null),
                "grade_points_milli" => $p["grade"]["points_milli"] ?? null,
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
    private function source(EntityId $enrollment, EntityId $student, EntityId $section): array
    {
        $m =
        $this->records->forEnrollment($enrollment) ??
        throw new RecordNotFoundException("Academic record not found.");
        if ($m["student_id"] !== $student->value || $m["section_id"] !== $section->value) {
            throw new AccessDeniedException("The academic record belongs to another scope.");
        }
        $id = new EntityId($m["id"]);
        $root = $this->load($id);
        $facts =
        $this->records->projection($id) ??
        throw new RecordNotFoundException("Current academic record not found.");
        return [$id, $root, $facts];
    }
    private function load(EntityId $id): AcademicCredit
    {
        $root = AcademicCredit::root(new AcademicCreditId($id->value));
        $cursor = null;
        do {
            $page = $this->history->page($this->stream($id), $cursor, 20);
            foreach ($page->events as $e) {
                $root->acceptHistorical($e->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $m = $this->records->mapping($id);
        $c = $root->credit();
        if ($c === null) {
            if ($m !== null) {
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
        $p = $this->records->projection($id);
        if ($c["status"] === "D" ? $p !== null : !$this->same($p, $c)) {
            throw new CorruptHistoryException();
        }
        return $root;
    }
    /** @param array<string,mixed> $facts
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
    /** @param array<string,mixed> $facts
     * @param array<string,mixed>|null $grade
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
    private function active(EntityId $enrollment, EntityId $student, EntityId $section, EntityId $term): void
    {
        $r = $this->records->enrollment($enrollment);
        if (
            $r === null ||
            $r["student_id"] !== $student->value ||
            $r["section_id"] !== $section->value ||
            $r["term_id"] !== $term->value ||
            !in_array($r["status"], ["A", "N"], true)
        ) {
            throw new ConcurrentModificationException("The enrollment is no longer active.");
        }
    }
    private function authority(EntityId $section, bool $amendment): EntityId
    {
        if ($amendment) {
            $actor = $this->access->requirePermission("sis:gradebook:amend");
            $this->access->requirePermission("sis:student:read");
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
    private function canAmend(): bool
    {
        try {
            $this->access->requirePermission("sis:gradebook:amend");
            $this->access->requirePermission("sis:student:read");
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
