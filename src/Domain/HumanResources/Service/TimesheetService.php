<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\Dto\EmployeeProfile;
use Domain\HumanResources\Employee;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\HumanResources\Repository\TimeRepository;
use Domain\HumanResources\TimeEntry;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class TimesheetService
{
    public function __construct(
        private TimeRepository $repository,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    public function addEmployee(EntityId $person, string $jobTitle): EntityId
    {
        $actor = $this->access->requirePermission('sis:hr:manage');
        $this->admissions->person($person);
        $id = EntityId::generate();
        $employee = new Employee($id, $person, trim($jobTitle), true);
        $this->transactions->run(function () use ($employee, $actor, $id, $person): void {
            $this->repository->addEmployee($id, $person, $employee->jobTitle);
            $this->audit->record($actor, 'employee.created', $id);
        });

        return $id;
    }

    public function employeeProfile(EntityId $id): EmployeeProfile
    {
        $this->access->authenticatedUserId();
        $employee = $this->repository->employee($id);
        $person = $this->admissions->person($employee->personId);
        $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:hr:manage');

        return new EmployeeProfile($employee, $person);
    }

    /** @return array<string, mixed> */
    public function employeeDetails(EntityId $id): array
    {
        $profile = $this->employeeProfile($id);
        $employee = $profile->employee;

        return ['id' => $id->value, 'person_id' => $employee->personId->value,
            'given_name' => $profile->person->givenName, 'family_name' => $profile->person->familyName,
            'institutional_id' => $profile->person->institutionalId->value, 'job_title' => $employee->jobTitle,
            'active' => $employee->active, 'revision' => $this->revision($employee),
            'is_self' => $profile->person->ownerUserId?->value === $this->access->authenticatedUserId()->value];
    }

    public function editEmployee(EntityId $id, string $jobTitle, bool $active, string $revision): void
    {
        $actor = $this->access->requirePermission('sis:hr:manage');
        $this->transactions->run(function () use ($id, $jobTitle, $active, $revision, $actor): void {
            $this->repository->lockEmployee($id);
            $current = $this->repository->employee($id);
            if (!hash_equals($this->revision($current), $revision)) {
                throw new ConcurrentModificationException('Reload employment before editing.');
            }
            $this->repository->saveEmployee(new Employee($id, $current->personId, trim($jobTitle), $active));
            $this->audit->record($actor, 'employee.updated', $id);
        });
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

    private function revision(Employee $employee): string
    {
        return hash('sha256', json_encode([$employee->id->value, $employee->personId->value,
            $employee->jobTitle, $employee->active], JSON_THROW_ON_ERROR));
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
            $this->repository->add($entry);
            $this->audit->record($actor, 'timesheet.submitted', $entry->id);
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
            $person = $this->admissions->person($this->repository->employee($entry->employeeId)->personId);
            $this->repository->save($entry->decide($approve, $actor, $person->ownerUserId), $entry->version);
            $this->audit->record($actor, $approve ? 'timesheet.approved' : 'timesheet.rejected', $id);
        });
    }

    public function get(EntityId $id): TimeEntry
    {
        $this->access->authenticatedUserId();
        $entry = $this->repository->get($id);
        $person = $this->admissions->person($this->repository->employee($entry->employeeId)->personId);
        $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:hr:manage');

        return $entry;
    }
}
