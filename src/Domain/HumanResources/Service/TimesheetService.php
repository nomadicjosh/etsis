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
    ) {
    }

    public function addEmployee(EntityId $person, string $jobTitle): EntityId
    {
        $actor = $this->access->requirePermission('sis:hr:manage');
        $jobTitle = trim($jobTitle);
        $intent = EmploymentLedger::intent(['create', $person->value, $jobTitle]);
        return $this->maintain($person, $actor, function () use ($actor, $person, $jobTitle, $intent): EntityId {
            $existing = $this->repository->employeeForPerson($person);
            if ($existing !== null) {
                $operation = EmploymentLedger::operation($existing->id, $actor, $intent);
                if ($this->history->receipt($existing->id, $operation, $actor, $intent)) {
                    return $existing->id;
                }
                throw new ValidationException([['field' => 'person_id',
                    'message' => 'This person already has employment. Open their existing record.']]);
            }
            $id = EntityId::generate();
            $employee = new Employee($id, $person, $jobTitle, true);
            $this->history->create($employee, EmploymentLedger::operation($id, $actor, $intent), $actor, $intent);
            $this->repository->addEmployee($id, $person, $jobTitle);
            $this->audit->record($actor, 'employee.created', $id);
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
        return new EmployeeProfile($employee, $person, $state['revision'], $state['earlier_unknown']);
    }

    /** @return array<string,mixed> */
    public function employeeDetails(EntityId $id): array
    {
        $profile = $this->employeeProfile($id);
        return $this->profileDetails($profile);
    }

    /** @return array<string,mixed> */
    public function historyPage(EntityId $id, int $after = 0, ?int $throughVersion = null): array
    {
        $actor = $this->access->requirePermission('sis:hr:manage');
        $this->authorizeHistory($actor);
        $employee = $this->repository->employee($id);
        $person = $this->authorizeRead($employee, $actor);
        $this->peopleHistory->verify($person);
        $page = $this->history->review($employee, $after, $throughVersion);
        $ids = [];
        foreach ($page['items'] as $item) {
            $ids[$item['actor_id']] = new EntityId($item['actor_id']);
        }
        $names = $this->actors->names(array_values($ids));
        foreach ($page['items'] as &$item) {
            $item['recorded_by'] = $names[$item['actor_id']] ?? 'Unavailable account';
            unset($item['actor_id']);
        }
        unset($item);
        $this->authorizeHistory($actor);
        $this->authorizeRead($employee, $actor);
        if (EmploymentFacts::of($this->repository->employee($id)) !== EmploymentFacts::of($employee)) {
            throw new ConcurrentModificationException('Refresh employment history.');
        }
        return $page;
    }

    private function authorizeHistory(EntityId $actor): void
    {
        if (
            $this->access->requirePermission('sis:hr:manage')->value !== $actor->value
            || $this->access->requirePermission('sis:audit:read')->value !== $actor->value
        ) {
            throw new AccessDeniedException('The employment history reviewer changed.');
        }
    }
    /** @return array<string,mixed> */
    private function profileDetails(EmployeeProfile $profile): array
    {
        return ['id' => $profile->employee->id->value, 'person_id' => $profile->person->id->value,
            'given_name' => $profile->person->givenName, 'family_name' => $profile->person->familyName,
            'institutional_id' => $profile->person->institutionalId->value, 'job_title' => $profile->employee->jobTitle,
            'active' => $profile->employee->active, 'revision' => $profile->revision,
            'earlier_history_unknown' => $profile->earlierHistoryUnknown,
            'is_self' => $profile->person->ownerUserId?->value === $this->access->authenticatedUserId()->value];
    }

    public function editEmployee(
        EntityId $id,
        string $jobTitle,
        bool $active,
        string $revision,
        #[\SensitiveParameter] string $reason
    ): void {
        $actor = $this->access->requirePermission('sis:hr:manage');
        $reason = \Domain\HumanResources\ValueObject\DefinitionRules::reason($reason);
        $initial = $this->repository->employee($id);
        $next = new Employee($id, $initial->personId, trim($jobTitle), $active);
        $intent = EmploymentLedger::intent(['edit', EmploymentFacts::of($next),
            $revision, $reason]);
        $operation = EmploymentLedger::operation($id, $actor, $intent);
        $this->maintain($initial->personId, $actor, function () use (
            $id,
            $initial,
            $next,
            $revision,
            $actor,
            $operation,
            $intent,
            $reason
        ): void {
            $this->repository->lockEmployee($id);
            $current = $this->repository->employee($id);
            if ($current->personId->value !== $initial->personId->value) {
                throw new AccessDeniedException('The employment person changed.');
            }
            if ($this->history->receipt($id, $operation, $actor, $intent)) {
                return;
            }
            if (!$this->history->change($current, $next, $revision, $operation, $actor, $intent, $reason)) {
                return;
            }
            $this->repository->saveEmployee($next);
            $this->audit->record($actor, 'employee.updated', $id);
        });
    }

    /** @template T
     * @param \Closure(Employee,EntityId):T $operation
     * @return T
     */
    public function maintainEmployee(EntityId $id, \Closure $operation): mixed
    {
        $actor = $this->access->requirePermission('sis:hr:manage');
        $initial = $this->repository->employee($id);
        return $this->maintain($initial->personId, $actor, function () use ($id, $initial, $actor, $operation): mixed {
            $this->repository->lockEmployee($id);
            $current = $this->repository->employee($id);
            if ($current->personId->value !== $initial->personId->value) {
                throw new AccessDeniedException('The employment person changed.');
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
            throw new ConcurrentModificationException('Reload employment before continuing.');
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
            $this->access->requirePermission('sis:hr:manage')->value !== $actor->value
            || $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException('The HR editor changed.');
        }
        $record = $this->admissions->person($person);
        $this->protectSuper($record, $actor);
        return $record;
    }
    private function authorizeRead(Employee $employee, EntityId $actor): Person
    {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException('The HR reader changed.');
        }
        $person = $this->admissions->person($employee->personId);
        $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:hr:manage');
        if ($person->ownerUserId?->value !== $actor->value) {
            $this->protectSuper($person, $actor);
        }
        return $person;
    }
    private function protectSuper(Person $person, EntityId $actor): void
    {
        if (
            $person->ownerUserId !== null && $this->roles->isSuper($person->ownerUserId->value)
            && !$this->roles->isSuper($actor->value)
        ) {
            throw new AccessDeniedException('This employment requires a super administrator.');
        }
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function entries(EntityId $id, ?EntityId $after = null): array
    {
        $this->employeeProfile($id);
        $rows = $this->repository->entries($id, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);

        return ['items' => $rows, 'next_after' => $more ? (string) $rows[49]['id'] : null];
    }

    /** @return array<string,mixed> */
    public function week(EntityId $id, ?string $date = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $profile = $this->employeeProfile($id);
        $now = $this->clock->now();
        $week = new TimeReviewWeek($date ?? $now->format('Y-m-d'), $now->getTimezone());
        $entries = $this->repository->inWindow($id, new TimeSlot($week->startsAt, $week->endsAt), 501);
        if (count($entries) > 500) {
            throw new ValidationException([['field' => 'week',
                'message' => 'This week exceeds the review limit. Ask HR for a detailed time review.']]);
        }
        foreach ($entries as $entry) {
            if ($entry->employeeId->value !== $id->value) {
                throw new AccessDeniedException('The time review contains another employee.');
            }
        }
        $summary = $week->summarize($entries);
        $this->authorizeRead($profile->employee, $actor);
        return $summary + ['employee' => $this->profileDetails($profile)];
    }

    public function submit(EntityId $employee, TimeSlot $work): TimeEntry
    {
        $this->access->authenticatedUserId();
        $person = $this->admissions->person($this->repository->employeePerson($employee));
        $actor = $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:hr:manage');
        $entry = new TimeEntry(EntityId::generate(), $employee, $work);
        $this->transactions->run(function () use ($employee, $entry, $actor): void {
            $this->repository->lockEmployee($employee);
            $person = $this->admissions->person($this->repository->employeePerson($employee));
            $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:hr:manage');
            $this->employeeProfile($employee);
            $zone = $this->clock->now()->getTimezone();
            $this->employmentDates->requireCoverage(
                $employee,
                $entry->work->startsAt->setTimezone($zone)->format('Y-m-d'),
                $entry->work->endsAt->modify('-1 microsecond')->setTimezone($zone)->format('Y-m-d'),
                'starts_at'
            );
            $this->repository->add($entry);
            $this->audit->record($actor, 'timesheet.submitted', $entry->id);
            $this->authorizeRead($this->repository->employee($employee), $actor);
        });

        return $entry;
    }

    public function decide(EntityId $id, bool $approve, ?int $expectedVersion = null): void
    {
        $actor = $this->access->requirePermission('sis:hr:manage');
        $initial = $this->repository->get($id);
        $this->transactions->run(function () use ($id, $approve, $actor, $initial, $expectedVersion): void {
            $this->repository->lockEmployee($initial->employeeId);
            $entry = $this->repository->get($id);
            if ($expectedVersion !== null && $entry->version !== $expectedVersion) {
                throw new ConcurrentModificationException('Reload the time entry before deciding.');
            }
            $person = $this->employeeProfile($entry->employeeId)->person;
            if ($approve) {
                $zone = $this->clock->now()->getTimezone();
                $this->employmentDates->requireCoverage(
                    $entry->employeeId,
                    $entry->work->startsAt->setTimezone($zone)->format('Y-m-d'),
                    $entry->work->endsAt->modify('-1 microsecond')->setTimezone($zone)->format('Y-m-d'),
                    'starts_at'
                );
            }
            $this->repository->save($entry->decide($approve, $actor, $person->ownerUserId), $entry->version);
            $this->audit->record($actor, $approve ? 'timesheet.approved' : 'timesheet.rejected', $id);
            if ($this->access->requirePermission('sis:hr:manage')->value !== $actor->value) {
                throw new AccessDeniedException('The time reviewer changed.');
            }
        });
    }

    /** @return array{entry:TimeEntry,profile:EmployeeProfile} */
    private function entryProfile(EntityId $id): array
    {
        $this->access->authenticatedUserId();
        $entry = $this->repository->get($id);
        return ['entry' => $entry, 'profile' => $this->employeeProfile($entry->employeeId)];
    }
    public function get(EntityId $id): TimeEntry
    {
        return $this->entryProfile($id)['entry'];
    }
    /** @return array<string,mixed> */
    public function entryDetails(EntityId $id): array
    {
        $data = $this->entryProfile($id);
        $entry = $data['entry'];
        $profile = $this->profileDetails($data['profile']);
        return ['id' => $entry->id->value, 'employee_id' => $entry->employeeId->value,
            'status' => $entry->status, 'version' => $entry->version,
            'institutional_id' => $profile['institutional_id'], 'given_name' => $profile['given_name'],
            'family_name' => $profile['family_name'], 'job_title' => $profile['job_title'],
            'is_self' => $profile['is_self'],
            'duration_seconds' => $entry->work->endsAt->getTimestamp()
                - $entry->work->startsAt->getTimestamp(),
            'starts_at' => $entry->work->startsAt->format(DATE_ATOM),
            'ends_at' => $entry->work->endsAt->format(DATE_ATOM)];
    }
}
