<?php

declare(strict_types=1);

namespace Domain\Gradebook;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class AssignmentScore
{
    public function __construct(
        public EntityId $assignmentId,
        public EntityId $studentId,
        public ?int $earnedHundredths,
        public int $possibleHundredths,
    ) {
        if (
            $possibleHundredths < 1
            || $possibleHundredths > 1_000_000
            || ($earnedHundredths !== null && ($earnedHundredths < 0 || $earnedHundredths > $possibleHundredths))
        ) {
            throw new InvalidArgumentException('A grade must fall between zero and the assignment maximum.');
        }
    }

    /** Ungraded is distinct from an earned zero. Returns hundredths of a percent. */
    public function percentageHundredths(): ?int
    {
        return $this->earnedHundredths === null
        ? null
        : intdiv($this->earnedHundredths * 10000 + intdiv($this->possibleHundredths, 2), $this->possibleHundredths);
    }
}
