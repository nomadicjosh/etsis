<?php

declare(strict_types=1);

namespace Domain\Gradebook;

use Domain\Shared\ValueObject\EntityId;

final readonly class AssignmentScore
{
    public function __construct(
        public EntityId $assignmentId,
        public EntityId $studentId,
        public ?int $earnedHundredths,
        public int $possibleHundredths,
        public int $bonusHundredths = 0,
    ) {
        new \Domain\Gradebook\ValueObject\AssignmentPoints(
            $possibleHundredths,
            $bonusHundredths,
        )->assertEarned($earnedHundredths);
    }

    /** Ungraded is distinct from an earned zero. Returns hundredths of a percent. */
    public function percentageHundredths(): ?int
    {
        return $this->earnedHundredths === null
        ? null
        : intdiv(
            $this->earnedHundredths * 10000 + intdiv($this->possibleHundredths, 2),
            $this->possibleHundredths,
        );
    }
}
