<?php

declare(strict_types=1);

namespace Domain\Admissions\Command;

use Codefy\CommandBus\Command;
use Domain\Shared\ValueObject\EntityId;

final readonly class MatriculateStudentCommand implements Command
{
    public function __construct(
        public EntityId $applicationId,
        #[\SensitiveParameter] public string $reviewToken,
    ) {
    }
}
