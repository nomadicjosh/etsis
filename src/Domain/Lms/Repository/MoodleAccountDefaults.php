<?php

declare(strict_types=1);

namespace Domain\Lms\Repository;

use Domain\Shared\ValueObject\EntityId;

interface MoodleAccountDefaults
{
    /** @return array{username:string,email:string} */
    public function forOwner(?EntityId $owner): array;
}
