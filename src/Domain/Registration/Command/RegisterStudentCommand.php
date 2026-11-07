<?php

declare(strict_types=1);

namespace Domain\Registration\Command;

use Codefy\CommandBus\Command;
use Domain\Shared\ValueObject\EntityId;

final readonly class RegisterStudentCommand implements Command
{
    public function __construct(public EntityId $studentId, public EntityId $sectionId, public bool $byStaff = false)
    {
    }
}
