<?php

declare(strict_types=1);

namespace Domain\User\Repository;

use Codefy\Domain\Aggregate\AggregateRepository;

interface UserAggregateRepository extends AggregateRepository
{
    public function saveProfile(\Domain\User\User $user, \Domain\Shared\EventSourcing\EventContext $context): void;
}
