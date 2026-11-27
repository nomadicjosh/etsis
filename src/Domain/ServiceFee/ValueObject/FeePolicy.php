<?php

declare(strict_types=1);

namespace Domain\ServiceFee\ValueObject;

use Domain\Finance\ValueObject\Money;
use InvalidArgumentException;

final readonly class FeePolicy
{
    public function __construct(
        public int $revision,
        public int $minor,
        public string $currency,
        public bool $waivers,
    ) {
        if (
            $revision < 0 ||
            $revision > 2000000000 ||
            $minor < 0 ||
            $minor > 2000000000
        ) {
            throw new InvalidArgumentException(
                "Use a valid fee policy and amount.",
            );
        }
        new Money($minor, $currency);
        if (
            !new \Money\Currencies\ISOCurrencies()->contains(
                new \Money\Currency($currency),
            )
        ) {
            throw new InvalidArgumentException(
                "Choose a supported fee currency.",
            );
        }
    }
    public function values(): array
    {
        return [
            "revision" => $this->revision,
            "minor" => $this->minor,
            "currency" => $this->currency,
            "waivers" => $this->waivers,
        ];
    }
    public static function from(array $p): self
    {
        FeeSchema::keys($p, ["revision", "minor", "currency", "waivers"]);
        if (
            !is_int($p["revision"]) ||
            !is_int($p["minor"]) ||
            !is_string($p["currency"]) ||
            !is_bool($p["waivers"])
        ) {
            throw new InvalidArgumentException("Use a valid fee policy.");
        }
        return new self(
            $p["revision"],
            $p["minor"],
            $p["currency"],
            $p["waivers"],
        );
    }
}
