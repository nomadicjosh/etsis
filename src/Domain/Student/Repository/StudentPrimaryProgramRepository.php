<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Student;
use Domain\Student\StudentPrimaryProgram;

interface StudentPrimaryProgramRepository
{
    /** @return array{id:string,assignment_id:string,stream_version:int,last_decision_id:string}|null */
    public function marker(EntityId $student): ?array;
    public function track(StudentPrimaryProgram $root): void;
    public function project(StudentPrimaryProgram $root, Student $before, int $expected): void;
}
