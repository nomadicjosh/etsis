<?php

declare(strict_types=1);

namespace Domain\Registration\Command;

use Codefy\CommandBus\Command;
use Codefy\CommandBus\CommandHandler;
use Domain\Registration\Service\RegistrationService;
use InvalidArgumentException;

final readonly class RegisterStudentCommandHandler implements CommandHandler
{
    public function __construct(private RegistrationService $service)
    {
    }

    public function handle(Command $command): void
    {
        if (!$command instanceof RegisterStudentCommand) {
            throw new InvalidArgumentException('Unsupported command.');
        }
        $this->service->register($command->studentId, $command->sectionId, $command->byStaff);
    }
}
