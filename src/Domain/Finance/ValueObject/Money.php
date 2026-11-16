<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use DomainException;
use InvalidArgumentException;

final readonly class Money
{
    // Bound persisted values and sums to signed 32-bit portable database integers.
    public const int MAX_MINOR = 2_000_000_000;

    public function __construct(public int $minor, public string $currency)
    {
        if (($minor < -self::MAX_MINOR || $minor > self::MAX_MINOR) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('A supported amount and uppercase currency code are required.');
        }
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new DomainException('Currencies must match.');
        }

        return new self($this->minor + $other->minor, $this->currency);
    }

    /** Exact ISO decimal representation; unknown precision remains unknown. */
    public function decimal(): ?string
    {
        if ($this->currency === '') {
            return null;
        }
        $currencies = new \Money\Currencies\ISOCurrencies();
        $currency = new \Money\Currency($this->currency);
        if (!$currencies->contains($currency)) {
            return null;
        }
        return new \Money\Formatter\DecimalMoneyFormatter($currencies)->format(
            new \Money\Money((string) $this->minor, $currency)
        );
    }
}
