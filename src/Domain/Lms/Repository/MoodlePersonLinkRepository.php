<?php

declare(strict_types=1);

namespace Domain\Lms\Repository;

use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodlePersonLink;
use Domain\Shared\ValueObject\EntityId;

interface MoodlePersonLinkRepository
{
    /** @phpstan-impure */
    public function forPerson(EntityId $binding, EntityId $person): ?MoodlePersonLink;
    /** @phpstan-impure */
    public function forExternal(EntityId $binding, MoodleExternalId $external): ?MoodlePersonLink;
    public function add(MoodlePersonLink $link, #[\SensitiveParameter] string $encryptedReview): void;
}
