<?php

declare(strict_types=1);

namespace Domain\Lms\Repository;

use Domain\Lms\ValueObject\MoodleLaunch;
use Domain\Shared\ValueObject\EntityId;

interface MoodleLaunchRepository
{
    /** @phpstan-impure */
    public function personForOwner(EntityId $user): ?EntityId;
    public function add(MoodleLaunch $launch, #[\SensitiveParameter] string $ticketHash): void;
    /** @phpstan-impure */
    public function forTicket(string $hash): ?MoodleLaunch;
    /** @phpstan-impure */
    public function byId(EntityId $id): ?MoodleLaunch;
    public function consume(EntityId $id): bool;
}
