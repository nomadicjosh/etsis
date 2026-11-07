<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

use InvalidArgumentException;
use Stringable;

/** Human-facing identity. It is never an authentication token or a relationship key. */
final readonly class InstitutionalId implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'Institutional IDs allow 1-32 letters, digits, dots, dashes or underscores.',
            );
        }
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
