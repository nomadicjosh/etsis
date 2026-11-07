<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domain\HumanResources\Employee;
use Domain\HumanResources\Repository\TimeRepository;
use Domain\HumanResources\TimeEntry;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlTimeRepository implements TimeRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function addEmployee(EntityId $id, EntityId $person, string $jobTitle): void
    {
        $this->sql->insert('sis_employees', ['id' => $id->value, 'person_id' => $person->value,
            'job_title' => $jobTitle, 'active' => 1]);
    }

    public function employee(EntityId $id): Employee
    {
        $row = $this->sql->one('sis_employees', ['id' => $id->value]);

        return new Employee($id, new EntityId($row['person_id']), $row['job_title'], (bool) $row['active']);
    }

    public function employeePerson(EntityId $employee): EntityId
    {
        $row = $this->sql->one('sis_employees', ['id' => $employee->value]);
        if (!(bool) $row['active']) {
            throw new DomainException('The employee is inactive.');
        }

        return new EntityId($row['person_id']);
    }

    public function lockEmployee(EntityId $employee): void
    {
        $this->sql->lock('sis_employees', $employee->value, 'active');
    }

    public function add(TimeEntry $entry): void
    {
        $q = $this->sql->quote(...);
        $utc = new DateTimeZone('UTC');
        if (
            $this->sql->fetch('SELECT ' . $q('id') . ' FROM ' . $q('sis_timesheets')
            . ' WHERE ' . $q('employee_id') . ' = ? AND ' . $q('status') . ' <> ? AND '
            . $q('starts_at') . ' < ? AND ' . $q('ends_at') . ' > ?', [$entry->employeeId->value, 'rejected',
                $entry->work->endsAt->setTimezone($utc)->format('Y-m-d H:i:s'),
                $entry->work->startsAt->setTimezone($utc)->format('Y-m-d H:i:s')], 1) !== []
        ) {
            throw new DomainException('Time entries cannot overlap.');
        }
        $this->sql->insert('sis_timesheets', ['id' => $entry->id->value, 'employee_id' => $entry->employeeId->value,
            'starts_at' => $entry->work->startsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'ends_at' => $entry->work->endsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'status' => $entry->status, 'approved_by' => null, 'version' => 0]);
    }

    public function get(EntityId $id): TimeEntry
    {
        $r = $this->sql->one('sis_timesheets', ['id' => $id->value]);

        return new TimeEntry(
            $id,
            new EntityId($r['employee_id']),
            new TimeSlot(
                new DateTimeImmutable($r['starts_at'], new DateTimeZone('UTC')),
                new DateTimeImmutable($r['ends_at'], new DateTimeZone('UTC'))
            ),
            $r['status'],
            $r['approved_by'] === null ? null : new EntityId($r['approved_by']),
            (int) $r['version']
        );
    }

    public function save(TimeEntry $entry, int $expectedVersion): void
    {
        if (
            $this->sql->update(
                'sis_timesheets',
                ['status' => $entry->status,
                'approved_by' => $entry->approvedBy?->value, 'version' => $entry->version],
                ['id' => $entry->id->value, 'version' => $expectedVersion]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('The time entry changed.');
        }
    }
}
