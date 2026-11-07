<?php

declare(strict_types=1);

namespace Domain\Registration;

use DateTimeImmutable;
use Domain\Shared\ValueObject\DateRange;
use InvalidArgumentException;

final readonly class RegistrationWindow
{
    public function __construct(public DateRange $dates, public int $courseLimit)
    {
        if ($dates->end === null || $courseLimit < 1 || $courseLimit > 1000) {
            throw new InvalidArgumentException('A finite registration window and positive course limit are required.');
        }
    }

    public function isOpen(DateTimeImmutable $on): bool
    {
        return $this->dates->contains($on);
    }
}
