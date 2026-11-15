<?php

declare(strict_types=1);

namespace Domain\User\Command;

use Codefy\CommandBus\Command;
use Codefy\CommandBus\CommandHandler;

final readonly class CreateUserCommandHandler implements CommandHandler
{
    public function __construct(
        private \Domain\Person\Service\PersonRegistrationService $registration,
        private \Domain\Shared\Security\StudentAccess $access,
    ) {
    }
    public function handle(CreateUserCommand|Command $command): void
    {
        /** @var CreateUserCommand $command */
        $creator = null;
        try {
            $creator = $this->access->authenticatedUserId();
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            // Anonymous registration creates only a basic account.
        }
        if ($creator !== null) {
            $this->access->requirePermission('admin:create:user');
        }
        $this->registration->register(
            \Domain\Shared\ValueObject\EntityId::generate(),
            (string) $command->firstName,
            (string) $command->lastName,
            (string) $command->email,
            (string) $command->username,
            (string) $command->password,
            $creator,
            (string) $command->middleName
        );
    }
}
