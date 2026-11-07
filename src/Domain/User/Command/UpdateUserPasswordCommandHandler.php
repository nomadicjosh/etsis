<?php

declare(strict_types=1);

namespace Domain\User\Command;

use Codefy\CommandBus\Command;
use Codefy\CommandBus\CommandHandler;
use Codefy\Domain\Aggregate\AggregateNotFoundException;
use Domain\User\Repository\UserAggregateRepository;
use Domain\User\User;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\UserAccountAccess;

final readonly class UpdateUserPasswordCommandHandler implements CommandHandler
{
    public function __construct(public UserAggregateRepository $aggregateRepository, private UserAccountAccess $access)
    {
    }

    /**
     * @throws AggregateNotFoundException
     * @throws \Exception
     */
    public function handle(UpdateUserPasswordCommand|Command $command): void
    {
        /** @var UpdateUserPasswordCommand $command */

        $this->access->update(new EntityId((string) $command->userId), function () use ($command): void {
            /** @var User $user */
            $user = $this->aggregateRepository->loadAggregateRoot(aggregateId: $command->userId);

            $user->changePassword(password: $command->password, token: $command->token);

            $this->aggregateRepository->saveAggregateRoot(aggregate: $user);
        });
    }
}
