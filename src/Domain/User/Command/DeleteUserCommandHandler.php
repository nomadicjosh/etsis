<?php

declare(strict_types=1);

namespace Domain\User\Command;

use Codefy\CommandBus\Command;
use Codefy\CommandBus\CommandHandler;
use Codefy\Domain\Aggregate\AggregateNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\UserAggregateRepository;
use Domain\User\Service\UserAccountAccess;
use Domain\User\User;

final readonly class DeleteUserCommandHandler implements CommandHandler
{
    public function __construct(public UserAggregateRepository $aggregateRepository, private UserAccountAccess $access)
    {
    }

    /**
     * @throws AggregateNotFoundException
     * @throws \Exception
     */
    public function handle(DeleteUserCommand|Command $command): void
    {
        /** @var DeleteUserCommand $command */

        $this->access->delete(new EntityId((string) $command->userId), function () use ($command): void {
            /** @var User $user */
            $user = $this->aggregateRepository->loadAggregateRoot(aggregateId: $command->userId);

            $user->deleteUser($command->userId);

            $this->aggregateRepository->saveAggregateRoot(aggregate: $user);
        });
    }
}
