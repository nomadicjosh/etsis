<?php

declare(strict_types=1);

namespace Domain\Gradebook;

use DateTimeImmutable;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class Assignment
{
    public function __construct(
        public EntityId $id,
        public EntityId $sectionId,
        public string $title,
        public int $possibleHundredths,
        public DateTimeImmutable $dueOn,
        public int $bonusHundredths = 0,
    ) {
        if (trim($title) === "" || mb_strlen($title) > 191) {
            throw new InvalidArgumentException("An assignment requires a title and positive maximum score.");
        }
        new \Domain\Gradebook\ValueObject\AssignmentPoints($possibleHundredths, $bonusHundredths);
    }
}
