<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Retained price inputs for one accepted registration, independent of today's course or rate. */
final readonly class RegistrationPrice
{
    public Money $amount;

    public function __construct(
        public EntityId $enrollment,
        public EntityId $rule,
        public string $name,
        public int $creditsMilli,
        public Money $rate,
    ) {
        if (
            trim($name) === "" ||
            mb_strlen($name) > 191 ||
            $creditsMilli < 0 ||
            $creditsMilli > 999999 ||
            $rate->minor < 1
        ) {
            throw new InvalidArgumentException(
                "A credit price requires a recorded rate and valid registration credits.",
            );
        }
        // Round each registration once, to the nearest currency minor unit, with halves rounded up.
        $this->amount = self::amountForCredits($creditsMilli, $rate);
    }

    public static function amountForCredits(int $creditsMilli, Money $rate): Money
    {
        if ($creditsMilli < 0 || $creditsMilli > 999999 || $rate->minor < 1) {
            throw new InvalidArgumentException(
                "A recorded rate and valid registration credits are required.",
            );
        }
        return new Money(intdiv($rate->minor * $creditsMilli + 500, 1000), $rate->currency);
    }

    /** @return array{enrollment_id:string,rule_id:string,name:string,credits_milli:int,rate_minor:int,currency:string,rounding:string} */
    public function values(): array
    {
        return [
            "enrollment_id" => $this->enrollment->value,
            "rule_id" => $this->rule->value,
            "name" => $this->name,
            "credits_milli" => $this->creditsMilli,
            "rate_minor" => $this->rate->minor,
            "currency" => $this->rate->currency,
            "rounding" => "half_up",
        ];
    }

    /** @param array<mixed> $values */
    public static function fromRecorded(array $values): self
    {
        if (
            count($values) !== 7 ||
            !is_string($values["enrollment_id"] ?? null) ||
            !is_string($values["rule_id"] ?? null) ||
            !is_string($values["name"] ?? null) ||
            !is_int($values["credits_milli"] ?? null) ||
            !is_int($values["rate_minor"] ?? null) ||
            !is_string($values["currency"] ?? null) ||
            ($values["rounding"] ?? null) !== "half_up"
        ) {
            throw new InvalidArgumentException("Invalid recorded registration price.");
        }
        return new self(
            new EntityId($values["enrollment_id"]),
            new EntityId($values["rule_id"]),
            $values["name"],
            $values["credits_milli"],
            new Money($values["rate_minor"], $values["currency"]),
        );
    }
}
