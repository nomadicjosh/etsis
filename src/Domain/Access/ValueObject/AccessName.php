<?php

declare(strict_types=1);

namespace Domain\Access\ValueObject;

use InvalidArgumentException;
use Qubus\ValueObjects\StringLiteral\StringLiteral;

/** Stable identifier syntax; database services validate definitions and assignments. */
final class AccessName extends StringLiteral
{
    public function __construct(string $value)
    {
        if (preg_match('/^[a-z][a-z0-9:_-]{0,79}$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'Use a lowercase access name of at most 80 letters, digits, colons, underscores or hyphens.'
            );
        }
        parent::__construct($value);
    }
}
