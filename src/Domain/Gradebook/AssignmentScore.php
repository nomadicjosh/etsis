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
        public bool $excused = false,
    ) {
        new \Domain\Gradebook\ValueObject\AssignmentPoints(
            $possibleHundredths,
            $bonusHundredths,
        )->assertEarned($earnedHundredths);
    }

    /** Ungraded, excused and zero-base bonus work have no standalone percentage; marks remain recorded. */
    public function percentageHundredths(): ?int
    {
        return $this->excused || $this->earnedHundredths === null || $this->possibleHundredths === 0
        ? null
        : intdiv(
            $this->earnedHundredths * 10000 + intdiv($this->possibleHundredths, 2),
            $this->possibleHundredths,
        );
    }
}
