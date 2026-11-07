<?php

declare(strict_types=1);

namespace Domain\Gradebook;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class GradeDefinition
{
    public function __construct(
        public EntityId $id,
        public string $code,
        public int $pointsMilli,
        public bool $passes,
        public bool $countsGpa,
    ) {
        if (preg_match('/^[A-Z0-9+.-]{1,12}$/D', $code) !== 1 || $pointsMilli < 0 || $pointsMilli > 10000) {
            throw new InvalidArgumentException('A grade code and valid scaled grade points are required.');
        }
    }
}
