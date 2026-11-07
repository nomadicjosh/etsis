<?php

declare(strict_types=1);

namespace Domain\Catalog;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class Program
{
    public function __construct(public EntityId $id, public string $code, public string $title)
    {
        if (
            preg_match('/^[A-Z0-9][A-Z0-9._-]{0,79}$/D', $code) !== 1
            || trim($title) === ''
            || mb_strlen($title) > 191
        ) {
            throw new InvalidArgumentException('A program code and title are required.');
        }
    }
}
