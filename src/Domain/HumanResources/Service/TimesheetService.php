<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\Dto\EmployeeProfile;
use Domain\HumanResources\Repository\TimeRepository;
use Domain\HumanResources\TimeEntry;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

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
        if (trim($jobTitle) === '' || mb_strlen($jobTitle) > 191) {
            throw new InvalidArgumentException('A job title is required.');
        }
        $id = EntityId::generate();
        $this->transactions->run(function () use ($id, $person, $jobTitle, $actor): void {
            $this->repository->addEmployee($id, $person, $jobTitle);
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

    public function submit(EntityId $employee, TimeSlot $work): TimeEntry
    {
        $this->access->authenticatedUserId();
        $person = $this->admissions->person($this->repository->employeePerson($employee));
        $actor = $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:hr:manage');
        $entry = new TimeEntry(EntityId::generate(), $employee, $work);
        $this->transactions->run(function () use ($employee, $entry, $actor): void {
            $this->repository->lockEmployee($employee);
            $this->repository->employeePerson($employee);
            $this->repository->add($entry);
            $this->audit->record($actor, 'timesheet.submitted', $entry->id);
        });

        return $entry;
    }

    public function decide(EntityId $id, bool $approve): void
    {
        $actor = $this->access->requirePermission('sis:hr:manage');
        $initial = $this->repository->get($id);
        $this->transactions->run(function () use ($id, $approve, $actor, $initial): void {
            $this->repository->lockEmployee($initial->employeeId);
            $entry = $this->repository->get($id);
            $person = $this->admissions->person($this->repository->employeePerson($entry->employeeId));
            $this->repository->save($entry->decide($approve, $actor, $person->ownerUserId), $entry->version);
            $this->audit->record($actor, $approve ? 'timesheet.approved' : 'timesheet.rejected', $id);
        });
    }

    public function get(EntityId $id): TimeEntry
    {
        $this->access->authenticatedUserId();
        $entry = $this->repository->get($id);
        $person = $this->admissions->person($this->repository->employeePerson($entry->employeeId));
        $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:hr:manage');

        return $entry;
    }
}
