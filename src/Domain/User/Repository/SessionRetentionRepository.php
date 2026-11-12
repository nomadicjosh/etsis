<?php

declare(strict_types=1);

namespace Domain\User\Repository;

use DateTimeImmutable;
use Domain\User\ValueObject\SessionRetentionSummary;

interface SessionRetentionRepository
{
    public function preview(DateTimeImmutable $cutoff, int $limit): SessionRetentionSummary;
    /** Requires the caller's unit of work; never remove a sign-in while a launch reference remains. */
    public function prune(DateTimeImmutable $cutoff, int $limit): SessionRetentionSummary;
}
