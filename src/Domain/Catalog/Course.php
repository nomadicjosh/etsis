<?php

declare(strict_types=1);

namespace Domain\Catalog;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class Course
{
    public function __construct(
        public EntityId $id,
        public string $code,
        public string $title,
        public int $creditsMilli,
        public bool $active = true,
    ) {
        if (
            trim($code) === '' || strlen($code) > 80 || trim($title) === '' || mb_strlen($title) > 191
            || $creditsMilli < 0 || $creditsMilli > 999_999
        ) {
            throw new InvalidArgumentException('Valid course details and nonnegative credit thousandths are required.');
        }
    }
}
