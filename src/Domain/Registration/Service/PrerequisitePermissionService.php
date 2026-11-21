<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\AcademicRecord\Service\WorkPursuitService;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Event\PrerequisitePermissionWasRecorded;
use Domain\Registration\PrerequisitePermission;
use Domain\Registration\Repository\PrerequisitePermissionRepository;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Service\StudentProgramStatusLedger;

final readonly class PrerequisitePermissionService
{
    public const string PERMISSION = "sis:registration:prerequisite-exception";

    public function __construct(
        private PrerequisitePermissionRepository $records,
        private PrerequisitePermissionLedger $ledger,
        private RegistrationRepository $registration,
        private EnrollmentLedger $enrollments,
        private AdmissionsRepository $admissions,
        private StudentRecordRepository $students,
        private StudentProgramStatusLedger $statuses,
        private WorkPursuitService $pursuits,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
    ) {
    }

    public function sections(
        EntityId $student,
        EntityId $pursuit,
        string $search = "",
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize();
        $this->scope($student, $pursuit, false);
        if (
            !mb_check_encoding($search, "UTF-8") ||
            mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            self::invalid(
                "search",
                "Search by section, course title or term, using up to eighty characters.",
            );
        }
        $page = $this->records->sections(trim($search), $after);
        $this->authorize($actor);
        return $page;
    }

    public function view(EntityId $student, EntityId $pursuit, EntityId $section): array
    {
        $actor = $this->authorize();
        $this->scope($student, $pursuit, false);
        $result = $this->context($student, $pursuit, $section);
        $result["editable"] = false;
        try {
            $this->scope($student, $pursuit, true);
            $result["editable"] = true;
        } catch (ValidationException) {
            // Closed or non-primary pursuits retain readable history.
        }
        $this->authorize($actor);
        return $result;
    }

    public function review(
        EntityId $student,
        EntityId $pursuit,
        EntityId $section,
        ?string $expiresAt,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $section,
            $expiresAt,
            $revision,
            $reason,
            $actor,
        ): array {
            $this->lock($student, $section);
            $this->scope($student, $pursuit, true);
            $context = $this->context($student, $pursuit, $section, true);
            $this->check($context["revision"], $revision);
            $id = new EntityId($context["stream_id"] ?? EntityId::generate()->value);
            $scope = $this->scopeData($student, $pursuit, $section);
            $root = $this->ledger->load($id, $scope);
            $approval = $this->approval($expiresAt, $context);
            if ($approval === $root->state()["approval"]) {
                self::invalid("expires_at", "Change the approval or expiry before reviewing.");
            }
            $operation = EntityId::generate();
            $intent = $this->intent($scope, $id, $approval, $revision, $reason);
            $this->authorize($actor);
            return [
                "stream_id" => $id->value,
                "operation_id" => $operation->value,
                "approval" => $approval,
                "revision" => $revision,
                "reason" => $reason,
                "before" => $root->state()["approval"],
                "after" => $approval,
                "section" => $context["section"],
                "review_token" => $this->proofs->issue(
                    "prerequisite-permission",
                    "record",
                    $id,
                    $actor,
                    $operation,
                    $intent,
                ),
            ];
        });
    }

    public function commit(
        EntityId $student,
        EntityId $pursuit,
        EntityId $section,
        EntityId $id,
        EntityId $operation,
        ?array $approval,
        string $revision,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        if ($approval !== null) {
            PrerequisitePermission::validate([
                "operation_id" => $operation->value,
                "previous_operation_id" => null,
                "before_hash" => PrerequisitePermission::hash(null),
                "scope" => $this->scopeData($student, $pursuit, $section),
                "approval" => $approval,
                "intent" => str_repeat("0", 64),
            ]);
            $approval = PrerequisitePermission::approval($approval);
        }
        $scope = $this->scopeData($student, $pursuit, $section);
        $intent = $this->intent($scope, $id, $approval, $revision, $reason);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $section,
            $id,
            $operation,
            $approval,
            $revision,
            $reason,
            $token,
            $actor,
            $intent,
            $scope,
        ): array {
            $this->lock($student, $section);
            $this->scope($student, $pursuit, false);
            $current = $this->ledger->current($student, $pursuit, $section);
            if ($current !== null && $current["id"] !== $id->value) {
                throw new ConcurrentModificationException(
                    "This prerequisite exception changed. Review it again.",
                );
            }
            $root = $this->ledger->load($id, $scope);
            $receipt = $root->receipt($operation, $actor, $intent);
            $this->proofs->validate(
                $token,
                "prerequisite-permission",
                "record",
                $id,
                $actor,
                $operation,
                $intent,
                $receipt !== null,
            );
            $this->authorize($actor);
            if ($receipt !== null) {
                return $receipt;
            }
            $this->scope($student, $pursuit, true);
            $context = $this->context($student, $pursuit, $section, true);
            $this->check($context["revision"], $revision);
            if (
                $approval !== $this->approval($approval["expires_at"] ?? null, $context) ||
                $approval === $root->state()["approval"]
            ) {
                throw new ConcurrentModificationException(
                    "Review the current section and prerequisite exception again.",
                );
            }
            $event = PrerequisitePermissionWasRecorded::occur(
                PrerequisitePermissionLedger::stream($id)->aggregateId,
                [
                    "operation_id" => $operation->value,
                    "previous_operation_id" => $root->state()["operation_id"],
                    "before_hash" => PrerequisitePermission::hash($root->state()["approval"]),
                    "scope" => $scope,
                    "approval" => $approval,
                    "intent" => $intent,
                ],
                ["__aggregate_type" => "prerequisite_permission"],
            )->withPlayhead($root->playhead() + 1);
            $this->history->append(
                PrerequisitePermissionLedger::stream($id),
                $operation,
                $root->playhead(),
                new EventContext($actor, $this->clock->now(), $operation, reason: $reason),
                [$event],
            );
            $state = [
                "position" => $event->playhead(),
                "operation_id" => $operation->value,
                "scope" => $scope,
                "approval" => $approval,
            ];
            $this->records->project($id, $state);
            $this->audit->record(
                $actor,
                $approval === null
                    ? "registration.prerequisite_revoked"
                    : "registration.prerequisite_approved",
                $student,
            );
            $this->authorize($actor);
            $this->scope($student, $pursuit, true);
            $final = $this->context($student, $pursuit, $section, true);
            if ($final["source_revision"] !== $context["source_revision"]) {
                throw new ConcurrentModificationException(
                    "The section changed during confirmation. Review again.",
                );
            }
            if ($approval !== null) {
                $this->approval($approval["expires_at"], $final);
            }
            $this->proofs->validate(
                $token,
                "prerequisite-permission",
                "record",
                $id,
                $actor,
                $operation,
                $intent,
            );
            $head =
                $this->history->latest(PrerequisitePermissionLedger::stream($id)) ??
                throw new CorruptHistoryException();
            $root->accept($head);
            if (
                $root->state() !==
                array_diff_key($this->ledger->current($student, $pursuit, $section) ?? [], ["id" => true])
            ) {
                throw new CorruptHistoryException();
            }
            return $root->receipt($operation, $actor, $intent) ?? throw new CorruptHistoryException();
        });
    }

    public function timeline(
        EntityId $student,
        EntityId $pursuit,
        EntityId $section,
        ?string $cursor = null,
    ): array {
        $actor = $this->authorize();
        $this->scope($student, $pursuit, false);
        $row = $this->ledger->current($student, $pursuit, $section);
        if ($row === null) {
            return ["items" => [], "next_cursor" => null];
        }
        $page = $this->history->page(
            PrerequisitePermissionLedger::stream(new EntityId($row["id"])),
            $cursor,
            20,
        );
        $items = [];
        foreach ($page->events as $stored) {
            $state = PrerequisitePermission::recordedState($stored);
            if ($state["scope"] !== $this->scopeData($student, $pursuit, $section)) {
                throw new CorruptHistoryException();
            }
            $items[] = [
                "version" => $state["position"],
                "approval" => $state["approval"],
                "effective_at" => $stored->context->effectiveAt?->format(DATE_ATOM),
                "reason" => $stored->context->reason,
            ];
        }
        $this->audit->record($actor, "registration.prerequisite_history_read", $student);
        $this->authorize($actor);
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }

    private function context(
        EntityId $student,
        EntityId $pursuit,
        EntityId $sectionId,
        bool $verify = false,
    ): array {
        $section = $this->registration->section($sectionId);
        $course = $this->registration->courseForSection($section);
        $term = $this->registration->term($section->termId);
        if ($verify) {
            $this->enrollments->verifyOffering($section, $course);
            $this->enrollments->verifyCourse($course, new HistoryReadBudget(), new RegistrationCourseRead());
        }
        $row = $this->ledger->current($student, $pursuit, $sectionId);
        $source = [
            "section_id" => $sectionId->value,
            "term_id" => $section->termId->value,
            "course_version_id" => $course->publicationVersionId?->value,
            "required" => $course->publishedPrerequisites ?? [],
            "section_active" => $section->active,
            "course_active" => $course->active,
            "term_end" => $term->dates->end?->format(DATE_ATOM),
        ];
        $sourceRevision = hash("sha256", json_encode($source, JSON_THROW_ON_ERROR));
        return [
            "stream_id" => $row["id"] ?? null,
            "state" => $row,
            "source" => $source,
            "source_revision" => $sourceRevision,
            "revision" => hash(
                "sha256",
                json_encode(
                    [$this->scopeData($student, $pursuit, $sectionId), $row, $sourceRevision],
                    JSON_THROW_ON_ERROR,
                ),
            ),
            "section" => [
                "id" => $sectionId->value,
                "code" => $section->code,
                "course_code" => $course->code,
                "course_title" => $course->title,
                "term_code" => $this->students->term($section->termId)["code"],
            ],
            "active" =>
                $row !== null &&
                $this->ledger->effective(
                    $student,
                    $pursuit,
                    $sectionId,
                    $section->termId,
                    $course->publicationVersionId,
                ) !== null,
        ];
    }

    private function approval(?string $expiresAt, array $context): ?array
    {
        if ($expiresAt === null) {
            return null;
        }
        $source = $context["source"];
        if (
            $source["course_version_id"] === null ||
            $source["required"] === [] ||
            !$source["section_active"] ||
            !$source["course_active"]
        ) {
            self::invalid(
                "section_id",
                "Choose an active section with a published course definition and course prerequisites.",
            );
        }
        try {
            if (
                preg_match(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/D',
                    $expiresAt,
                ) !== 1
            ) {
                throw new \InvalidArgumentException();
            }
            $date = new \DateTimeImmutable($expiresAt);
            if (\DateTimeImmutable::getLastErrors() !== false) {
                throw new \InvalidArgumentException();
            }
            $end =
            $source["term_end"] === null
            ? null
            : new \DateTimeImmutable($source["term_end"])->modify("+1 day")->setTime(0, 0);
            if ($date <= $this->clock->now() || ($end !== null && $date > $end)) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            self::invalid(
                "expires_at",
                "Choose a future expiry with an explicit time-zone offset, no later than the end of this term.",
            );
        }
        return [
            "term_id" => $source["term_id"],
            "course_version_id" => $source["course_version_id"],
            "expires_at" => $date->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z"),
        ];
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }

    private function lock(EntityId $student, EntityId $section): void
    {
        $this->registration->lockStudent($student);
        $this->registration->lockSection($section);
        $this->registration->lockCourse($this->registration->section($section)->courseId);
    }

    private function scope(EntityId $student, EntityId $pursuit, bool $editable): void
    {
        $this->admissions->student($student);
        $record = $this->students->assignment($pursuit);
        if ($record->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Student program not found.");
        }
        $this->statuses->verify($record);
        if ($editable && $this->pursuits->activePursuit($student)->value !== $pursuit->value) {
            self::invalid(
                "pursuit",
                "Maintain prerequisite exceptions under the current primary active program pursuit.",
            );
        }
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission(self::PERMISSION);
        if (
            $this->access->requirePermission("sis:student:read")->value !== $actor->value ||
            $this->access->authenticatedUserId()->value !== $actor->value ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function reason(#[\SensitiveParameter] string $reason): string
    {
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            self::invalid("reason", "Enter a reason up to 1,000 characters.");
        }
        return trim($reason);
    }

    private function check(string $actual, string $expected): void
    {
        if (!hash_equals($actual, $expected)) {
            throw new ConcurrentModificationException(
                "The section or prerequisite exception changed. Reload and review again.",
            );
        }
    }

    private function scopeData(EntityId $student, EntityId $pursuit, EntityId $section): array
    {
        return [
            "student_id" => $student->value,
            "pursuit_id" => $pursuit->value,
            "section_id" => $section->value,
        ];
    }

    private function intent(
        array $scope,
        EntityId $id,
        ?array $approval,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): string {
        return hash(
            "sha256",
            json_encode([$scope, $id->value, $approval, $revision, $reason], JSON_THROW_ON_ERROR),
        );
    }
}
