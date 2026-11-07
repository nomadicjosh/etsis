<?php

declare(strict_types=1);

namespace Domain\Registration;

use DateTimeImmutable;
use Domain\Registration\Enum\RegistrationStatus;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class Term
{
    public function __construct(
        public EntityId $id,
        public DateRange $dates,
        public DateTimeImmutable $addDropEndsOn,
    ) {
        if ($dates->end === null || !$dates->contains($addDropEndsOn)) {
            throw new InvalidArgumentException('The add/drop deadline must fall within a finite term.');
        }
    }

    public function dropStatusOn(DateTimeImmutable $date): RegistrationStatus
    {
        return $date->format('Y-m-d') <= $this->addDropEndsOn->format('Y-m-d')
        ? RegistrationStatus::Dropped
        : RegistrationStatus::Withdrawn;
    }
}
