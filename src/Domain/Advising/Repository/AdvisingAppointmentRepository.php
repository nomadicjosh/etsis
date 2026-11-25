<?php

declare(strict_types=1);

namespace Domain\Advising\Repository;

use Domain\Advising\AdvisingAppointment;
use Domain\Advising\AppointmentTime;
use Domain\Shared\ValueObject\EntityId;

interface AdvisingAppointmentRepository
{
    public function find(EntityId $student, EntityId $pursuit, EntityId $id): ?AdvisingAppointment;
    /** @return list<AdvisingAppointment> */
    public function page(
        EntityId $student,
        EntityId $pursuit,
        ?EntityId $owner,
        bool $finished,
        ?EntityId $after,
    ): array;
    public function activeCount(EntityId $student, EntityId $pursuit): int;
    public function conflict(EntityId $student, EntityId $host, EntityId $except, AppointmentTime $time): bool;
    public function save(AdvisingAppointment $item, ?int $expectedVersion): void;
}
