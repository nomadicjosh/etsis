<?php

declare(strict_types=1);

namespace Domain\Registration;

use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class Section
{
    public function __construct(
        public EntityId $id,
        public EntityId $courseId,
        public EntityId $termId,
        public ?EntityId $facultyUserId,
        public string $code,
        public int $capacity,
        public bool $active,
        public bool $webRegistration,
        public Money $fee,
    ) {
        if (trim($code) === '' || strlen($code) > 80 || $capacity < 1 || $capacity > 100000 || $fee->minor < 0) {
            throw new InvalidArgumentException('A section requires a code, positive capacity and nonnegative fees.');
        }
    }
}
