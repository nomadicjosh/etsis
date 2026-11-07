<?php

declare(strict_types=1);

namespace Domain\Admissions\Command;

use Codefy\CommandBus\Command;
use Codefy\CommandBus\CommandHandler;
use Domain\Admissions\Service\AdmissionsService;
use InvalidArgumentException;

final readonly class MatriculateStudentCommandHandler implements CommandHandler
{
    public function __construct(private AdmissionsService $service)
    {
    }

    public function handle(Command $command): void
    {
        if (!$command instanceof MatriculateStudentCommand) {
            throw new InvalidArgumentException('Unsupported command.');
        }
        $this->service->matriculate($command->applicationId);
    }
}
