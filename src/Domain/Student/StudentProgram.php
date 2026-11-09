<?php

declare(strict_types=1);

namespace Domain\Student;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class StudentProgram
{
    public function __construct(
        public EntityId $id,
        public EntityId $studentId,
        public EntityId $programId,
        public EntityId $entryTermId,
        public string $status = 'active',
    ) {
        if (!in_array($status, ['active', 'closed'], true)) {
            throw new InvalidArgumentException('Invalid student program status.');
        }
    }
}
