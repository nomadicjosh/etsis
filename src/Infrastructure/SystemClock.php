<?php

declare(strict_types=1);

namespace Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\Service\Clock;

final readonly class SystemClock implements Clock
{
    public function __construct(private string $timezone = 'UTC')
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($this->timezone));
    }
}
