<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\StudentProgramStatus;
use Domain\Student\StudentProgram;

interface StudentProgramStatusRepository
{
    /** @return array{id:string,student_id:string,stream_version:int,last_decision_id:string}|null */
    public function marker(EntityId $assignment): ?array;
    public function project(StudentProgramStatus $root, string $before, int $expected): void;
    /** @return list<StudentProgram> */
    public function forProgram(EntityId $student, EntityId $program, ?EntityId $after): array;
}
