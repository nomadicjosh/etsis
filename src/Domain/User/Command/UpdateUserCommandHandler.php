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
use Qubus\ValueObjects\Person\Name;

final readonly class UpdateUserCommandHandler implements CommandHandler
{
    public function __construct(public UserAggregateRepository $aggregateRepository, private UserAccountAccess $access)
    {
    }

    /**
     * @throws AggregateNotFoundException
     * @throws \Exception
     */
    public function handle(UpdateUserCommand|Command $command): void
    {
        /** @var UpdateUserCommand $command */

        $this->access->update(new EntityId((string) $command->userId), function () use ($command): void {
            /** @var User $user */
            $user = $this->aggregateRepository->loadAggregateRoot(aggregateId: $command->userId);

            $user->changeEmailAddress(emailAddress: $command->email);

            $user->changeName(
                name: new Name(
                    firstName: $command->firstName,
                    middleName: $command->middleName,
                    lastName: $command->lastName
                )
            );

            $this->aggregateRepository->saveAggregateRoot(aggregate: $user);
        });
    }
}
