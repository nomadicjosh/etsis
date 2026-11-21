<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\RegistrationBatchRepository;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Registration\ValueObject\RegistrationPlanSelection;
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
        private CoRequisiteService $corequisites,
        private RegistrationWindowService $windows,
        private \Domain\Registration\Waitlist\WaitlistSeatGuard $waitlists,
    ) {
    }

    /** Internal section-change assessment. The coordinator owns student/person/section/course locks.
     * @return array<string,mixed> */
    public function assessSectionChange(
        EntityId $student,
        EntityId $from,
        EntityId $to,
        bool $byStaff,
        \Domain\Shared\EventSourcing\HistoryReadBudget $budget,
        ?\Domain\Finance\ValueObject\RegistrationChargeQuote &$quote,
    ): array {
        $selection = new RegistrationBatchSelection($to, $student);
        $actor = $this->authorize($selection, $byStaff);
        $old = $this->repository->enrollment($student, $from);
        $source = $this->repository->section($from);
        $target = $this->repository->section($to);
        if (
            $from->value === $to->value ||
            $old === null ||
            !$old->status->isEnrolled() ||
            $source->courseId->value !== $target->courseId->value ||
            $source->termId->value !== $target->termId->value
        ) {
            throw new ValidationException([
                [
                    "field" => "to_section_id",
                    "message" => "Choose a different section of the same course and academic term.",
                ],
            ]);
        }
        $quotes = [];
        $result = $this->context($selection, $budget, $byStaff, $quotes, replacingSection: $from);
        $quote = $quotes[$student->value] ?? null;
        $this->actor($actor, $selection, $byStaff);
        return $result;
    }

    /** Internal completion check shared by schedule registration and section changes.
     * @param array<string,mixed> $expected */
    public function verifyReviewedSection(
        EntityId $student,
        EntityId $section,
        array $expected,
        \Domain\Finance\ValueObject\RegistrationChargeQuote $quote,
        \Domain\Shared\EventSourcing\HistoryReadBudget $budget,
    ): void {
        $current = $this->repository->section($section);
        $course = $this->repository->courseForSection($current);
        $term = $this->repository->term($current->termId);

        if (
            $this->windows->checked($current->termId, $this->repository->window($current->termId))->fingerprint() !==
                $expected['registration_window']['revision'] ||
            $current->termId->value !== $expected["term_id"] ||
            $course->id->value !== $expected["course_id"] ||
            $current->publicationVersionId?->value !== $expected["section_version_id"] ||
            $this->versions->selectionPosition($section) !== $expected["selection_position"] ||
            $course->publicationVersionId?->value !== $expected["course_version_id"] ||
            $current->capacity !== $expected["capacity"] ||
            $current->minimumEnrollment !== $expected["minimum_enrollment"] ||
            $current->webRegistration !== $expected["web_registration"] ||
            $course->code !== $expected["course_code"] ||
            $course->title !== $expected["course_title"] ||
            $course->creditsMilli !== $expected["credits_milli"] ||
            $term->dates->start->format("Y-m-d") !== $expected["term_starts_on"] ||
            $term->dates->end?->format("Y-m-d") !== $expected["term_ends_on"]
        ) {
            throw new ConcurrentModificationException(
                "Section definitions changed before completion. Review again.",
            );
        }
        $fresh = $this->billing->registrationQuote(
            $student,
            $current->termId,
            $current->fee,
            $course->creditsMilli,
            $budget,
        );
        if ($fresh->definitionFingerprint() !== $quote->definitionFingerprint()) {
            throw new ConcurrentModificationException(
                "Registration prices changed before completion. Review again.",
            );
        }
    }

    /** Several sections for one student, using the same readiness, pricing, proofs and receipt storage.
     * @return array<string,mixed> */
    public function reviewPlan(RegistrationPlanSelection $selection, bool $byStaff): array
    {
        $actor = $this->authorize($this->singleSelection($selection), $byStaff);
        $routing = $this->planRouting($selection);
        return $this->transactions->run(function () use ($selection, $routing, $actor, $byStaff): array {
            $this->lockPlan($selection, $routing);
            $quotes = [];
            $context = $this->planContext(
                $selection,
                new \Domain\Shared\EventSourcing\HistoryReadBudget(),
                $byStaff,
                $quotes,
            );
            $this->actor($actor, $this->singleSelection($selection), $byStaff);
            $operation = EntityId::generate();
            $revision = $this->revision($context);
            return $context + [
                "operation_id" => $operation->value,
                "revision" => $revision,
                "review_token" => $this->reviews->issue(
                    "registration-plan",
                    $byStaff ? "staff" : "student",
                    $selection->student,
                    $actor,
                    $operation,
                    $revision,
                ),
                "expires_at" => $this->clock
                    ->now()
                    ->modify("+20 minutes")
                    ->format(DATE_ATOM),
            ];
        });
    }

    /** @return array<string,mixed> */
    public function commitPlan(
        EntityId $operation,
        RegistrationPlanSelection $selection,
        string $revision,
        #[\SensitiveParameter] string $token,
        bool $byStaff,
    ): array {
        $single = $this->singleSelection($selection);
        $actor = $this->authorize($single, $byStaff);
        $hash = $selection->requestHash($revision, $byStaff);
        $receipt = $this->batches->receipt($operation);
        $this->reviews->validate(
            $token,
            "registration-plan",
            $byStaff ? "staff" : "student",
            $selection->student,
            $actor,
            $operation,
            $revision,
            accepted: $receipt !== null,
        );
        if ($receipt !== null) {
            return $this->replay($receipt, $actor, $hash);
        }
        $routing = $this->planRouting($selection);
        return $this->transactions->run(function () use (
            $selection,
            $single,
            $routing,
            $actor,
            $operation,
            $revision,
            $token,
            $byStaff,
            $hash,
        ): array {
            $this->lockPlan($selection, $routing);
            $receipt = $this->batches->receipt($operation);
            $this->reviews->validate(
                $token,
                "registration-plan",
                $byStaff ? "staff" : "student",
                $selection->student,
                $actor,
                $operation,
                $revision,
                accepted: $receipt !== null,
            );
            if ($receipt !== null) {
                return $this->replay($receipt, $actor, $hash);
            }
            $budget = new \Domain\Shared\EventSourcing\HistoryReadBudget();
            $quotes = [];
            $context = $this->planContext($selection, $budget, $byStaff, $quotes);
            if (!hash_equals($this->revision($context), $revision)) {
                throw new ConcurrentModificationException("The selected schedule changed. Review it again.");
            }
            if (!$context["can_confirm"]) {
                throw new ValidationException([
                    [
                        "field" => "sections",
                        "message" =>
                            "Resolve the readiness, timetable, seat or pricing issues before confirming. " .
                            "No registrations were saved.",
                    ],
                ]);
            }
            $items = [];
            $courseRead = new RegistrationCourseRead();
            $corequisitePlan = new CoRequisitePlan($selection, $this->repository, $operation);
            foreach ($selection->sections as $section) {
                $current = $this->repository->section($section);
                $course = $this->repository->courseForSection($current);
                $quote = $this->billing->registrationQuote(
                    $selection->student,
                    $current->termId,
                    $current->fee,
                    $course->creditsMilli,
                    $budget,
                );
                if ($quote->definitionFingerprint() !== $quotes[$section->value]->definitionFingerprint()) {
                    throw new ConcurrentModificationException(
                        "Registration prices changed. Review the schedule again.",
                    );
                }
                $enrollment = $this->registration->register(
                    $selection->student,
                    $section,
                    $byStaff,
                    budget: $budget,
                    chargeQuote: $quote,
                    courseRead: $courseRead,
                    corequisitePlan: $corequisitePlan,
                );
                if ($enrollment->id->value !== $corequisitePlan->sections[$section->value]['enrollment_id']) {
                    throw new ConcurrentModificationException('A coordinated registration changed before completion.');
                }
                $items[] = [
                    "section_id" => $section->value,
                    "enrollment_id" => $enrollment->id->value,
                    "status" => $enrollment->status->value,
                ];
            }
            $result = [
                "plan_id" => $operation->value,
                "student_id" => $selection->student->value,
                "created" => count($items),
                "registrations" => $items,
            ];
            $this->batches->save(
                $operation,
                $actor,
                $selection->sections[0],
                $hash,
                $result,
                $this->clock->now()->format(DATE_ATOM),
            );
            $this->audit->record($actor, "registration.plan_completed", $operation);
            // Check every accepted section after the final write/audit, not just the last section.
            $corequisiteRead = new CoRequisitePlan($selection, $this->repository);
            foreach ($selection->sections as $section) {
                $reviewedSection = array_values(array_filter(
                    $context['sections'],
                    static fn(array $row): bool => $row['section']['id'] === $section->value,
                ))[0];
                $this->registration->verifyCompletion(
                    $selection->student,
                    $section,
                    $actor,
                    $byStaff,
                    $budget,
                    prerequisiteRevision: $reviewedSection['student']['prerequisites']['permission'] === null
                        ? null : $reviewedSection['student']['prerequisites']['revision'],
                    courseRead: $courseRead,
                    corequisiteRead: $corequisiteRead,
                );
                $expected = array_values(
                    array_filter(
                        $context["sections"],
                        static fn(array $row): bool => $row["section"]["id"] === $section->value,
                    ),
                )[0]["section"];
                $this->verifyReviewedSection(
                    $selection->student,
                    $section,
                    $expected,
                    $quotes[$section->value],
                    $budget,
                );
            }
            if ($this->schedules->between($selection->sections) !== $context["planned_schedule"]) {
                throw new ConcurrentModificationException(
                    "Selected meeting publications or policy changed. Review again.",
                );
            }
            $this->verifyPlanRouting($selection, $routing);
            $this->actor($actor, $single, $byStaff);
            $this->reviews->validate(
                $token,
                "registration-plan",
                $byStaff ? "staff" : "student",
                $selection->student,
                $actor,
                $operation,
                $revision,
            );
            return $result;
        });
    }

    private function singleSelection(RegistrationPlanSelection $selection): RegistrationBatchSelection
    {
        return new RegistrationBatchSelection($selection->sections[0], $selection->student);
    }

    /** @return array<string,mixed> */
    private function planRouting(RegistrationPlanSelection $selection): array
    {
        $courses = [];
        foreach ($selection->sections as $section) {
            $id = $this->repository->section($section)->courseId;
            $courses[$id->value] = $id;
        }
        ksort($courses, SORT_STRING);
        $person = $this->admissions->student($selection->student)->personId;
        return [
            "person" => $person,
            "owner" => $this->admissions->person($person)->ownerUserId?->value,
            "courses" => array_values($courses),
        ];
    }

    private function lockPlan(RegistrationPlanSelection $selection, array $routing): void
    {
        $this->repository->lockStudent($selection->student);
        $this->repository->lockPerson($routing["person"]);
        foreach ($selection->sections as $section) {
            $this->repository->lockSection($section);
        }
        foreach ($routing["courses"] as $course) {
            $this->repository->lockCourse($course);
        }
        $this->verifyPlanRouting($selection, $routing);
    }

    private function verifyPlanRouting(RegistrationPlanSelection $selection, array $routing): void
    {
        if (
            $this->admissions->student($selection->student)->personId->value !== $routing["person"]->value ||
            $this->admissions->person($routing["person"])->ownerUserId?->value !== $routing["owner"]
        ) {
            throw new ConcurrentModificationException("The student identity or account link changed. Review again.");
        }
    }

    /** @return array<string,mixed> */
    private function planContext(
        RegistrationPlanSelection $selection,
        \Domain\Shared\EventSourcing\HistoryReadBudget $budget,
        bool $byStaff,
        array &$quotes,
    ): array {
        $rows = $issues = $terms = $termFees = $totals = $termCharges = $termCurrencies = [];
        $read = new RegistrationScheduleRead();
        $corequisitePlan = new CoRequisitePlan($selection, $this->repository);
        foreach ($selection->sections as $section) {
            $childQuotes = [];
            $child = $this->context(
                new RegistrationBatchSelection($section, $selection->student),
                $budget,
                $byStaff,
                $childQuotes,
                $read,
                corequisitePlan: $corequisitePlan,
            );
            $row = $child["students"][0];
            if ($row["enrollment_id"] !== null) {
                $issues[] = "A selected section already has an enrollment. Review it separately.";
            }
            if (!$child["section"]["active"] || $child["section"]["seats_remaining"] < 1) {
                $issues[] = "A selected section is inactive or full.";
            }
            if (
                $row["issues"] !== [] ||
                $row["schedule"]["blocked"] ||
                $row["prerequisites"]["status"] === "missing" ||
                $row["corequisites"]["status"] === "missing" ||
                $row["charges"]["problems"] !== []
            ) {
                $issues[] = "A selected section has readiness, timetable or pricing issues.";
            }
            $term = $this->repository->section($section)->termId;
            $terms[$term->value] = ($terms[$term->value] ?? 0) + 1;
            $quotes[$section->value] = $childQuotes[$selection->student->value] ?? null;
            $quote = $quotes[$section->value];
            if ($quote !== null) {
                $row["charges"] = $quote->planSummary($termFees);
                if ($row["charges"]["total_minor"] !== null) {
                    $currency = $row["charges"]["currency"];
                    if (isset($termCurrencies[$term->value]) && $termCurrencies[$term->value] !== $currency) {
                        $issues[] = "Selected sections in the same billing term must use one currency.";
                    }
                    $termCurrencies[$term->value] = $currency;
                    $amount = $row["charges"]["total_minor"];
                    $maximum = \Domain\Finance\ValueObject\Money::MAX_MINOR;
                    if (
                        (array_key_exists($currency, $totals) && $totals[$currency] === null) ||
                        $amount > $maximum - ($totals[$currency] ?? 0) ||
                        $amount > $maximum - ($termCharges[$term->value] ?? 0)
                    ) {
                        $totals[$currency] = null;
                        $issues[] = "The selected schedule exceeds the supported account amount limit.";
                    } else {
                        $totals[$currency] = ($totals[$currency] ?? 0) + $amount;
                        $termCharges[$term->value] = ($termCharges[$term->value] ?? 0) + $amount;
                        if (
                            $quote->termQuote->facts()["balance_before_minor"] >
                            $maximum - $termCharges[$term->value]
                        ) {
                            $issues[] = "The selected schedule exceeds the supported account amount limit.";
                        }
                    }
                }
            }
            $rows[] = ["section" => $child["section"], "student" => $row];
        }
        foreach ($terms as $id => $count) {
            $term = new EntityId($id);
            if (
                $this->repository->enrolledCount($selection->student, $term) + $count >
                $this->repository->window($term)->courseLimit
            ) {
                $issues[] = "The selected schedule exceeds the course limit for an academic term.";
            }
        }
        $schedule = $this->schedules->between($selection->sections, $read);
        if ($schedule["blocked"]) {
            $issues[] = "Selected sections have overlapping published meetings.";
        }
        return [
            "student_id" => $selection->student->value,
            "by_staff" => $byStaff,
            "sections" => $rows,
            "planned_schedule" => $schedule,
            "totals" => $totals,
            "issues" => array_values(array_unique($issues)),
            "can_confirm" => $issues === [],
        ];
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
            $courseRead = new RegistrationCourseRead();
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
                        courseRead: $courseRead,
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
            foreach ($context['students'] as $reviewedStudent) {
                if (
                    $reviewedStudent['enrollment_id'] === null &&
                    $reviewedStudent['prerequisites']['permission'] !== null
                ) {
                    $this->registration->verifyCompletion(
                        new EntityId($reviewedStudent['id']),
                        $selection->section,
                        $actor,
                        $byStaff,
                        $budget,
                        prerequisiteRevision: $reviewedStudent['prerequisites']['revision'],
                        courseRead: $courseRead,
                    );
                }
            }
            if (
                $created > 0 && $this->windows->checked(
                    $selectionTerm = new EntityId($context['section']['term_id']),
                    $this->repository->window($selectionTerm)
                )->fingerprint() !==
                $context['section']['registration_window']['revision']
            ) {
                throw new ConcurrentModificationException(
                    'The reviewed registration window changed before completion.',
                );
            }
            if ($created > 0) {
                $this->registration->verifyCourseSelection($selection->section, $budget, $courseRead);
            }
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
        ?RegistrationScheduleRead $scheduleRead = null,
        ?EntityId $replacingSection = null,
        ?CoRequisitePlan $corequisitePlan = null,
    ): array {
        $section = $this->repository->section($selection->section);
        $course = $this->repository->courseForSection($section);
        $selectedOffering = $this->registration->offeringDefinition($section->id);
        $students = [];
        $window = $this->windows->checked($section->termId, $this->repository->window($section->termId));
        $term = $this->repository->term($section->termId);
        $scheduleRead ??= new RegistrationScheduleRead();
        $required = $course->publishedPrerequisites ?? $this->repository->requiredCourses($course->id);
        $corequired = $course->prerequisiteGroups?->corequisites ?? [];
        $corequisiteLabels = $this->repository->courseLabels($corequired);
        $records = $existing = $programs = [];
        $waitlistStatuses = $this->waitlists->statuses($section->id, $selection->students, $budget);
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
            : $this->prerequisites->evaluate(
                $id,
                $assignment,
                $required,
                $budget,
                $course->prerequisiteGroups,
                $section->id,
                $section->termId,
                $course->publicationVersionId,
            );
            $corequisite = $assignment === null ? null : $this->corequisites->evaluate(
                $id,
                $assignment,
                $section->termId,
                $corequired,
                $budget,
                $corequisitePlan,
            );
            $schedule =
            $enrollment === null
            ? $this->schedules->evaluate($id, $section->id, $scheduleRead, $replacingSection)
            : null;
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
            $readiness = $enrollment === null ? new \Domain\Registration\RegistrationPolicy()->checks(
                $student,
                $restriction === null ? [] : [$restriction],
                $now,
                $byStaff,
                $window->isOpen($now),
                $section->webRegistration,
                max(0, $this->repository->enrolledCount($id, $section->termId) - (int) ($replacingSection !== null)),
                $window->courseLimit,
                $decision?->isMet() ?? true,
            ) : [];
            $waitlist = $waitlistStatuses[$id->value];
            if ($enrollment === null && $waitlist['waiting_count'] > 0) {
                $readiness[] = [
                    'code' => 'waitlist',
                    'label' => 'Waitlist priority',
                    'status' => $waitlist['can_register'] ? 'met' : 'blocked',
                    'message' => $waitlist['can_register'] ? 'Your request is the oldest accepted request.'
                        : 'Earlier accepted waitlist requests have priority.',
                    'next_step' => $waitlist['can_register'] ? null
                        : 'Wait for your turn or ask the registrar to review the queue.',
                ];
            }
            $issues = array_column(
                array_filter($readiness, static fn(array $check): bool => $check["status"] === "blocked"),
                "message",
            );
            if (
                $enrollment === null && $selectedOffering !== null &&
                !$selectedOffering->matchesOffering($section, $course)
            ) {
                $issues[] = "Review the section's published offering settings before registration.";
            }
            if ($corequisite !== null && !$corequisite->isMet()) {
                $issues[] = 'Select the required co-requisite sections together, ' .
                'or review qualifying completed coursework.';
            }
            if ($enrollment !== null && !$enrollment->status->isEnrolled()) {
                $issues = ["A closed enrollment requires registrar review before re-enrollment."];
            }
            $students[] = [
                'waitlist' => $waitlist,
                "charges" => $charges,
                "pricing_revision" => $quote?->fingerprint(),
                "issues" => $issues,
                "readiness" => $readiness,
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
                'corequisites' => $corequisite?->summary() ??
                    ['status' => $enrollment === null ? 'not_required' : 'not_rechecked'],
                "prerequisites" => [
                    "status" =>
                        $decision === null
                            ? "not_rechecked"
                            : ($required === []
                                ? "not_required"
                                : ($decision->permission !== null ? "permission" : ($decision->isMet()
                                    ? "met"
                                    : "missing"))),
                    "permission" => $decision?->permission === null ? null :
                        ['expires_at' => $decision->permission->data['expires_at']],
                    "course_statuses" => $decision?->courseStatuses() ?? [],
                    "groups" => $decision?->groupStatuses(),
                    "group_policy" => $decision?->groupPolicy(),
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
                "course_id" => $course->id->value,
                "course_code" => $course->code,
                "course_title" => $course->title,
                'registration_window' => $window->summary(),
                "prerequisite_courses" => $prerequisiteCourses,
                'corequisite_courses' => array_map(static fn(string $id): array =>
                    $corequisiteLabels[$id] ?? ['code' => null, 'title' => null], $corequired),
                "credits_milli" => $course->creditsMilli,
                'web_registration' => $section->webRegistration,
                'self_registration_recorded' => array_key_exists(
                    'web_registration',
                    $selectedOffering?->definition->data ?? [],
                ),
                "active" => $section->active && $course->active,
                "fee_minor" => $section->fee->minor,
                "currency" => $section->fee->currency,
                "capacity" => $section->capacity,
                "minimum_enrollment" => $section->minimumEnrollment,
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
