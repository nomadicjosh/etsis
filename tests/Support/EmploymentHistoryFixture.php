<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Domain\HumanResources\JobDefinition;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;
use SisFixture;

final class EmploymentHistoryFixture
{
    public readonly SisFixture $sis;
    public readonly EntityId $employee;
    public readonly EntityId $position;
    public readonly EntityId $entry;
    public readonly EntityId $owner;

    public function __construct(?SisFixture $sis = null)
    {
        require_once __DIR__ . "/SisFixture.php";
        $s = $this->sis = $sis ?? new SisFixture();
        $this->owner = $s->user();
        $student = $s->student($this->owner);
        $this->employee = $s
            ->timesheetService()
            ->addEmployee($student->personId, "Fictional private assistant");
        $job = new JobDefinition(
            EntityId::generate(),
            "PRIVATE",
            "Fictional retained appointment",
            "USD",
            "hourly",
            true,
        );
        $s->hrLibrary()->save($job, null);
        $this->position = EntityId::generate();
        $s->positions()->assign(
            $this->employee,
            $this->position,
            $job->id,
            0,
            null,
            "2026-08-01",
            null,
            4321,
            1234,
            "Private fictional agreement reason.",
        );
        $this->designate($this->position);
        $policy = $s->timeApprovalPolicies();
        $current = $policy->view();
        $review = $policy->review(
            array_replace($current["rules"], [
                "require_primary_position" => true,
            ]),
            $current["version"],
            "Private fictional policy reason.",
        );
        $policy->publish(
            new EntityId($review["publication_id"]),
            $review["rules"],
            $review["version"],
            $review["reason"],
            $review["review_token"],
        );
        $entry = $s
            ->timesheetService()
            ->submit(
                $this->employee,
                new TimeSlot(
                    new DateTimeImmutable("2026-08-10T09:00:00Z"),
                    new DateTimeImmutable("2026-08-10T10:00:00Z"),
                ),
                $this->position,
                1,
            );
        $this->entry = $entry->id;
        $s->access->actor = $s->user();
        $revision = $s->timesheetService()->entryDetails($entry->id)[
        "time_review"
        ]["revision"];
        $s->timesheetService()->decide($entry->id, true, 0, $revision);
    }

    public function designate(
        ?EntityId $position,
        ?int $positionVersion = 1,
    ): void {
        $service = $this->sis->primaryPositions();
        $current = $service->get($this->employee);
        $args = [
            $this->employee,
            EntityId::generate(),
            $current["version"],
            $position,
            $position === null ? null : $positionVersion,
            "Private fictional primary reason.",
        ];
        $review = $service->review(...$args);
        $service->confirm(...[...$args, $review["review_token"]]);
    }

    public function facts(): array
    {
        return array_map($this->sis->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_persons",
            "users",
            "sis_students",
            "sis_employees",
            "sis_employee_positions",
            "sis_primary_positions",
            "sis_timesheets",
            "sis_timesheet_positions",
            "sis_time_policy_state",
        ]);
    }
}
