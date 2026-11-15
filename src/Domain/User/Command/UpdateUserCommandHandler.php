<?php

declare(strict_types=1);

namespace Domain\User\Command;

use Codefy\CommandBus\Command;
use Codefy\CommandBus\CommandHandler;
use Codefy\Domain\Aggregate\AggregateNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use Domain\User\Repository\UserAggregateRepository;
use Domain\User\Service\UserAccountAccess;
use Domain\User\User;
use Qubus\ValueObjects\Person\Name;

final readonly class UpdateUserCommandHandler implements CommandHandler
{
    public function __construct(
        public UserAggregateRepository $aggregateRepository,
        private UserAccountAccess $access,
        private \Domain\Person\Repository\PersonAccountRepository $people,
        private \Domain\Person\Repository\PersonProfileRepository $rows,
        private \Domain\Person\Service\ProfileLedger $profiles,
        private \Domain\Shared\Security\StudentAccess $actors,
    ) {
    }

    /**
     * @throws AggregateNotFoundException
     * @throws \Exception
     */
    public function handle(UpdateUserCommand|Command $command): void
    {
        /** @var UpdateUserCommand $command */

        $this->access->update(new EntityId((string) $command->userId), function () use ($command): void {
            $owner = new EntityId((string) $command->userId);
            $person = $this->people->findByUser($owner)
                ?? throw new DomainException('This account needs its person record.');
            $this->rows->lock($person->id);
            $person = $this->people->findByUser($owner)
                ?? throw new DomainException('This account needs its person record.');
            $this->profiles->verify($person);
            $actor = $this->actors->authenticatedUserId();
            $operation = EntityId::generate();
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

            $this->aggregateRepository->saveProfile($user, $this->profiles->accountContext(
                $actor,
                \Domain\Shared\ValueObject\DerivedIdentity::from($operation, 'person.account.update')
            ));
            $after = $this->people->findByUser($owner)
                ?? throw new DomainException('The person record is unavailable.');
            $this->profiles->record(
                $person,
                $after,
                $operation,
                $actor,
                \Domain\Person\Service\ProfileLedger::intent([(string) $command->firstName,
                    (string) $command->lastName])
            );
            $this->access->authorizeUpdate($owner);
            if ($this->actors->authenticatedUserId()->value !== $actor->value) {
                throw new \Domain\Shared\Exception\AccessDeniedException('The profile actor changed.');
            }
        });
    }
}
