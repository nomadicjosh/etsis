<?php

declare(strict_types=1);

namespace Domain\Gradebook\Service;

use Domain\AcademicRecord\Service\InstitutionalCreditLedger;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\Event\ClassGradesWereCertified;
use Domain\Gradebook\GradeCertificationHistory;
use Domain\Gradebook\Repository\GradeCertificationRoster;
use Domain\Gradebook\ValueObject\GradeCertificationId;
use Domain\Gradebook\ValueObject\GradeCertificationSnapshot;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\Service\EnrollmentLedger;
use Domain\Registration\Service\SectionOfferingSelection;
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
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

/** Certification observes posted final grades; it never posts grades or closes correction paths. */
final readonly class GradeCertificationService
{
    public const string PERMISSION = "sis:gradebook:certify";
    public function __construct(
        private GradeCertificationRoster $roster,
        private RegistrationRepository $registration,
        private AdmissionsRepository $admissions,
        private InstitutionalCreditLedger $credits,
        private EnrollmentLedger $enrollments,
        private SectionVersionRepository $offerings,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
    ) {
    }
    public function readiness(EntityId $section): array
    {
        $actor = $this->authorize($section);
        $candidates = $this->roster->rows($section);
        return $this->transactions->run(function () use ($section, $actor, $candidates): array {
            $budget = $this->budget();
            $data = $this->capture($section, $budget, $candidates);
            $root = $this->restore($section, $budget);
            $records = $root->records();
            $latest = $records === [] ? null : $records[array_key_last($records)];
            $this->audit->record($actor, "gradebook.certification_readiness_read", $section);
            $this->authorize($section, $actor);
            return $this->view($data) + [
                "expected_version" => $root->playhead(),
                "latest" => $latest === null ? null : $this->metadata($latest, $data["snapshot"]),
            ];
        });
    }
    public function review(
        EntityId $section,
        #[\SensitiveParameter] string $reason,
        bool $acknowledged,
    ): array {
        $actor = $this->authorize($section);
        $this->reason($reason);
        $candidates = $this->roster->rows($section);
        return $this->transactions->run(function () use (
            $section,
            $actor,
            $reason,
            $acknowledged,
            $candidates,
        ): array {
            $budget = $this->budget();
            $data = $this->capture($section, $budget, $candidates);
            $snapshot = $this->ready($data, $acknowledged);
            $root = $this->restore($section, $budget);
            $operation = EntityId::generate();
            $intent = GradeCertificationHistory::intent($snapshot, $acknowledged, $reason);
            $this->audit->record($actor, "gradebook.certification_reviewed", $section);
            $this->authorize($section, $actor);
            return $this->view($data) + [
                "operation_id" => $operation->value,
                "revision" => $snapshot->fingerprint(),
                "expected_version" => $root->playhead(),
                "reason" => trim($reason),
                "acknowledged_history_gaps" => $acknowledged,
                "review_token" => $this->proofs->issue(
                    "grade-certification",
                    "faculty",
                    $section,
                    $actor,
                    $operation,
                    $this->proofRevision($intent, $root->playhead(), $snapshot->data["as_of_date"]),
                ),
            ];
        });
    }
    public function confirm(
        EntityId $section,
        EntityId $operation,
        string $revision,
        int $expectedVersion,
        #[\SensitiveParameter] string $reason,
        bool $acknowledged,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize($section);
        $this->reason($reason);
        if (!preg_match('/^[a-f0-9]{64}$/D', $revision) || $expectedVersion < 0) {
            throw new ValidationException([
                ["field" => "revision", "message" => "Review the class grades again."],
            ]);
        }
        $intent = GradeCertificationSnapshot::hash([
            $section->value,
            $revision,
            $acknowledged,
            trim($reason),
        ]);
        $budget = $this->budget();
        $known = array_any(
            $this->restore($section, $budget)->records(),
            static fn(array $r): bool => $r["operation_id"] === $operation->value,
        );
        $candidates = $known ? [] : $this->roster->rows($section);
        return $this->transactions->run(function () use (
            $section,
            $operation,
            $revision,
            $expectedVersion,
            $reason,
            $acknowledged,
            $token,
            $actor,
            $intent,
            $known,
            $candidates,
            $budget,
        ): array {
            $this->roles->lock();
            if ($known) {
                $this->registration->lockSection($section);
            } else {
                $data = $this->capture($section, $budget, $candidates);
            }
            // Accepted retries remain available after later grade changes, without replaying a new decision.
            $root = $this->restore($section, $budget);
            $accepted = array_find(
                $root->records(),
                static fn(array $r): bool => $r["operation_id"] === $operation->value,
            );
            $proofRevision = $this->proofRevision(
                $intent,
                $expectedVersion,
                $accepted["snapshot"]["as_of_date"] ?? $this->clock->now()->format("Y-m-d"),
            );
            $this->proofs->validate(
                $token,
                "grade-certification",
                "faculty",
                $section,
                $actor,
                $operation,
                $proofRevision,
                $accepted !== null,
            );
            if ($accepted !== null) {
                if (
                    $accepted["actor_id"] !== $actor->value ||
                    $accepted["intent"] !== $intent ||
                    $accepted["version"] !== $expectedVersion + 1
                ) {
                    throw new AccessDeniedException();
                }
                $this->authorize($section, $actor);
                return [
                    "status" => "recorded",
                    "operation_id" => $operation->value,
                    "version" => $accepted["version"],
                ];
            }
            if ($known) {
                throw new CorruptHistoryException();
            }
            // Section locking preserves accepted server order for the class.
            if ($root->playhead() !== $expectedVersion) {
                throw new ConcurrentModificationException(
                    "Another class certification was accepted. Review again.",
                );
            }
            $snapshot = $this->ready($data, $acknowledged);
            $this->same($revision, $snapshot->fingerprint());
            $this->audit->record($actor, "gradebook.class_grades_certified", $section);
            $this->authorize($section, $actor);
            $this->proofs->validate(
                $token,
                "grade-certification",
                "faculty",
                $section,
                $actor,
                $operation,
                $proofRevision,
            );
            $fresh = $this->ready($this->capture($section, $budget, $candidates), $acknowledged);
            $this->same($revision, $fresh->fingerprint());
            $at = $this->clock->now();
            if ($at->format("Y-m-d") !== $snapshot->data["as_of_date"]) {
                throw new ConcurrentModificationException(
                    "The certification date changed. Review again.",
                );
            }
            $records = $root->records();
            $event = ClassGradesWereCertified::occur(
                new GradeCertificationId($section->value),
                [
                    "operation_id" => $operation->value,
                    "section_id" => $section->value,
                    "previous_operation_id" =>
                        $records === [] ? null : $records[array_key_last($records)]["operation_id"],
                    "snapshot" => $snapshot->data,
                    "acknowledged_history_gaps" => $acknowledged,
                    "intent" => $intent,
                ],
                ["__aggregate_type" => "class_grade_certifications"],
            )->withPlayhead($expectedVersion + 1);
            $this->history->append(
                $this->stream($section),
                $operation,
                $expectedVersion,
                new EventContext($actor, $at, $operation, reason: trim($reason)),
                [$event],
            );
            $this->authorize($section, $actor);
            $this->proofs->validate(
                $token,
                "grade-certification",
                "faculty",
                $section,
                $actor,
                $operation,
                $proofRevision,
            );
            return [
                "status" => "recorded",
                "operation_id" => $operation->value,
                "version" => $expectedVersion + 1,
            ];
        });
    }
    public function listing(EntityId $section, int $before = 0): array
    {
        $actor = $this->authorize($section);
        if ($before < 0) {
            throw new ValidationException([
                ["field" => "before", "message" => "Use a valid history position."],
            ]);
        }
        return $this->transactions->run(function () use ($section, $before, $actor): array {
            $budget = $this->budget();
            $root = $this->restore($section, $budget);
            $rows = array_values(
                array_filter(
                    array_reverse($root->records()),
                    static fn(array $r): bool => $before === 0 || $r["version"] < $before,
                ),
            );
            $items = array_map(
                fn(array $r): array => $this->metadata($r, null),
                array_slice($rows, 0, 10),
            );
            $this->audit->record($actor, "gradebook.certifications_read", $section);
            $this->authorize($section, $actor);
            return [
                "items" => $items,
                "next_before" => count($rows) > 10 ? $items[9]["version"] : null,
            ];
        });
    }
    public function detail(EntityId $section, EntityId $operation): array
    {
        $actor = $this->authorize($section, archive: true);
        return $this->transactions->run(function () use ($section, $operation, $actor): array {
            $row =
                array_find(
                    $this->restore($section, $this->budget())->records(),
                    static fn(array $r): bool => $r["operation_id"] === $operation->value,
                ) ?? throw new RecordNotFoundException();
            $snapshot = new GradeCertificationSnapshot($row["snapshot"]);
            $data = $this->metadata($row, null) + [
                "section" => $snapshot->data["section"],
                "as_of_date" => $snapshot->data["as_of_date"],
                "reason" => $row["reason"],
                "actor_id" => $row["actor_id"],
                "rows" => $this->displayRows($snapshot->data["rows"]),
                "acknowledged_history_gaps" => $row["acknowledged_history_gaps"],
            ];
            $this->audit->record($actor, "gradebook.certification_archive_read", $operation);
            $this->authorize($section, $actor, true);
            return $data;
        });
    }
    private function capture(EntityId $section, HistoryReadBudget $budget, array $rows): array
    {
        if (count($rows) > 50) {
            throw new ValidationException([
                [
                    "field" => "section",
                    "message" =>
                        "Class certification supports at most fifty enrolled or withdrawn students; " .
                        "no partial roster can be certified.",
                ],
            ]);
        }
        foreach ($rows as $row) {
            $this->registration->lockStudent(new EntityId($row["student_id"]));
        }
        $this->registration->lockSection($section);
        $this->roster->lockDefinitions($section);
        if ($rows !== $this->roster->rows($section)) {
            throw new ConcurrentModificationException(
                "The roster changed. Refresh class readiness.",
            );
        }
        $s = $this->registration->section($section);
        $course = $this->registration->courseForSection($s);
        $offering = SectionOfferingSelection::verify($section, $this->offerings, $this->history);
        $header = [
            "id" => $section->value,
            "code" => $s->code,
            "course_id" => $s->courseId->value,
            "course_code" => $course->code,
            "course_title" => $course->title,
            "term_id" => $s->termId->value,
            "term_code" => $this->roster->termCode($s->termId),
            "offering_version_id" => $offering?->publication->value,
        ];
        $facts = [];
        $issues = [];
        foreach ($rows as $row) {
            $student = new EntityId($row["student_id"]);
            $enrollment =
            $this->registration->enrollment($student, $section) ??
            throw new CorruptHistoryException();
            if (
                $enrollment->id->value !== $row["id"] ||
                !in_array($enrollment->status->value, ["A", "N", "W"], true) ||
                $enrollment->termId->value !== $s->termId->value
            ) {
                throw new CorruptHistoryException();
            }
            $enrollmentFacts = $this->enrollments->evaluationFacts(
                $enrollment->id,
                $student,
                $budget,
            );
            $credit = $this->credits->certificationFacts($enrollment, $budget);
            $person = $this->admissions->student($student)->personId;
            $issue =
            $enrollmentFacts["history_version"] < 1 || $credit["history_version"] < 1
            ? "unrecorded_history"
            : ($credit["pending"]
                        ? "pending_grade_change"
                        : ($credit["grade"] === null
                            ? "missing_final_grade"
                            : null));
            $facts[] = [
                "student_id" => $student->value,
                "person_id" => $person->value,
                "enrollment_id" => $enrollment->id->value,
                "enrollment_version" => $enrollmentFacts["history_version"],
                "credit_history_version" => $credit["history_version"],
                "credit" => $credit["credit"],
                "grade" => $credit["grade"],
                "section_version_id" => $credit["section_version_id"],
                "earlier_history_unknown" =>
                    $credit["earlier_history_unknown"] ||
                    $enrollmentFacts["earlier_history_unknown"],
            ];
            if ($issue !== null) {
                $issues[] = ["student_id" => $student->value, "status" => $issue];
            }
        }
        $snapshot = null;
        if ($issues === [] && $facts !== []) {
            $evidence = [
                "section" => $header,
                "as_of_date" => $this->clock->now()->format("Y-m-d"),
                "rows" => $facts,
            ];
            if (strlen(json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 55000) {
                throw new ValidationException([
                    ["field" => "certification", "message" => "Class evidence exceeds the retained-record size limit."],
                ]);
            }
            $snapshot = new GradeCertificationSnapshot($evidence);
        }
        return [
            "section" => $header,
            "rows" => $facts,
            "issues" => $issues,
            "snapshot" => $snapshot,
        ];
    }
    private function view(array $data): array
    {
        return [
            "section" => $data["section"],
            "ready" => $data["snapshot"] !== null,
            "history_gaps" => $data["snapshot"]?->historyGaps,
            "issues" => $data["issues"],
            "rows" => $this->displayRows($data["rows"]),
        ];
    }
    private function displayRows(array $rows): array
    {
        return array_map(function (array $r): array {
            $p = $this->admissions->person(new EntityId($r["person_id"]));
            return [
                "student_id" => $r["student_id"],
                "current_name" => $p->givenName . " " . $p->familyName,
                "current_institutional_id" => $p->institutionalId->value,
                "course_code" => $r["credit"]["course_code"],
                "course_title" => $r["credit"]["course_title"],
                "status" => $r["credit"]["status"],
                "grade_code" => $r["grade"]["code"] ?? null,
                "attempted_milli" => $r["credit"]["attempted_milli"],
                "completed_milli" => $r["credit"]["completed_milli"],
            ];
        }, $rows);
    }
    private function ready(array $data, bool $acknowledged): GradeCertificationSnapshot
    {
        $snapshot = $data["snapshot"];
        if ($snapshot === null || ($snapshot->historyGaps && !$acknowledged)) {
            throw new ValidationException([
                [
                    "field" => "certification",
                    "message" =>
                        $snapshot === null
                            ? "Every enrolled or withdrawn student needs a retained final grade " .
                            "and no pending grade change; an empty class cannot be certified."
                            : "Acknowledge the earlier offering or grading-history gaps " .
                            "before certifying these recorded facts.",
                ],
            ]);
        }
        return $snapshot;
    }
    private function metadata(array $r, ?GradeCertificationSnapshot $current): array
    {
        $snapshot = new GradeCertificationSnapshot($r["snapshot"]);
        return [
            "operation_id" => $r["operation_id"],
            "version" => $r["version"],
            "recorded_at" => $r["recorded_at"],
            "student_count" => count($snapshot->data["rows"]),
            "history_gaps" => $snapshot->historyGaps,
            "matches_current" =>
                $current === null
                    ? null
                    : hash_equals($snapshot->fingerprint(), $current->fingerprint()),
        ];
    }
    private function restore(
        EntityId $section,
        HistoryReadBudget $budget,
    ): GradeCertificationHistory {
        $root = GradeCertificationHistory::root(new GradeCertificationId($section->value));
        $cursor = null;
        do {
            $page = $budget->page($this->history, $this->stream($section), $cursor);
            foreach ($page->events as $stored) {
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->playhead() !== $page->throughVersion) {
            throw new CorruptHistoryException();
        }
        return $root;
    }
    private function authorize(
        EntityId $section,
        ?EntityId $expected = null,
        bool $archive = false,
    ): EntityId {
        $actor = $this->access->requirePermission(self::PERMISSION);
        $scoped = $this->access->requireOwnerOrPermission(
            $this->registration->section($section)->facultyUserId,
            "sis:gradebook:manage",
        );
        if (
            $scoped->value !== $actor->value ||
            ($expected !== null && $actor->value !== $expected->value)
        ) {
            throw new AccessDeniedException();
        }
        if ($archive) {
            foreach (["sis:audit:read", "sis:student:read"] as $permission) {
                if ($this->access->requirePermission($permission)->value !== $actor->value) {
                    throw new AccessDeniedException();
                }
            }
        }
        return $actor;
    }
    private function budget(): HistoryReadBudget
    {
        return new HistoryReadBudget(maximumBytes: 4_194_304, maximumMilliseconds: 5000);
    }
    private function stream(EntityId $section): StreamReference
    {
        return new StreamReference(
            new GradeCertificationId($section->value),
            "class_grade_certifications",
        );
    }
    private function proofRevision(string $intent, int $version, string $date): string
    {
        return GradeCertificationSnapshot::hash([$intent, $version, $date]);
    }
    private function same(string $a, string $b): void
    {
        if (!hash_equals($a, $b)) {
            throw new ConcurrentModificationException(
                "Class grades or roster changed. Refresh and review again.",
            );
        }
    }
    private function reason(#[\SensitiveParameter] string $reason): void
    {
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x1f\x7f]/', $reason)
        ) {
            throw new ValidationException([
                [
                    "field" => "reason",
                    "message" => "Provide a certification reason of up to one thousand characters.",
                ],
            ]);
        }
    }
}
