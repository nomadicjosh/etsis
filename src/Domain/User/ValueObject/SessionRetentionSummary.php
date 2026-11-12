<?php

declare(strict_types=1);

namespace Domain\User\ValueObject;

use DateTimeImmutable;

/** Operator-visible aggregate counts only; no account, cookie, ticket or sign-in identifiers. */
final readonly class SessionRetentionSummary
{
    public function __construct(
        public DateTimeImmutable $cutoff,
        public bool $applied,
        public int $launches,
        public int $sessions,
        public int $referenced,
        public int $invalid,
        public int $changed,
        public bool $batchFull,
    ) {
    }
}
