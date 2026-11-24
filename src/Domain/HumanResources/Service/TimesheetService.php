<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Audit\Repository\ActorDirectory;
use Domain\HumanResources\Dto\EmployeeProfile;
use Domain\HumanResources\Employee;
use Domain\HumanResources\Repository\TimeRepository;
use Domain\HumanResources\TimeEntry;
use Domain\HumanResources\ValueObject\EmploymentFacts;
use Domain\HumanResources\ValueObject\TimeReviewWeek;
use Domain\Person\Person;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Person\Service\ProfileLedger;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class TimesheetService
{
    public function __construct(
        private TimeRepository $repository,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private EmploymentLedger $history,
        private PersonProfileRepository $peopleRows,
        private AccessRepository $roles,
        private ProfileLedger $peopleHistory,
        private Clock $clock,
        private ActorDirectory $actors,
        private EmploymentDatesLedger $employmentDates,
        private PositionLedger $positions,
        private TimeReviewService $timeReviews,
        private TimeDecisionLedger $timeDecisions,
        private PrimaryPositionLedger $primaryPositions,
    ) {
    }

    public function addEmployee(EntityId $person, string $jobTitle): EntityId
    {
        $actor = $this->access->requirePermission("sis:hr:manage");
        $jobTitle = trim($jobTitle);
        $intent = EmploymentLedger::intent(["create", $person->value, $jobTitle]);
        return $this->maintain($person, $actor, function () use (
            $actor,
            $person,
            $jobTitle,
            $intent,
        ): EntityId {
            $existing = $this->repository->employeeForPerson($person);
            if ($existing !== null) {
                $operation = EmploymentLedger::operation($existing->id, $actor, $intent);
                if ($this->history->receipt($existing->id, $operation, $actor, $intent)) {
                    return $existing->id;
                }
                throw new ValidationException([
                    [
                        "field" => "person_id",
                        "message" =>
                            "This person already has employment. Open their existing record.",
                    ],
                ]);
            }
            $id = EntityId::generate();
            $employee = new Employee($id, $person, $jobTitle, true);
            $this->history->create(
                $employee,
                EmploymentLedger::operation($id, $actor, $intent),
                $actor,
                $intent,
            );
            $this->repository->addEmployee($id, $person, $jobTitle);
            $this->audit->record($actor, "employee.created", $id);
            return $id;
        });
    }

    public function employeeProfile(EntityId $id): EmployeeProfile
    {
        $actor = $this->access->authenticatedUserId();
        $employee = $this->repository->employee($id);
        $person = $this->authorizeRead($employee, $actor);
        $this->peopleHistory->verify($person);
        $state = $this->history->inspect($employee);
        $this->authorizeRead($employee, $actor);
        return new EmployeeProfile(
            $employee,
            $person,
            $state["revision"],
            $state["earlier_unknown"],
        );
    }

    /** @return array<string,mixed> */
    public function employeeDetails(EntityId $id): array
    {
        $actor = $this->access->authenticatedUserId();
        $profile = $this->employeeProfile($id);
        $primary = $this->primaryPositions->summary($this->primaryPositions->inspect($id)['root']->designation());
        $this->recheckEmployee($profile->employee, $actor);
        return $this->profileDetails($profile) + ['primary_position' => $primary];
    }

    /** @return array<string,mixed> */
    public function historyPage(EntityId $id, int $after = 0, ?int $throughVersion = null): array
    {
        $actor = $this->access->requirePermission("sis:hr:manage");
        $this->authorizeHistory($actor);
        $employee = $this->repository->employee($id);
        $person = $this->authorizeRead($employee, $actor);
        $this->peopleHistory->verify($person);
        $page = $this->history->review($employee, $after, $throughVersion);
        $ids = [];
        foreach ($page["items"] as $item) {
            $ids[$item["actor_id"]] = new EntityId($item["actor_id"]);
        }
        $names = $this->actors->names(array_values($ids));
        foreach ($page["items"] as &$item) {
            $item["recorded_by"] = $names[$item["actor_id"]] ?? "Unavailable account";
            unset($item["actor_id"]);
        }
        unset($item);
        $this->authorizeHistory($actor);
        $this->authorizeRead($employee, $actor);
        if (
            EmploymentFacts::of($this->repository->employee($id)) !== EmploymentFacts::of($employee)
        ) {
            throw new ConcurrentModificationException("Refresh employment history.");
        }
        return $page;
    }

    private function authorizeHistory(EntityId $actor): void
    {
        if (
            $this->access->requirePermission("sis:hr:manage")->value !== $actor->value ||
            $this->access->requirePermission("sis:audit:read")->value !== $actor->value
        ) {
            throw new AccessDeniedException("The employment history reviewer changed.");
        }
    }
    /** @return array<string,mixed> */
    private function profileDetails(EmployeeProfile $profile): array
    {
        return [
            "id" => $profile->employee->id->value,
            "person_id" => $profile->person->id->value,
            "given_name" => $profile->person->givenName,
            "family_name" => $profile->person->familyName,
            "institutional_id" => $profile->person->institutionalId->value,
            "job_title" => $profile->employee->jobTitle,
            "active" => $profile->employee->active,
            "revision" => $profile->revision,
            "earlier_history_unknown" => $profile->earlierHistoryUnknown,
            "is_self" =>
                $profile->person->ownerUserId?->value ===
                $this->access->authenticatedUserId()->value,
        ];
    }

    public function editEmployee(
        EntityId $id,
        string $jobTitle,
        bool $active,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): void {
        $actor = $this->access->requirePermission("sis:hr:manage");
        $reason = \Domain\HumanResources\ValueObject\DefinitionRules::reason($reason);
        $initial = $this->repository->employee($id);
        $next = new Employee($id, $initial->personId, trim($jobTitle), $active);
        $intent = EmploymentLedger::intent([
            "edit",
            EmploymentFacts::of($next),
            $revision,
            $reason,
        ]);
        $operation = EmploymentLedger::operation($id, $actor, $intent);
        $this->maintain($initial->personId, $actor, function () use (
            $id,
            $initial,
            $next,
            $revision,
            $actor,
            $operation,
            $intent,
            $reason,
        ): void {
            $this->repository->lockEmployee($id);
            $current = $this->repository->employee($id);
            if ($current->personId->value !== $initial->personId->value) {
                throw new AccessDeniedException("The employment person changed.");
            }
            if ($this->history->receipt($id, $operation, $actor, $intent)) {
                return;
            }
            if (
                !$this->history->change(
                    $current,
                    $next,
                    $revision,
                    $operation,
                    $actor,
                    $intent,
                    $reason,
                )
            ) {
                return;
            }
            $this->repository->saveEmployee($next);
            $this->audit->record($actor, "employee.updated", $id);
        });
    }

    /** @template T
     * @param \Closure(Employee,EntityId):T $operation
     * @return T
     */
    public function maintainEmployee(EntityId $id, \Closure $operation): mixed
    {
        $actor = $this->access->requirePermission("sis:hr:manage");
        $initial = $this->repository->employee($id);
        return $this->maintain($initial->personId, $actor, function () use (
            $id,
            $initial,
            $actor,
            $operation,
        ): mixed {
            $this->repository->lockEmployee($id);
            $current = $this->repository->employee($id);
            if ($current->personId->value !== $initial->personId->value) {
                throw new AccessDeniedException("The employment person changed.");
            }
            $this->history->inspect($current);
            return $operation($current, $actor);
        });
    }

    /** Internal companion for private child reads; repeats authorization without replaying the parent again. */
    public function recheckEmployee(Employee $expected, EntityId $actor): void
    {
        $current = $this->repository->employee($expected->id);
        $this->authorizeRead($current, $actor);
        if (EmploymentFacts::of($current) !== EmploymentFacts::of($expected)) {
            throw new ConcurrentModificationException("Reload employment before continuing.");
        }
    }

    /** @template T
     * @param \Closure():T $operation
     * @return T
     */
    private function maintain(EntityId $person, EntityId $actor, \Closure $operation): mixed
    {
        return $this->transactions->run(function () use ($person, $actor, $operation): mixed {
            $this->roles->lock();
            $this->peopleRows->lock($person);
            $record = $this->authorizeMaintenance($person, $actor);
            $this->peopleHistory->verify($record);
            $result = $operation();
            $this->authorizeMaintenance($person, $actor);
            return $result;
        });
    }
    private function authorizeMaintenance(EntityId $person, EntityId $actor): Person
    {
        if (
            $this->access->requirePermission("sis:hr:manage")->value !== $actor->value ||
            $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException("The HR editor changed.");
        }
        $record = $this->admissions->person($person);
        $this->protectSuper($record, $actor);
        return $record;
    }
    private function authorizeRead(Employee $employee, EntityId $actor): Person
    {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException("The HR reader changed.");
        }
        $person = $this->admissions->person($employee->personId);
        $this->access->requireOwnerOrPermission($person->ownerUserId, "sis:hr:manage");
        if ($person->ownerUserId?->value !== $actor->value) {
            $this->protectSuper($person, $actor);
        }
        return $person;
    }
    private function protectSuper(Person $person, EntityId $actor): void
    {
        if (
            $person->ownerUserId !== null &&
            $this->roles->isSuper($person->ownerUserId->value) &&
            !$this->roles->isSuper($actor->value)
        ) {
            throw new AccessDeniedException("This employment requires a super administrator.");
        }
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function entries(EntityId $id, ?EntityId $after = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $profile = $this->employeeProfile($id);
        $rows = $this->repository->entries($id, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $rows = $this->positions->labelEntries($id, $rows);
        $this->recheckEmployee($profile->employee, $actor);
        return ["items" => $rows, "next_after" => $more ? (string) $rows[49]["id"] : null];
    }

    /** @return array<string,mixed> */
    public function week(EntityId $id, ?string $date = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $profile = $this->employeeProfile($id);
        $now = $this->clock->now();
        $week = new TimeReviewWeek($date ?? $now->format("Y-m-d"), $now->getTimezone());
        $entries = $this->repository->inWindow(
            $id,
            new TimeSlot($week->startsAt, $week->endsAt),
            501,
        );
        if (count($entries) > 500) {
            throw new ValidationException([
                [
                    "field" => "week",
                    "message" =>
                        "This week exceeds the review limit. Ask HR for a detailed time review.",
                ],
            ]);
        }
        foreach ($entries as $entry) {
            if ($entry->employeeId->value !== $id->value) {
                throw new AccessDeniedException("The time review contains another employee.");
            }
        }
        $summary = $week->summarize($entries);
        $summary["items"] = $this->positions->labelEntries($id, $summary["items"]);
        $this->authorizeRead($profile->employee, $actor);
        return $summary + ["employee" => $this->profileDetails($profile)];
    }

    /** Owner/HR choices exclude pay terms and do not replay every candidate's stream.
     * @return array<string,mixed>
     */
    public function workPositions(EntityId $employee, TimeSlot $work): array
    {
        $actor = $this->access->authenticatedUserId();
        $profile = $this->employeeProfile($employee);
        new TimeEntry(EntityId::generate(), $employee, $work);
        $zone = $this->clock->now()->getTimezone();
        $this->employmentDates->requireCoverage(
            $employee,
            $work->startsAt->setTimezone($zone)->format("Y-m-d"),
            $work->endsAt->modify("-1 microsecond")->setTimezone($zone)->format("Y-m-d"),
            "starts_at",
        );
        $result = $this->positions->choices($employee, $work);
        $this->recheckEmployee($profile->employee, $actor);
        $evidence = $this->timeReviews->capture(
            new TimeEntry(EntityId::generate(), $employee, $work),
            proposed: true,
        );
        $this->recheckEmployee($profile->employee, $actor);
        $primary = $this->primaryPositions->summary(
            $this->primaryPositions->inspect($employee)['root']->designation()
        );
        $this->recheckEmployee($profile->employee, $actor);
        return $result + ["timezone" => $zone->getName(), "time_review" => $evidence->summary,
            'primary_position' => $primary];
    }

    public function submit(
        EntityId $employee,
        TimeSlot $work,
        ?EntityId $positionId = null,
        ?int $positionVersion = null,
    ): TimeEntry {
        $this->access->authenticatedUserId();
        $person = $this->admissions->person($this->repository->employeePerson($employee));
        $actor = $this->access->requireOwnerOrPermission($person->ownerUserId, "sis:hr:manage");
        return $this->transactions->run(function () use (
            $employee,
            $work,
            $actor,
            $positionId,
            $positionVersion,
            $person,
        ): TimeEntry {
            $this->roles->lock();
            $this->peopleRows->lock($person->id);
            $this->repository->lockEmployee($employee);
            $person = $this->admissions->person($this->repository->employeePerson($employee));
            $this->access->requireOwnerOrPermission($person->ownerUserId, "sis:hr:manage");
            $this->employeeProfile($employee);
            new TimeEntry(EntityId::generate(), $employee, $work);
            $position = $this->positions->select($employee, $work, $positionId, $positionVersion);
            $entry = new TimeEntry(
                EntityId::generate(),
                $employee,
                $work,
                positionId: $position?->id,
                positionVersion: $position?->version,
            );
            $zone = $this->clock->now()->getTimezone();
            $this->employmentDates->requireCoverage(
                $employee,
                $entry->work->startsAt->setTimezone($zone)->format("Y-m-d"),
                $entry->work->endsAt->modify("-1 microsecond")->setTimezone($zone)->format("Y-m-d"),
                "starts_at",
            );
            $this->repository->add($entry);
            $this->audit->record($actor, "timesheet.submitted", $entry->id);
            $this->authorizeRead($this->repository->employee($employee), $actor);
            return $entry;
        });
    }

    public function decide(
        EntityId $id,
        bool $approve,
        ?int $expectedVersion = null,
        ?string $reviewRevision = null,
        #[\SensitiveParameter] ?string $exceptionReason = null,
    ): void {
        $actor = $this->access->requirePermission("sis:hr:manage");
        $initial = $this->repository->get($id);
        $initialEmployee = $this->repository->employee($initial->employeeId);
        $exceptionReason =
        $exceptionReason === null
        ? null
        : \Domain\HumanResources\ValueObject\DefinitionRules::reason($exceptionReason);
        $this->transactions->run(function () use (
            $id,
            $approve,
            $actor,
            $initial,
            $initialEmployee,
            $expectedVersion,
            $reviewRevision,
            $exceptionReason,
        ): void {
            $this->roles->lock();
            $this->peopleRows->lock($initialEmployee->personId);
            $this->repository->lockEmployee($initial->employeeId);
            $entry = $this->repository->get($id);
            $profile = $this->employeeProfile($entry->employeeId);
            $person = $profile->person;
            if (
                $entry->employeeId->value !== $initial->employeeId->value ||
                $profile->employee->personId->value !== $initialEmployee->personId->value
            ) {
                throw new AccessDeniedException();
            }
            $stored = $this->timeDecisions->inspect($entry);
            if ($stored !== null) {
                $evidence = new \Domain\HumanResources\ValueObject\TimeReviewEvidence(
                    $stored["evidence"],
                );
                $this->exceptionAuthority($evidence, $approve, $actor, $exceptionReason);
                if (
                    $stored["actor_id"] !== $actor->value ||
                    $stored["next_status"] !== ($approve ? "approved" : "rejected") ||
                    ($expectedVersion !== null &&
                        $expectedVersion !== $stored["evidence"]["target"]["version"]) ||
                    ($approve &&
                        $reviewRevision !== null &&
                        $evidence->summary["revision"] !== $reviewRevision) ||
                    $stored["exception_reason"] !== $exceptionReason
                ) {
                    throw new ConcurrentModificationException(
                        "This time entry has another recorded decision.",
                    );
                }
                $this->recheckEmployee($profile->employee, $actor);
                return;
            }
            if ($expectedVersion !== null && $entry->version !== $expectedVersion) {
                throw new ConcurrentModificationException("Reload the time entry before deciding.");
            }
            $budget = new HistoryReadBudget(maximumBytes: 4_194_304, maximumMilliseconds: 5000);
            $evidence = $this->timeReviews->capture($entry, $budget, rejection: !$approve);
            if (
                $approve &&
                $reviewRevision !== null &&
                !hash_equals($evidence->summary["revision"], $reviewRevision)
            ) {
                throw new ConcurrentModificationException(
                    "The time entries or review policy changed. Reload the review.",
                );
            }
            $this->exceptionAuthority($evidence, $approve, $actor, $exceptionReason);
            if (
                $approve && $reviewRevision === null &&
                ($evidence->summary['requires_exception'] || $evidence->summary['primary_position'] !== null)
            ) {
                throw new ValidationException([
                    [
                        "field" => "review",
                        "message" => "Review the current time-policy checks before approving.",
                    ],
                ]);
            }
            $after = $entry->decide($approve, $actor, $person->ownerUserId);
            $this->approvalCoverage($entry, $approve);
            $this->audit->record(
                $actor,
                $approve ? "timesheet.approved" : "timesheet.rejected",
                $id,
            );
            $this->recheckEmployee($profile->employee, $actor);
            if ($this->access->requirePermission("sis:hr:manage")->value !== $actor->value) {
                throw new AccessDeniedException("The time reviewer changed.");
            }
            $fresh = $this->timeReviews->capture($this->repository->get($id), $budget, rejection: !$approve);
            if (!hash_equals($fresh->summary["revision"], $evidence->summary["revision"])) {
                throw new ConcurrentModificationException(
                    "The time entries or review policy changed. Reload the review.",
                );
            }
            $this->approvalCoverage($entry, $approve);
            $this->exceptionAuthority($fresh, $approve, $actor, $exceptionReason);
            $this->timeDecisions->record($entry, $after, $evidence, $actor, $exceptionReason);
            $this->repository->save($after, $entry->version);
            $this->timeDecisions->inspect($after);
            $this->recheckEmployee($profile->employee, $actor);
            $this->exceptionAuthority($fresh, $approve, $actor, $exceptionReason);
            if ($this->access->requirePermission("sis:hr:manage")->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        });
    }
    private function exceptionAuthority(
        \Domain\HumanResources\ValueObject\TimeReviewEvidence $evidence,
        bool $approve,
        EntityId $actor,
        #[\SensitiveParameter] ?string $reason,
    ): void {
        if ($approve && $evidence->summary['approval_blocked']) {
            throw new ValidationException([
                [
                    'field' => 'primary_position',
                    'message' => 'HR must record or reaffirm a current primary position before approving this time.',
                ],
            ]);
        }
        if ($approve && $evidence->summary["requires_exception"]) {
            if (
                $this->access->requirePermission("sis:hr:time-exception")->value !== $actor->value
            ) {
                throw new AccessDeniedException();
            }
            if ($reason === null) {
                throw new ValidationException([
                    [
                        "field" => "exception_reason",
                        "message" => "Explain why this flagged work may be approved.",
                    ],
                ]);
            }
        }
    }
    private function approvalCoverage(TimeEntry $entry, bool $approve): void
    {
        if (!$approve) {
            $this->positions->verifyEntryReference($entry);
            return;
        }
        $this->positions->requireApprovalCoverage($entry);
        $zone = $this->clock->now()->getTimezone();
        $this->employmentDates->requireCoverage(
            $entry->employeeId,
            $entry->work->startsAt->setTimezone($zone)->format("Y-m-d"),
            $entry->work->endsAt->modify("-1 microsecond")->setTimezone($zone)->format("Y-m-d"),
            "starts_at",
        );
    }

    /** @return array{entry:TimeEntry,profile:EmployeeProfile} */
    private function entryProfile(EntityId $id): array
    {
        $this->access->authenticatedUserId();
        $entry = $this->repository->get($id);
        $profile = $this->employeeProfile($entry->employeeId);
        $this->positions->verifyEntryReference($entry);
        return ["entry" => $entry, "profile" => $profile];
    }
    public function get(EntityId $id): TimeEntry
    {
        return $this->entryProfile($id)["entry"];
    }
    /** @return array<string,mixed> */
    public function entryDetails(EntityId $id): array
    {
        $actor = $this->access->authenticatedUserId();
        $data = $this->entryProfile($id);
        $entry = $data["entry"];
        $profile = $this->profileDetails($data["profile"]);
        $position = $this->positions->labelEntries($entry->employeeId, [
            [
                "position_id" => $entry->positionId?->value,
                "position_version" => $entry->positionVersion,
            ],
        ])[0]["position"];
        $this->recheckEmployee($data["profile"]->employee, $actor);
        $stored = $this->timeDecisions->inspect($entry);
        $limited = false;
        try {
            $assessment =
            $entry->status === "pending"
            ? $this->timeReviews->capture($entry)->summary
            : ($stored === null
                        ? null
                        : new \Domain\HumanResources\ValueObject\TimeReviewEvidence(
                            $stored["evidence"],
                        )->summary);
        } catch (ValidationException | HistoryReadLimitException $failure) {
            if ($entry->status !== "pending") {
                throw $failure;
            }
            $assessment = null;
            $limited = true;
        }
        $canException = false;
        $reason = null;
        try {
            $hr = $this->access->requirePermission("sis:hr:manage");
            if ($hr->value === $actor->value) {
                $reason = $stored["exception_reason"] ?? null;
            }
            $canException =
            $this->access->requirePermission("sis:hr:time-exception")->value === $actor->value;
        } catch (AccessDeniedException) {
            /* Ordinary owners see policy outcomes, never HR reasons. */
        }
        $this->recheckEmployee($data["profile"]->employee, $actor);
        return [
            "time_review" => $assessment,
            "time_review_limited" => $limited,
            "decision_recorded" => $stored !== null,
            "exception_reason" => $reason,
            "can_approve_exception" => $canException,
            "id" => $entry->id->value,
            "employee_id" => $entry->employeeId->value,
            "position" => $position,
            "status" => $entry->status,
            "version" => $entry->version,
            "institutional_id" => $profile["institutional_id"],
            "given_name" => $profile["given_name"],
            "family_name" => $profile["family_name"],
            "job_title" => $profile["job_title"],
            "is_self" => $profile["is_self"],
            "duration_seconds" =>
                $entry->work->endsAt->getTimestamp() - $entry->work->startsAt->getTimestamp(),
            "starts_at" => $entry->work->startsAt->format(DATE_ATOM),
            "ends_at" => $entry->work->endsAt->format(DATE_ATOM),
        ];
    }
}
