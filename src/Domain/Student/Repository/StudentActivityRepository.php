<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\StudentActivity;

interface StudentActivityRepository
{
    /** @return array{person_id:string,active:bool} */
    public function current(EntityId $student): array;
    /** @return array{stream_version:int,last_decision_id:string,current_decision_id:string,effective_on:string}|null */
    public function marker(EntityId $student): ?array;
    public function project(EntityId $student, StudentActivity $root, bool $before, int $expected): void;
}
