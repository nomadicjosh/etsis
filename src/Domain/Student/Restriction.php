<?php

declare(strict_types=1);

namespace Domain\Student;

use DateTimeImmutable;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class Restriction
{
    public function __construct(
        public EntityId $id,
        public EntityId $personId,
        public string $code,
        public int $severity,
        public DateRange $dates,
        public bool $blocksStaffRegistration = false,
    ) {
        if (trim($code) === '' || strlen($code) > 22 || $severity < 0) {
            throw new InvalidArgumentException('A valid restriction code and severity are required.');
        }
    }

    public function blocksRegistration(DateTimeImmutable $on, bool $byStaff): bool
    {
        return $this->dates->contains($on)
        && ($byStaff ? $this->blocksStaffRegistration : $this->severity >= 99);
    }
}
