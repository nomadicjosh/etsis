<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\Exception\ValidationException;
use InvalidArgumentException;

/** Exact academic-credit thousandths. This policy never represents or converts CEUs. */
final readonly class CreditPolicy
{
    public function __construct(public int $minimumMilli, public int $maximumMilli, public int $incrementMilli)
    {
        if (
            $minimumMilli < 0 || $maximumMilli > 999999 || $maximumMilli < $minimumMilli
            || ($minimumMilli === $maximumMilli ? $incrementMilli !== 0
                : $incrementMilli < 1 || ($maximumMilli - $minimumMilli) % $incrementMilli !== 0)
        ) {
            throw new ValidationException([['field' => 'credit_policy',
                'message' => 'Use a fixed credit value or an aligned minimum, maximum and increment.']]);
        }
    }

    public static function fixed(int $credits): self
    {
        return new self($credits, $credits, 0);
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        if (
            count($values) !== 3
            || array_diff(array_keys($values), ['minimum_milli', 'maximum_milli', 'increment_milli'])
            || !is_int($values['minimum_milli'] ?? null) || !is_int($values['maximum_milli'] ?? null)
            || !is_int($values['increment_milli'] ?? null)
        ) {
            throw new InvalidArgumentException('Use the complete credit policy.');
        }
        return new self($values['minimum_milli'], $values['maximum_milli'], $values['increment_milli']);
    }

    public static function stored(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }
        if (strlen($value) > 512) {
            throw new InvalidArgumentException('Use a bounded credit policy.');
        }
        return self::fromArray(json_decode($value, true, 4, JSON_THROW_ON_ERROR));
    }

    public function requireChoice(int $credits, string $field = 'credits_milli'): void
    {
        if (
            $credits < $this->minimumMilli || $credits > $this->maximumMilli
            || ($this->incrementMilli > 0 && ($credits - $this->minimumMilli) % $this->incrementMilli !== 0)
        ) {
            throw new ValidationException([['field' => $field,
                'message' => 'Choose credits within the course minimum, maximum and increment.']]);
        }
    }

    /** @return array{minimum_milli:int, maximum_milli:int, increment_milli:int} */
    public function describe(): array
    {
        return ['minimum_milli' => $this->minimumMilli, 'maximum_milli' => $this->maximumMilli,
            'increment_milli' => $this->incrementMilli];
    }
}
