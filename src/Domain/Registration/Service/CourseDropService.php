<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Registration\Enrollment;
use Domain\Registration\Enum\RegistrationStatus;
use Domain\Registration\Repository\CourseDropRepository;
use Domain\Registration\Repository\DropPolicyRepository;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class CourseDropService
{
    public function __construct(
        private CourseDropRepository $drops,
        private DropPolicyRepository $policies,
        private DropPolicyService $policy,
        private RegistrationRepository $registration,
        private AdmissionsRepository $admissions,
        private RegistrationBilling $billing,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private MessageCipher $cipher,
        private \Domain\AcademicRecord\Service\InstitutionalCreditLedger $credits,
        private EnrollmentLedger $enrollments,
        private \Domain\Registration\Repository\RegistrationBatchRepository $plans,
        private \Domain\Shared\Service\ReviewProof $proofs,
        private CoRequisiteService $corequisites,
    ) {
    }

    /** Indexed current choices; no history replay, seat changes or charges.
     * @return array<string,mixed> */
    public function planChoices(
        EntityId $student,
        bool $byStaff,
        string $search = '',
        ?EntityId $after = null
    ): array {
        $actor = $this->authorizePlan($student, $byStaff);
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new ValidationException([['field' => 'search', 'message' => 'Use up to 80 characters.']]);
        }
        $rows = $this->drops->choices($student, trim($search), $after);
        $this->authorizePlan($student, $byStaff, $actor);
        return ['items' => array_slice($rows, 0, 20),
            'next_after' => count($rows) > 20 ? $rows[19]['cursor'] : null];
    }

    /** One bounded selection; each child keeps its ordinary academic and financial history.
     * @return array<string,mixed> */
    public function reviewPlan(
        \Domain\Registration\ValueObject\RegistrationPlanSelection $selection,
        bool $byStaff,
        ?HistoryReadBudget $budget = null,
    ): array {
        $actor = $this->authorizePlan($selection->student, $byStaff);
        $routing = $this->planRouting($selection);
        return $this->transactions->run(function () use ($selection, $byStaff, $actor, $routing, $budget): array {
            $this->lockPlan($selection, $routing);
            $this->authorizePlan($selection->student, $byStaff, $actor);
            $context = $this->planContext($selection, $budget ?? new HistoryReadBudget());
            $revision = $this->revision($context);
            $operation = EntityId::generate();
            $this->authorizePlan($selection->student, $byStaff, $actor);
            $first = $context['courses'][0];
            $visible = ['student_id' => $selection->student->value, 'effective_on' => $context['effective_on'],
                'student' => ['given_name' => $first['given_name'], 'family_name' => $first['family_name'],
                    'institutional_id' => $first['institutional_id']],
                'courses' => array_map(static fn(array $row): array => array_intersect_key(
                    $row,
                    array_flip([
                        'section_id', 'section_code', 'effective_on', 'outcome', 'corequisites', 'has_earned_ceus',
                    ])
                ), $context['courses'])];
            return $visible + ['operation_id' => $operation->value, 'revision' => $revision,
                'review_token' => $this->proofs->issue(
                    'course-drop-plan',
                    $byStaff ? 'staff' : 'student',
                    $selection->student,
                    $actor,
                    $operation,
                    $revision
                )];
        });
    }

    /** @return array<string,mixed> */
    public function commitPlan(
        \Domain\Registration\ValueObject\RegistrationPlanSelection $selection,
        EntityId $operation,
        string $revision,
        #[\SensitiveParameter] string $token,
        bool $byStaff,
        ?HistoryReadBudget $budget = null,
    ): array {
        $actor = $this->authorizePlan($selection->student, $byStaff);
        $hash = hash('sha256', 'course-drop-plan:' . $selection->requestHash($revision, $byStaff));
        $old = $this->plans->receipt($operation);
        $this->authorizePlan($selection->student, $byStaff, $actor);
        $this->planProof($selection, $actor, $operation, $revision, $token, $byStaff, $old !== null);
        if ($old !== null) {
            return $this->replayPlan($old, $actor, $hash);
        }
        $routing = $this->planRouting($selection);
        return $this->transactions->run(function () use (
            $selection,
            $operation,
            $revision,
            $token,
            $byStaff,
            $actor,
            $hash,
            $routing,
            $budget
        ): array {
            $this->lockPlan($selection, $routing);
            $this->authorizePlan($selection->student, $byStaff, $actor);
            $old = $this->plans->receipt($operation);
            $this->planProof($selection, $actor, $operation, $revision, $token, $byStaff, $old !== null);
            if ($old !== null) {
                return $this->replayPlan($old, $actor, $hash);
            }
            $budget ??= new HistoryReadBudget();
            $dependencyRead = $this->dependencies(
                $selection->student,
                array_map(static fn(EntityId $id): string => $id->value, $selection->sections),
                $budget
            );
            $context = $this->planContext($selection, $budget, $dependencyRead);
            if (!hash_equals($this->revision($context), $revision)) {
                throw new ConcurrentModificationException('The selected drops changed. Review them again.');
            }
            foreach ($context['courses'] as $course) {
                $this->assertDependencies($course['corequisites']);
            }
            $removed = $withdrawn = 0;
            $items = [];
            foreach ($context['courses'] as $course) {
                $child = $this->commit(
                    $selection->student,
                    new EntityId($course['section_id']),
                    new EntityId($course['enrollment_id']),
                    $course['version'],
                    EntityId::generate(),
                    $course['revision'],
                    $course['effective_on'],
                    budget: $budget,
                    dropping: array_map(static fn(EntityId $id): string => $id->value, $selection->sections),
                    dependencyRead: $dependencyRead,
                );
                $removed += (int) $child['academic_records_removed'];
                $withdrawn += (int) !$child['academic_records_removed'];
                $items[] = ['section_id' => $child['section_id'], 'operation_id' => $child['operation_id']];
            }
            $result = ['drop_plan_id' => $operation->value, 'student_id' => $selection->student->value,
                'removed' => $removed, 'withdrawn' => $withdrawn, 'drops' => $items];
            $this->plans->save(
                $operation,
                $actor,
                $selection->sections[0],
                $hash,
                $result,
                $this->clock->now()->format(DATE_ATOM)
            );
            $this->audit->record($actor, 'registration.drop_plan_completed', $operation);
            $after = $this->dependencies(
                $selection->student,
                array_map(static fn(EntityId $id): string => $id->value, $selection->sections),
                $budget
            );
            if ($after->revision() !== $context['courses'][0]['corequisites']['revision']) {
                throw new ConcurrentModificationException(
                    'Co-requisite support changed before completion. Review again.',
                );
            }
            $this->assertDependencies($after->summary());
            $this->verifyPlanRouting($selection, $routing);
            $this->authorizePlan($selection->student, $byStaff, $actor);
            $this->planProof($selection, $actor, $operation, $revision, $token, $byStaff, false);
            if ($context['effective_on'] !== $this->clock->now()->format('Y-m-d')) {
                throw new ConcurrentModificationException('The institutional date changed. Review the drops again.');
            }
            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function planReceipt(EntityId $operation, bool $byStaff): array
    {
        $requestActor = $this->access->authenticatedUserId();
        $row = $this->plans->receipt($operation) ?? throw new RecordNotFoundException('Drop confirmation not found.');
        $result = $row['result'];
        if (!isset($result['drop_plan_id'], $result['student_id'])) {
            throw new RecordNotFoundException('Drop confirmation not found.');
        }
        $actor = $this->authorizePlan(new EntityId($result['student_id']), $byStaff, $requestActor);
        return $this->replayPlan($row, $actor, $row['request_hash']);
    }

    /** @return array<string,mixed> */
    private function planContext(
        \Domain\Registration\ValueObject\RegistrationPlanSelection $selection,
        HistoryReadBudget $budget,
        ?\Domain\Registration\ValueObject\CoRequisiteDropDecision $dependencyRead = null,
    ): array {
        $dependencyRead ??= $this->dependencies(
            $selection->student,
            array_map(static fn(EntityId $id): string => $id->value, $selection->sections),
            $budget
        );
        $courses = [];
        foreach ($selection->sections as $section) {
            $row = $this->review(
                $selection->student,
                $section,
                budget: $budget,
                dropping: array_map(static fn(EntityId $id): string => $id->value, $selection->sections),
                dependencyRead: $dependencyRead
            );
            unset($row['operation_id']);
            $courses[] = $row;
        }
        return ['student_id' => $selection->student->value,
            'effective_on' => $this->clock->now()->format('Y-m-d'), 'courses' => $courses];
    }

    private function authorizePlan(EntityId $student, bool $byStaff, ?EntityId $expected = null): EntityId
    {
        $actor = $this->authorize($student);
        if ($byStaff) {
            $this->access->requirePermission('sis:registration:manage');
        } else {
            $record = $this->admissions->student($student);
            if ($this->admissions->person($record->personId)->ownerUserId?->value !== $actor->value) {
                throw new AccessDeniedException('The student owner must be signed in.');
            }
        }
        if ($expected !== null && $expected->value !== $actor->value) {
            throw new AccessDeniedException('The drop confirmation account changed.');
        }
        return $actor;
    }

    private function planProof(
        \Domain\Registration\ValueObject\RegistrationPlanSelection $selection,
        EntityId $actor,
        EntityId $operation,
        string $revision,
        #[\SensitiveParameter] string $token,
        bool $byStaff,
        bool $accepted,
    ): void {
        $this->proofs->validate(
            $token,
            'course-drop-plan',
            $byStaff ? 'staff' : 'student',
            $selection->student,
            $actor,
            $operation,
            $revision,
            $accepted
        );
    }

    /** @return array<string,mixed> */
    private function replayPlan(array $row, EntityId $actor, string $hash): array
    {
        if ($row['actor_id'] !== $actor->value) {
            throw new AccessDeniedException('This drop confirmation belongs to another account.');
        }
        if (!hash_equals($row['request_hash'], $hash) || !isset($row['result']['drop_plan_id'])) {
            throw new ConcurrentModificationException('This reference belongs to another confirmation.');
        }
        return $row['result'];
    }

    /** @return array<string,mixed> */
    private function planRouting(\Domain\Registration\ValueObject\RegistrationPlanSelection $selection): array
    {
        $person = $this->admissions->student($selection->student)->personId;
        $courses = $terms = $sections = [];
        foreach ($selection->sections as $id) {
            $section = $this->registration->section($id);
            $courses[$section->courseId->value] = $section->courseId;
            $terms[$section->termId->value] = $section->termId;
            $sections[$id->value] = [$section->courseId->value, $section->termId->value];
        }
        ksort($courses, SORT_STRING);
        ksort($terms, SORT_STRING);
        return ['person' => $person, 'owner' => $this->admissions->person($person)->ownerUserId?->value,
            'courses' => $courses, 'terms' => $terms, 'sections' => $sections];
    }

    private function lockPlan(
        \Domain\Registration\ValueObject\RegistrationPlanSelection $selection,
        array $routing
    ): void {
        $this->registration->lockStudent($selection->student);
        $this->registration->lockPerson($routing['person']);
        foreach ($selection->sections as $id) {
            $this->registration->lockSection($id);
        }
        foreach ($routing['courses'] as $id) {
            $this->registration->lockCourse($id);
        }
        foreach ($routing['terms'] as $id) {
            $this->policies->lockTerm($id);
        }
        $this->verifyPlanRouting($selection, $routing);
    }

    private function verifyPlanRouting(
        \Domain\Registration\ValueObject\RegistrationPlanSelection $selection,
        array $routing
    ): void {
        $current = $this->planRouting($selection);
        if (
            $current['person']->value !== $routing['person']->value || $current['owner'] !== $routing['owner'] ||
            $current['sections'] !== $routing['sections']
        ) {
            throw new ConcurrentModificationException('The student or section links changed. Review the drops again.');
        }
    }

    private function authorize(EntityId $student): EntityId
    {
        $actor = $this->access->authenticatedUserId();
        $record = $this->admissions->student($student);
        $this->access->requireOwnerOrPermission(
            $this->admissions->person($record->personId)->ownerUserId,
            "sis:registration:manage",
        );
        return $actor;
    }
    /** @return array<string, mixed> */
    public function review(
        EntityId $student,
        EntityId $section,
        ?string $effective = null,
        ?HistoryReadBudget $budget = null,
        array $dropping = [],
        ?CoRequisitePlan $replacement = null,
        ?\Domain\Registration\ValueObject\CoRequisiteDropDecision $dependencyRead = null,
    ): array {
        $this->authorize($student);
        if ($effective !== null) {
            $this->access->requirePermission("sis:registration:manage");
        }
        $date = $effective ?? $this->clock->now()->format("Y-m-d");
        $routing = $this->routing($student, $section);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $date,
            $routing,
            $budget,
            $dropping,
            $replacement,
            $dependencyRead,
        ): array {
            $this->lock($student, $section, $routing);
            $context = $this->context(
                $student,
                $section,
                $date,
                $budget ?? new HistoryReadBudget(),
                $dropping,
                $replacement,
                $dependencyRead,
            );
            return $context + [
                "operation_id" => EntityId::generate()->value,
                "revision" => $this->revision($context),
            ];
        });
    }
    /** @return array<string, mixed> */
    public function commit(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        int $version,
        EntityId $operation,
        string $revision,
        string $effective,
        bool $effectiveDated = false,
        ?HistoryReadBudget $budget = null,
        array $dropping = [],
        ?CoRequisitePlan $replacement = null,
        ?\Domain\Registration\ValueObject\CoRequisiteDropDecision $dependencyRead = null,
    ): array {
        $actor = $this->authorize($student);
        if ($effectiveDated) {
            $this->access->requirePermission("sis:registration:manage");
        }
        $request = [
            "student_id" => $student->value,
            "section_id" => $section->value,
            "enrollment_id" => $enrollment->value,
            "version" => $version,
            "revision" => $revision,
            "effective_on" => $effective,
            "effective_dated" => $effectiveDated,
            "dropping" => $dropping,
            "replacement" => $replacement?->sections,
        ];
        $hash = hash("sha256", json_encode($request, JSON_THROW_ON_ERROR));
        if (($old = $this->drops->receipt($operation)) !== null) {
            return $this->replay($old, $actor, $hash);
        }
        $routing = $this->routing($student, $section);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $version,
            $operation,
            $revision,
            $effective,
            $effectiveDated,
            $actor,
            $hash,
            $routing,
            $budget,
            $dropping,
            $replacement,
            $dependencyRead,
        ): array {
            $this->lock($student, $section, $routing);
            if (($old = $this->drops->receipt($operation)) !== null) {
                return $this->replay($old, $actor, $hash);
            }
            if (!$effectiveDated && $effective !== $this->clock->now()->format("Y-m-d")) {
                throw new ConcurrentModificationException(
                    "The institutional date changed. Review this drop again.",
                );
            }
            if ($this->authorize($student)->value !== $actor->value) {
                throw new AccessDeniedException('The drop confirmation account changed.');
            }
            if ($effectiveDated) {
                $this->access->requirePermission('sis:registration:manage');
            }
            $budget ??= new HistoryReadBudget();
            $dropping = $dropping === [] ? [$section->value] : $dropping;
            if (!in_array($section->value, $dropping, true)) {
                throw new \LogicException('The coordinated removal must include this section.');
            }
            $context = $this->context(
                $student,
                $section,
                $effective,
                $budget,
                $dropping,
                $replacement,
                $dependencyRead,
            );
            if (
                $context["enrollment_id"] !== $enrollment->value ||
                $context["version"] !== $version ||
                !hash_equals($this->revision($context), $revision)
            ) {
                throw new ConcurrentModificationException(
                    "This enrollment or drop policy changed. Review it again.",
                );
            }
            $dependencies = $dependencyRead ?? $this->dependencies($student, $dropping, $budget, $replacement);
            $this->assertDependencies($dependencies->summary());
            if ($context['corequisites']['revision'] !== $dependencies->revision()) {
                throw new ConcurrentModificationException('Co-requisite support changed. Review the drop again.');
            }
            $current =
                $this->registration->enrollment($student, $section) ??
                throw new RecordNotFoundException("Enrollment not found.");
            $when = new DateTimeImmutable($effective, $this->clock->now()->getTimezone());
            $closed = $current->withdrawOnDate($current->termId, $when, $context["outcome"]["grace_ends_on"]);
            $this->credits->drop(
                $closed,
                $context["outcome"],
                new \Domain\Shared\EventSourcing\EventContext(
                    $actor,
                    $when,
                    $operation,
                    reason: "Reviewed course drop.",
                ),
                $budget,
            );
            $this->enrollments->close(
                $closed,
                $this->registration->section($section),
                $context["outcome"],
                new \Domain\Shared\EventSourcing\EventContext(
                    $actor,
                    $when,
                    $operation,
                    reason: "Reviewed course drop.",
                ),
                $budget,
                $dependencies->facts['checks'] === [] ? null : $dependencies,
            );
            $reversed =
                $context["outcome"]["delete_records"] &&
                $this->billing->reverseCourseCharge(
                    $student,
                    $current->termId,
                    $current->id,
                    new EventContext(
                        $actor,
                        $when,
                        $operation,
                        $current->id,
                        reason: 'Course charge reversed by an accepted early drop.'
                    )
                );
            $result = [
                "operation_id" => $operation->value,
                "student_id" => $student->value,
                "section_id" => $section->value,
                "enrollment_id" => $closed->id->value,
                "status" => $closed->status->value,
                "version" => $closed->version,
                "effective_on" => $effective,
                "grade_code" => $context["outcome"]["grade_code"],
                "academic_records_removed" => $context["outcome"]["delete_records"],
                "course_charge" => $context["outcome"]["delete_records"]
                    ? ($reversed
                        ? "reversed"
                        : "none")
                    : "kept",
                "cash_refund_issued" => false,
                "term_id" => $closed->termId->value,
                "registered_at" => $closed->registeredAt->format(DATE_ATOM),
                "status_at" => $closed->statusAt->format(DATE_ATOM),
            ];
            $this->drops->record(
                $operation,
                $student,
                $section,
                $actor,
                $enrollment,
                $closed->version,
                $hash,
                $this->cipher->seal(
                    json_encode(["before" => $context, "result" => $result], JSON_THROW_ON_ERROR),
                ),
                $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format(DATE_ATOM),
            );
            $this->audit->record(
                $actor,
                $context["outcome"]["delete_records"] ? "registration.removed" : "registration.withdrawn",
                $enrollment,
            );
            // Shared multi-drop checks run once more at the outer completion boundary.
            if ($dependencyRead === null) {
                $after = $this->dependencies($student, $dropping, $budget, $replacement);
                if ($after->revision() !== $dependencies->revision()) {
                    throw new ConcurrentModificationException(
                        'Co-requisite support changed before completion. Review again.',
                    );
                }
                $this->assertDependencies($after->summary());
            }
            if ($this->authorize($student)->value !== $actor->value) {
                throw new AccessDeniedException('The drop confirmation account changed.');
            }
            if ($effectiveDated) {
                $this->access->requirePermission('sis:registration:manage');
            }
            if (
                $this->admissions->student($student)->personId->value !== $routing['person']->value ||
                (!$effectiveDated && $effective !== $this->clock->now()->format('Y-m-d'))
            ) {
                throw new ConcurrentModificationException('The student link or drop date changed. Review again.');
            }
            return $result;
        });
    }
    /** @return array<string, mixed> */
    public function receipt(EntityId $operation): array
    {
        $actor = $this->access->authenticatedUserId();
        $row =
        $this->drops->receipt($operation) ?? throw new RecordNotFoundException("Drop receipt not found.");
        $this->authorize(new EntityId($row["student_id"]));
        if ($row["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
        return json_decode(
            $this->cipher->open($row["encrypted_content"]),
            true,
            32,
            JSON_THROW_ON_ERROR,
        )["result"];
    }
    public function drop(EntityId $student, EntityId $section): Enrollment
    {
        $r = $this->review($student, $section);
        return $this->asEnrollment(
            $this->commit(
                $student,
                $section,
                new EntityId($r["enrollment_id"]),
                $r["version"],
                new EntityId($r["operation_id"]),
                $r["revision"],
                $r["effective_on"],
            ),
        );
    }
    /** @return array<string, mixed> */
    public function dropEffective(
        EntityId $student,
        EntityId $section,
        string $effective,
        array $dropping = [],
        ?\Domain\Registration\ValueObject\CoRequisiteDropDecision $dependencyRead = null,
    ): array {
        $r = $this->review($student, $section, $effective, dropping: $dropping, dependencyRead: $dependencyRead);
        return $this->commit(
            $student,
            $section,
            new EntityId($r["enrollment_id"]),
            $r["version"],
            new EntityId($r["operation_id"]),
            $r["revision"],
            $effective,
            true,
            dropping: $dropping,
            dependencyRead: $dependencyRead,
        );
    }
    /** @param array<string, mixed> $result */
    private function asEnrollment(array $result): Enrollment
    {
        return new Enrollment(
            new EntityId($result["enrollment_id"]),
            new EntityId($result["student_id"]),
            new EntityId($result["section_id"]),
            new EntityId($result["term_id"]),
            RegistrationStatus::from($result["status"]),
            new DateTimeImmutable($result["registered_at"]),
            new DateTimeImmutable($result["status_at"]),
            $result["version"],
        );
    }
    /** @return array{person: EntityId, course: EntityId, term: EntityId} */
    private function routing(EntityId $student, EntityId $section): array
    {
        $s = $this->registration->section($section);
        return [
            "person" => $this->admissions->student($student)->personId,
            "course" => $s->courseId,
            "term" => $s->termId,
        ];
    }
    /** @param array{person: EntityId, course: EntityId, term: EntityId} $routing */
    private function lock(EntityId $student, EntityId $section, array $routing): void
    {
        $this->registration->lockStudent($student);
        $this->registration->lockPerson($routing["person"]);
        $this->registration->lockSection($section);
        $this->registration->lockCourse($routing["course"]);
        $this->policies->lockTerm($routing["term"]);
        $this->authorize($student);
    }
    /** @return array<string, mixed> */
    private function context(
        EntityId $student,
        EntityId $section,
        string $effective,
        HistoryReadBudget $budget,
        array $dropping = [],
        ?CoRequisitePlan $replacement = null,
        ?\Domain\Registration\ValueObject\CoRequisiteDropDecision $dependencyRead = null,
    ): array {
        $e =
        $this->registration->enrollment($student, $section) ??
        throw new RecordNotFoundException("Enrollment not found.");
        $now = $this->clock->now();
        $date = DateTimeImmutable::createFromFormat("!Y-m-d", $effective);
        if (
            !$e->status->isEnrolled() ||
            $date === false ||
            $date->format("Y-m-d") !== $effective ||
            $effective < $e->registeredAt->setTimezone($now->getTimezone())->format("Y-m-d") ||
            $effective > $now->format("Y-m-d")
        ) {
            throw new ValidationException([
                [
                    "field" => "enrollment_id",
                    "message" =>
                        "A current enrollment and an effective date from registration through today are required.",
                ],
            ]);
        }
        $credit = $this->drops->credit($e);
        $sectionRecord = $this->registration->section($section);
        $this->enrollments->verifyExisting($e, $sectionRecord, $budget);
        if (
            $sectionRecord->termId->value !== $e->termId->value ||
            $credit["course_id"] !== $sectionRecord->courseId->value ||
            $credit["status"] !== $e->status->value
        ) {
            throw new ConcurrentModificationException(
                "The course and academic record need registrar review.",
            );
        }
        if (
            $credit["grade_id"] !== null ||
            $credit["completed_milli"] !== 0 ||
            $credit["grade_points_milli"] !== 0
        ) {
            throw new ValidationException([
                [
                    "field" => "enrollment_id",
                    "message" => "Posted academic credit requires a grade-change review.",
                ],
            ]);
        }
        $s = $this->admissions->student($student);
        $p = $this->admissions->person($s->personId);
        [, $academic] = $this->credits->decisionRecord($student, $section, $e->id, budget: $budget);
        $ceuAward = $academic->earnedCeus();
        $outcome = $this->policy->outcome($e->termId, $effective, $e->studentId, $e->id, $e->sectionId, $budget);
        if ($outcome['delete_records'] && isset($ceuAward['earned_milli'])) {
            throw new ValidationException([['field' => 'enrollment_id', 'message' =>
                \Codefy\Framework\Helpers\trans('Resolve the recorded CEU award before removing this attempt.')]]);
        }
        return [
            "student_id" => $student->value,
            "section_id" => $section->value,
            "enrollment_id" => $e->id->value,
            "version" => $e->version,
            "given_name" => $p->givenName,
            "family_name" => $p->familyName,
            "institutional_id" => $p->institutionalId->value,
            "student_active" => $s->active,
            "section_code" => $this->registration->section($section)->code,
            "effective_on" => $effective,
            "timezone" => $now->getTimezone()->getName(),
            "credit" => $credit,
            ...($ceuAward !== null ? ['has_earned_ceus' => isset($ceuAward['earned_milli'])] : []),
            "corequisites" => ($dependencyRead ?? $this->dependencies(
                $student,
                $dropping === [] ? [$section->value] : $dropping,
                $budget,
                $replacement,
            ))->summary(),
            "outcome" => $outcome,
        ];
    }
    /** Internal institutional setting for related authorized lifecycle reviews. */
    public function dependencyPolicy(): array
    {
        return $this->corequisites->policy();
    }
    /** Internal coordinator guard; callers hold the student lock and authorize every selected mutation. */
    public function dependencies(
        EntityId $student,
        array $sections,
        HistoryReadBudget $budget,
        ?CoRequisitePlan $replacement = null
    ): \Domain\Registration\ValueObject\CoRequisiteDropDecision {
        return $this->corequisites->dropping($student, $sections, $budget, $replacement);
    }
    private function assertDependencies(array $summary): void
    {
        if ($summary['blocked']) {
            throw new ValidationException([['field' => 'corequisites',
                'message' => 'This drop leaves a required co-requisite unsupported. ' .
                    'Select the linked courses together.']]);
        }
    }
    /** @param array<string, mixed> $context */
    private function revision(array $context): string
    {
        return hash("sha256", json_encode($context, JSON_THROW_ON_ERROR));
    }
    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function replay(array $row, EntityId $actor, string $hash): array
    {
        if ($row["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
        if (!hash_equals($row["request_hash"], $hash)) {
            throw new ConcurrentModificationException("This reference belongs to another drop request.");
        }
        return json_decode(
            $this->cipher->open($row["encrypted_content"]),
            true,
            32,
            JSON_THROW_ON_ERROR,
        )["result"];
    }
}
