<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\RegistrationBatchRepository;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use JsonException;

/** One reviewed selection for an owned student or an authorized staff group. */
final readonly class RegistrationBatchService
{
    public function __construct(
        private RegistrationBatchRepository $batches,
        private RegistrationRepository $repository,
        private AdmissionsRepository $admissions,
        private RegistrationService $registration,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private SectionVersionRepository $versions,
        private \Domain\AcademicRecord\Service\WorkPursuitService $pursuits,
        private PrerequisiteService $prerequisites,
        private RegistrationScheduleService $schedules,
        private RegistrationBilling $billing,
        private \Domain\Shared\Service\ReviewProof $reviews,
    ) {
    }

    /** @return array<string, mixed> */
    public function review(RegistrationBatchSelection $selection, bool $byStaff = true): array
    {
        $actor = $this->authorize($selection, $byStaff);
        $routing = $this->routing($selection);
        return $this->transactions->run(function () use ($selection, $routing, $actor, $byStaff): array {
            $this->lock($selection, $routing);
            $quotes = [];
            $context = $this->context(
                $selection,
                new \Domain\Shared\EventSourcing\HistoryReadBudget(),
                $byStaff,
                $quotes,
            );
            $this->actor($actor, $selection, $byStaff);
            $operation = EntityId::generate();
            $revision = $this->revision($context);
            return $context + [
                "revision" => $revision,
                "operation_id" => $operation->value,
                "review_token" => $this->reviews->issue(
                    "registration",
                    $byStaff ? "staff" : "student",
                    $selection->section,
                    $actor,
                    $operation,
                    $revision,
                ),
                "expires_at" => $this->clock->now()->modify("+20 minutes")->format("Y-m-d\TH:i:sP"),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function commit(
        EntityId $operation,
        RegistrationBatchSelection $selection,
        string $revision,
        #[\SensitiveParameter] string $token,
        bool $byStaff = true,
    ): array {
        $actor = $this->authorize($selection, $byStaff);
        $hash = hash(
            "sha256",
            json_encode([$selection->requestHash($revision), $byStaff], JSON_THROW_ON_ERROR),
        );
        $this->reviews->validate(
            $token,
            "registration",
            $byStaff ? "staff" : "student",
            $selection->section,
            $actor,
            $operation,
            $revision,
            accepted: ($receipt = $this->batches->receipt($operation)) !== null,
        );
        if ($receipt !== null) {
            return $this->replay($receipt, $actor, $hash);
        }
        $routing = $this->routing($selection);
        return $this->transactions->run(function () use (
            $operation,
            $selection,
            $revision,
            $hash,
            $actor,
            $routing,
            $byStaff,
            $token,
        ): array {
            $this->lock($selection, $routing);
            // A matching concurrent request may have committed while these locks were being acquired.
            if (($receipt = $this->batches->receipt($operation)) !== null) {
                $this->reviews->validate(
                    $token,
                    "registration",
                    $byStaff ? "staff" : "student",
                    $selection->section,
                    $actor,
                    $operation,
                    $revision,
                    accepted: true,
                );
                return $this->replay($receipt, $actor, $hash);
            }
            $this->reviews->validate(
                $token,
                "registration",
                $byStaff ? "staff" : "student",
                $selection->section,
                $actor,
                $operation,
                $revision,
            );
            $budget = new \Domain\Shared\EventSourcing\HistoryReadBudget();
            $quotes = [];
            $context = $this->context($selection, $budget, $byStaff, $quotes);
            $this->actor($actor, $selection, $byStaff);
            if (!hash_equals($this->revision($context), $revision)) {
                throw new ConcurrentModificationException("Review the current batch before confirming.");
            }
            $items = [];
            $created = 0;
            $scheduleRead = new RegistrationScheduleRead();
            foreach ($context["students"] as $row) {
                if ($row["schedule"]["blocked"]) {
                    throw new ValidationException([
                        [
                            "field" => "students",
                            "message" =>
                                "A selected student has overlapping published meetings. Review " .
                                "the group before confirming.",
                        ],
                    ]);
                }
                if ($row["prerequisites"]["status"] === "missing") {
                    throw new ValidationException([
                        [
                            "field" => "students",
                            "message" =>
                                "A selected student does not meet the prerequisites. " .
                                "Review the group before confirming.",
                        ],
                    ]);
                }
                if ($row["charges"]["problems"] !== []) {
                    throw new ValidationException([
                        [
                            "field" => "students",
                            "message" =>
                                "Resolve the registration pricing shown in the review. " .
                                "No changes in this batch were saved.",
                        ],
                    ]);
                }
                try {
                    $enrollment = $this->registration->register(
                        new EntityId($row["id"]),
                        $selection->section,
                        $byStaff,
                        budget: $budget,
                        scheduleRead: $scheduleRead,
                        chargeQuote: $quotes[$row["id"]] ?? null,
                    );
                } catch (DomainException) {
                    throw new ValidationException([
                        [
                            "field" => "students",
                            "message" =>
                                $row["institutional_id"] .
                                ": Registration could not be completed. Check activity, holds, prerequisites, " .
                                "timetable, course limits, seats and billing. No changes in this batch were saved.",
                        ],
                    ]);
                }
                $new = $row["enrollment_id"] === null;
                $created += (int) $new;
                $items[] = [
                    "student_id" => $row["id"],
                    "enrollment_id" => $enrollment->id->value,
                    "created" => $new,
                    "status" => $enrollment->status->value,
                ];
            }
            $result = [
                "batch_id" => $operation->value,
                "section_id" => $selection->section->value,
                "created" => $created,
                "existing" => count($items) - $created,
                "registrations" => $items,
            ];
            $this->batches->save(
                $operation,
                $actor,
                $selection->section,
                $hash,
                $result,
                $this->clock->now()->format("Y-m-d\TH:i:sP"),
            );
            $this->audit->record($actor, "registration.batch_completed", $operation);
            $this->actor($actor, $selection, $byStaff);
            $this->reviews->validate(
                $token,
                "registration",
                $byStaff ? "staff" : "student",
                $selection->section,
                $actor,
                $operation,
                $revision,
            );
            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function receipt(EntityId $id): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        $receipt =
        $this->batches->receipt($id) ?? throw new RecordNotFoundException("Batch receipt not found.");
        $this->owner($receipt["actor_id"], $actor);
        return $receipt["result"];
    }

    /** @param array{actor_id: string, request_hash: string, result: array<string, mixed>} $receipt
     * @return array<string, mixed>
     */
    private function replay(array $receipt, EntityId $actor, string $hash): array
    {
        $this->owner($receipt["actor_id"], $actor);
        if (!hash_equals($receipt["request_hash"], $hash)) {
            throw new ConcurrentModificationException("This operation belongs to a different batch request.");
        }
        return $receipt["result"];
    }

    private function authorize(RegistrationBatchSelection $selection, bool $byStaff): EntityId
    {
        if ($byStaff) {
            return $this->access->requirePermission("sis:registration:manage");
        }
        $actor = $this->access->authenticatedUserId();
        if (count($selection->students) !== 1) {
            throw new AccessDeniedException("Self-service registration applies to one student record.");
        }
        $student = $this->admissions->student($selection->students[0]);
        if ($this->admissions->person($student->personId)->ownerUserId?->value !== $actor->value) {
            throw new AccessDeniedException("The student account does not belong to this user.");
        }
        return $actor;
    }

    private function actor(EntityId $actor, RegistrationBatchSelection $selection, bool $byStaff): void
    {
        if ($this->authorize($selection, $byStaff)->value !== $actor->value) {
            throw new AccessDeniedException("The registration actor changed.");
        }
    }

    private function owner(string $owner, EntityId $actor): void
    {
        if ($owner !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
    }

    /** @return array{people: list<EntityId>, course: EntityId} */
    private function routing(RegistrationBatchSelection $selection): array
    {
        $people = [];
        foreach ($selection->students as $id) {
            $person = $this->admissions->student($id)->personId;
            $people[$person->value] = $person;
        }
        ksort($people, SORT_STRING);
        return [
            "people" => array_values($people),
            "course" => $this->repository->section($selection->section)->courseId,
        ];
    }

    /** @param array{people: list<EntityId>, course: EntityId} $routing */
    private function lock(RegistrationBatchSelection $selection, array $routing): void
    {
        // Sorted students, sorted people, section, course: acquire every eligibility lock before any consistent read.
        foreach ($selection->students as $id) {
            $this->repository->lockStudent($id);
        }
        foreach ($routing["people"] as $id) {
            $this->repository->lockPerson($id);
        }
        $this->repository->lockSection($selection->section);
        $this->repository->lockCourse($routing["course"]);
    }

    /** @return array<string, mixed> */
    private function context(
        RegistrationBatchSelection $selection,
        \Domain\Shared\EventSourcing\HistoryReadBudget $budget,
        bool $byStaff,
        array &$quotes,
    ): array {
        $section = $this->repository->section($selection->section);
        $course = $this->repository->courseForSection($section);
        $students = [];
        $window = $this->repository->window($section->termId);
        $term = $this->repository->term($section->termId);
        $scheduleRead = new RegistrationScheduleRead();
        $required = $course->publishedPrerequisites ?? $this->repository->requiredCourses($course->id);
        $records = $existing = $programs = [];
        foreach ($selection->students as $id) {
            $records[$id->value] = $this->admissions->student($id);
            $existing[$id->value] = $this->repository->enrollment($id, $section->id);
            if ($existing[$id->value] === null) {
                $program = $records[$id->value]->programId;
                $programs[$program->value] = $program;
            }
        }
        sort($required, SORT_STRING);
        $labels = $programs === [] ? [] : $this->repository->courseLabels($required);
        $prerequisiteCourses = $programs === [] ? [] : array_map(static fn(string $id): array =>
            $labels[$id] ?? ["code" => null, "title" => null], $required);
        $rules =
        $programs === [] ? [] : $this->billing->rules($section->termId, $budget, array_values($programs));
        foreach ($selection->students as $id) {
            $student = $records[$id->value];
            $person = $this->admissions->person($student->personId);
            $enrollment = $existing[$id->value];
            $assignment = $enrollment === null ? $this->pursuits->activePursuit($id) : null;
            $decision =
            $assignment === null
            ? null
            : $this->prerequisites->evaluate($id, $assignment, $required, $budget);
            $schedule =
            $enrollment === null ? $this->schedules->evaluate($id, $section->id, $scheduleRead) : null;
            $quote =
            $enrollment === null
            ? $this->billing->registrationQuote(
                $id,
                $section->termId,
                $section->fee,
                $course->creditsMilli,
                $budget,
                $rules,
            )
            : null;
            if ($quote !== null) {
                $quotes[$id->value] = $quote;
            }
            $charges = $quote?->summary() ?? [
                "currency" => $section->fee->currency,
                "total_minor" => 0,
                "items" => [],
                "problems" => [],
            ];
            $now = $this->clock->now();
            $restriction = $this->repository->blockingRestriction($student->personId, $now, $byStaff);
            $issues =
            $enrollment === null
            ? new \Domain\Registration\RegistrationPolicy()->issues(
                $student,
                $restriction === null ? [] : [$restriction],
                $now,
                $byStaff,
                $window->isOpen($now),
                $section->webRegistration,
                $this->repository->enrolledCount($id, $section->termId),
                $window->courseLimit,
                $decision?->isMet() ?? true,
            )
            : ($enrollment->status->isEnrolled()
                        ? []
                        : ["A closed enrollment requires registrar review before re-enrollment."]);
            $students[] = [
                "charges" => $charges,
                "pricing_revision" => $quote?->fingerprint(),
                "issues" => $issues,
                "id" => $id->value,
                "given_name" => $person->givenName,
                "family_name" => $person->familyName,
                "institutional_id" => $person->institutionalId->value,
                "active" => $student->active,
                "assignment_id" => $assignment?->value,
                "schedule" =>
                    $schedule === null
                        ? ["status" => "not_rechecked", "blocked" => false]
                        : $schedule->summary() + [
                                "revision" => $schedule->revision(),
                                "conflicts" => $schedule->facts["conflicts"],
                            ],
                "prerequisites" => [
                    "status" =>
                        $decision === null
                            ? "not_rechecked"
                            : ($required === []
                                ? "not_required"
                                : ($decision->isMet()
                                    ? "met"
                                    : "missing")),
                    "course_statuses" => $decision?->courseStatuses() ?? [],
                    "missing_count" => $decision?->missingCount(),
                    "revision" => $decision?->revision(),
                ],
                "enrollment_id" => $enrollment?->id->value,
                "enrollment_status" => $enrollment?->status->value,
            ];
        }
        return [
            "by_staff" => $byStaff,
            "section" => [
                "id" => $section->id->value,
                "code" => $section->code,
                "term_id" => $section->termId->value,
                "term_starts_on" => $term->dates->start->format("Y-m-d"),
                "term_ends_on" => $term->dates->end?->format("Y-m-d"),
                "section_version_id" => $section->publicationVersionId?->value,
                "selection_position" => $this->versions->selectionPosition($section->id),
                "course_version_id" => $course->publicationVersionId?->value,
                "course_code" => $course->code,
                "course_title" => $course->title,
                "prerequisite_courses" => $prerequisiteCourses,
                "credits_milli" => $course->creditsMilli,
                "active" => $section->active && $course->active,
                "fee_minor" => $section->fee->minor,
                "currency" => $section->fee->currency,
                "capacity" => $section->capacity,
                "seats_remaining" => max(
                    0,
                    $section->capacity - $this->repository->sectionCount($section->id),
                ),
            ],
            "students" => $students,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @throws JsonException
     */
    private function revision(array $context): string
    {
        return hash("sha256", json_encode($context, JSON_THROW_ON_ERROR));
    }
}
