<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Service;

use DateTimeImmutable;
use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\Event\InstitutionalCourseWasAccepted;
use Domain\AcademicRecord\Event\InstitutionalCourseWasCorrected;
use Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished;
use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Service\PublishedCourseReader;
use Domain\Communications\Service\MessageCipher;
use Domain\Gradebook\Service\AcademicGradeCatalog;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/** Reviewed academic course references; preserve original registration and every earlier decision. */
final readonly class AcademicCourseService
{
    public const string CORRECTION_PERMISSION = "sis:academic:course:amend";
    public function __construct(
        private InstitutionalCreditLedger $credits,
        private AcademicCreditRepository $records,
        private PublishedCourseReader $courses,
        private AcademicGradeCatalog $grades,
        private HistoricalEventStore $history,
        private UnitOfWork $transactions,
        private StudentAccess $access,
        private MessageCipher $cipher,
        private Clock $clock,
        private AuditTrail $audit,
        private \Domain\Catalog\Repository\CourseSearchRepository $catalog,
    ) {
    }

    /** Bounded identity search; an exact publication supplies the accepted academic facts.
     * @return array<string,mixed> */
    public function search(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        string $search,
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize(correction: true);
        $search = trim($search);
        if (
            !mb_check_encoding($search, "UTF-8") ||
            mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            self::invalid("search", "Search by course code or title using at most 80 characters.");
        }
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $search,
            $after,
            $actor,
        ): array {
            $this->lock($student, $section, $actor);
            [, $root, $facts] = $this->credits->decisionRecord($student, $section, $enrollment);
            $this->eligible($root, $facts, $enrollment, $section, true);
            $rows = $search === "" ? [] : $this->catalog->page($search, $after);
            $items = array_slice($rows, 0, 20);
            $this->sameActor($actor, $facts["status"] === "W", true);
            return [
                "items" => $items,
                "next_after" => count($rows) > 20 ? $items[19]["id"] : null,
                "search_required" => $search === "",
            ];
        });
    }

    /** @return array<string,mixed> */
    public function choices(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        ?string $cursor = null,
        ?EntityId $course = null,
    ): array {
        $actor = $this->authorize(correction: $course !== null);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $cursor,
            $actor,
            $course,
        ): array {
            $this->lock($student, $section, $actor);
            [, $root, $facts] = $this->credits->decisionRecord($student, $section, $enrollment);
            $this->eligible($root, $facts, $enrollment, $section, $course !== null);
            $page = $this->courses->page($course ?? new EntityId($facts["course_id"]), $cursor);
            $this->sameActor($actor, $facts["status"] === "W", $course !== null);
            return $page + [
                "current_course_version_id" => $facts["course_version_id"],
                "current_course_id" => $facts["course_id"],
                "current_version" => $facts["version"],
                "effective_on_default" => $this->clock->now()->format("Y-m-d"),
                "current_course_code" => $facts["course_code"],
                "current_course_title" => $facts["course_title"],
            ];
        });
    }

    /** @return array<string,mixed> */
    public function review(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        EntityId $courseVersion,
        int $version,
        string $effectiveOn,
        #[\SensitiveParameter] string $reason,
        ?EntityId $course = null,
    ): array {
        $actor = $this->authorize(correction: $course !== null);
        $when = $this->date($effectiveOn);
        $reason = trim($reason);
        if (
            $reason === "" ||
            mb_strlen($reason) > 1000 ||
            !mb_check_encoding($reason, "UTF-8") ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            self::invalid("reason", "Explain the course-publication evidence in at most 1,000 characters.");
        }
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $courseVersion,
            $version,
            $when,
            $reason,
            $actor,
            $course,
        ): array {
            $this->lock($student, $section, $actor);
            [$id, $root, $facts] = $this->credits->decisionRecord($student, $section, $enrollment);
            $this->eligible($root, $facts, $enrollment, $section, $course !== null);
            if ($facts["version"] !== $version) {
                throw new ConcurrentModificationException(
                    "Reload the academic record before reviewing its course publication.",
                );
            }
            if ($facts["course_version_id"] === $courseVersion->value) {
                self::invalid("course_version_id", "Select a different publication.");
            }
            $definition = $this->courses->version(
                $course ?? new EntityId($facts["course_id"]),
                $courseVersion,
                new HistoryReadBudget(),
            );
            $operation = EntityId::generate();
            $baseline = $root->playhead() === 0;
            $observation = PublishedGradeId::forVersion(
                $operation,
                "academic.course.observation." . $id->value,
            )->value;
            $now = $this->clock->now();
            $d = [
                "kind" => "academic-course-review",
                "student_id" => $student->value,
                "section_id" => $section->value,
                "enrollment_id" => $enrollment->value,
                "credit_id" => $id->value,
                "actor_id" => $actor->value,
                "operation_id" => $operation->value,
                "expected_version" => $root->playhead(),
                "credit_version" => $version,
                "baseline" => $baseline,
                "baseline_operation_id" => $observation,
                "observed_at" => $now->format(DATE_ATOM),
                "before" => $facts,
                "observed_grade" =>
                    $root->grade() ??
                    ($facts["grade_id"] === null
                        ? null
                        : GradeSnapshot::fromRow($this->grades->snapshot(new EntityId($facts["grade_id"])))
                            ->facts),
                "previous_decision_id" => $baseline ? $observation : $root->decision(),
                "previous_course_version_id" => $facts["course_version_id"],
                "course_version_id" => $courseVersion->value,
                "course_code" => $definition->definition->code,
                "course_title" =>
                    $definition->definition->text->transcriptTitle ?? $definition->definition->title,
                "effective_at" => $when->format(DATE_ATOM),
                "reason" => $reason,
                "expires_at" => $now->getTimestamp() + 1200,
            ];
            if ($course !== null) {
                $d += [
                    "course_correction" => true,
                    "previous_course_id" => $facts["course_id"],
                    "course_id" => $course->value,
                ];
            }
            $this->sameActor($actor, $facts["status"] === "W", $course !== null);
            return [
                "before" => [
                    "course_code" => $facts["course_code"],
                    "course_title" => $facts["course_title"],
                ],
                "after" => ["course_code" => $d["course_code"], "course_title" => $d["course_title"]],
                "effective_on" => $when->format("Y-m-d"),
                "reason" => $reason,
                "earlier_reference_unknown" => $root->registrationCourseVersion() === null,
                "course_correction" => $course !== null,
                "review_token" => $this->cipher->seal(json_encode($d, JSON_THROW_ON_ERROR)),
            ];
        });
    }

    /** @return array{operation_id:string,first_version:int,last_version:int,event_ids:list<string>} */
    public function commit(EntityId $student, EntityId $section, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->authorize();
        try {
            if (strlen($token) > 16384) {
                throw new \InvalidArgumentException();
            }
            $d = json_decode($this->cipher->open($token), true, 10, JSON_THROW_ON_ERROR);
            if (
                !is_array($d) ||
                ($d["kind"] ?? null) !== "academic-course-review" ||
                ($d["student_id"] ?? null) !== $student->value ||
                ($d["section_id"] ?? null) !== $section->value ||
                !is_int($d["expires_at"] ?? null) ||
                !is_int($d["credit_version"] ?? null) ||
                !is_int($d["expected_version"] ?? null) ||
                !is_bool($d["baseline"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (Throwable) {
            self::invalid("review_token", "Review the course publication again.");
        }
        if ($d["actor_id"] !== $actor->value) {
            throw new AccessDeniedException(
                "This course-publication review belongs to another staff member.",
            );
        }
        $correction = ($d["course_correction"] ?? false) === true;
        $this->sameActor($actor, correction: $correction);
        return $this->transactions->run(function () use ($student, $section, $actor, $d, $correction): array {
            $this->lock($student, $section, $actor);
            $enrollment = new EntityId($d["enrollment_id"]);
            [$id, $root, $facts] = $this->credits->decisionRecord(
                $student,
                $section,
                $enrollment,
                allowRemoved: true,
            );
            if ($id->value !== $d["credit_id"]) {
                throw new CorruptHistoryException();
            }
            $operation = new EntityId($d["operation_id"]);
            $stream = new StreamReference(new AcademicCreditId($id->value), "academic_credit");
            $base = null;
            if ($d["baseline"]) {
                $baseId = new EntityId($d["baseline_operation_id"]);
                $base = InstitutionalCreditWasEstablished::occur(
                    new AcademicCreditId($id->value),
                    [
                        "decision_id" => $baseId->value,
                        "provenance" => "observed_existing",
                        "credit" => $d["before"],
                        "section_id" => $section->value,
                        "policies" => [],
                        "grade" => $d["observed_grade"],
                    ],
                    ["__aggregate_type" => "academic_credit"],
                )->withPlayhead(1);
                $this->history->append(
                    $stream,
                    $baseId,
                    0,
                    new EventContext(
                        $actor,
                        new DateTimeImmutable($d["observed_at"]),
                        $operation,
                        reason: "Existing academic facts observed at a course-publication review; " .
                            "earlier reference and action times are unknown.",
                    ),
                    [$base],
                );
            }
            $eventClass = $correction
                ? InstitutionalCourseWasCorrected::class
                : InstitutionalCourseWasAccepted::class;
            $event = $eventClass
                ::occur(
                    new AcademicCreditId($id->value),
                    array_intersect_key(
                        $d,
                        array_flip([
                            "previous_decision_id",
                            "previous_course_version_id",
                            "course_version_id",
                            "course_code",
                            "course_title",
                        ]),
                    ) + ["decision_id" => $operation->value] +
                        ($correction
                            ? [
                                "previous_course_id" => $d["previous_course_id"],
                                "course_id" => $d["course_id"],
                            ]
                            : []),
                    ["__aggregate_type" => "academic_credit"],
                )
                ->withPlayhead($d["expected_version"] + ($d["baseline"] ? 2 : 1));
            $receipt = $this->history->append(
                $stream,
                $operation,
                $event->playhead() - 1,
                new EventContext(
                    $actor,
                    new DateTimeImmutable($d["effective_at"]),
                    $operation,
                    new EntityId($d["previous_decision_id"]),
                    $d["reason"],
                ),
                [$event],
            );
            $newDecision = $receipt["event_ids"] === [(string) $event->eventId()];
            if ($newDecision) {
                if (
                    $d["expires_at"] <= $this->clock->now()->getTimestamp() ||
                    $facts["version"] !== $d["credit_version"]
                ) {
                    throw new ConcurrentModificationException(
                        "The record or review changed. Review the course publication again.",
                    );
                }
                $this->eligible($root, $facts, $enrollment, $section, $correction);
                $this->date(substr($d["effective_at"], 0, 10));
                $definition = $this->courses->version(
                    new EntityId($correction ? $d["course_id"] : $facts["course_id"]),
                    new EntityId($d["course_version_id"]),
                    new HistoryReadBudget(),
                );
                if (
                    $definition->definition->code !== $d["course_code"] ||
                    ($definition->definition->text->transcriptTitle ?? $definition->definition->title) !==
                        $d["course_title"]
                ) {
                    throw new CorruptHistoryException();
                }
                if ($base !== null) {
                    $root->acceptHistorical($base);
                }
                $root->acceptHistorical($event);
                $this->records->acceptCourse(
                    $id,
                    $definition,
                    $d["previous_course_version_id"] === null
                        ? null
                        : new EntityId($d["previous_course_version_id"]),
                    new EntityId($facts["course_id"]),
                );
                $this->records->project(
                    $root->credit() ?? throw new CorruptHistoryException(),
                    $section,
                    $facts["version"],
                );
                $this->audit->record(
                    $actor,
                    $correction ? "academic.course_corrected" : "academic.course_accepted",
                    $id,
                );
            }
            $this->sameActor($actor, $facts["status"] === "W" || $d["before"]["status"] === "W", $correction);
            if ($newDecision && $d["expires_at"] <= $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException(
                    "The review expired before completion. Review the course publication again.",
                );
            }
            return $receipt;
        });
    }

    /** @param array<string,mixed> $facts */
    private function eligible(
        AcademicCredit $root,
        array $facts,
        EntityId $enrollment,
        EntityId $section,
        bool $correction = false,
    ): void {
        $this->authorize($facts["status"] === "W", $correction);
        $row = $this->records->enrollment($enrollment);
        if (
            ($correction ? !$root->canCorrectAwardedCourse() : !$root->canAcceptCourse()) ||
            (!$correction && $root->playhead() === 0 && $facts["course_version_id"] !== null) ||
            !in_array($facts["status"], ["A", "N", "W"], true) ||
            ($facts["status"] === "W" && !$root->canCorrectSource()) ||
            $row === null ||
            $row["student_id"] !== $facts["student_id"] ||
            $row["section_id"] !== $section->value ||
            $row["term_id"] !== ($root->registrationTermId() ?? $facts["term_id"]) ||
            $row["status"] !== $facts["status"]
        ) {
            self::invalid(
                "course_version_id",
                "Use a verified academic record with the appropriate course-correction authority. " .
                    "Resolve missing grades, inconsistent quantities or pending grade corrections first.",
            );
        }
    }
    private function date(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $date, $this->clock->now()->getTimezone());
        if (
            $parsed === false ||
            $parsed->format("Y-m-d") !== $date ||
            $date > $this->clock->now()->format("Y-m-d")
        ) {
            self::invalid("effective_on", "Enter a real effective date no later than today.");
        }
        return $parsed;
    }
    private function authorize(bool $withdrawn = false, bool $correction = false): EntityId
    {
        $actor = $this->access->requirePermission("sis:gradebook:amend");
        if (
            $this->access->requirePermission("sis:student:read")->value !== $actor->value ||
            ($withdrawn &&
                $this->access->requirePermission("sis:gradebook:closed")->value !== $actor->value) ||
            ($correction &&
                $this->access->requirePermission(self::CORRECTION_PERMISSION)->value !== $actor->value)
        ) {
            throw new AccessDeniedException("The academic decision actor changed.");
        }
        return $actor;
    }
    private function sameActor(EntityId $actor, bool $withdrawn = false, bool $correction = false): void
    {
        if ($actor->value !== $this->authorize($withdrawn, $correction)->value) {
            throw new AccessDeniedException("The academic decision actor changed.");
        }
    }
    private function lock(EntityId $student, EntityId $section, EntityId $actor): void
    {
        $this->records->lockStudent($student);
        $this->records->lockSection($section);
        $this->sameActor($actor);
    }
    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
}
