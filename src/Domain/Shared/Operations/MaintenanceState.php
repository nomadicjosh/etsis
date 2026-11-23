<?php

declare(strict_types=1);

namespace Domain\Shared\Operations;

use DateTimeImmutable;
use InvalidArgumentException;

/** Operational admission state, never an academic fact or a database write lock. */
final readonly class MaintenanceState
{
    private function __construct(
        public string $status,
        public int $retryAfter,
        public ?DateTimeImmutable $startedAt,
    ) {
    }

    public static function up(): self
    {
        return new self('up', 300, null);
    }

    public static function down(int $retryAfter, DateTimeImmutable $startedAt): self
    {
        if ($retryAfter < 30 || $retryAfter > 3600) {
            throw new InvalidArgumentException('Use a retry interval from 30 through 3600 seconds.');
        }
        return new self('down', $retryAfter, $startedAt);
    }

    public static function unavailable(): self
    {
        return new self('unavailable', 300, null);
    }

    public function paused(): bool
    {
        return $this->status !== 'up';
    }
}
