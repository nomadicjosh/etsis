<?php

declare(strict_types=1);

namespace Domain\Student;

use Domain\Shared\ValueObject\EntityId;

final readonly class Student
{
    public function __construct(
        public EntityId $id,
        public EntityId $personId,
        public EntityId $programId,
        public EntityId $entryTermId,
        public bool $active = true,
    ) {
    }
}
