<?php

declare(strict_types=1);

namespace Domain\Shared\ValueObject;

use InvalidArgumentException;
use Qubus\ValueObjects\Identity\Ulid;
use Stringable;

final readonly class EntityId implements Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $value) !== 1) {
            throw new InvalidArgumentException('A canonical ULID is required.');
        }
    }

    public static function generate(): self
    {
        return new self(Ulid::generateAsString());
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
