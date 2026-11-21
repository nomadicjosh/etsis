<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Typical weekly instructional contact, independent of credit awards, clocked work and compensation. */
final readonly class ContactHours
{
    public function __construct(public array $data)
    {
        $keys = array_keys($data);
        sort($keys);
        if (
            $keys !== [
                "policy_publication_id",
                "policy_version",
                "rounding",
                "unit_minutes",
                "weekly_contact_milli",
                "weekly_instruction_minutes",
            ] ||
            !is_int($data["weekly_instruction_minutes"]) ||
            $data["weekly_instruction_minutes"] < 0 ||
            $data["weekly_instruction_minutes"] > 10080 ||
            !is_int($data["unit_minutes"]) ||
            $data["unit_minutes"] < 1 ||
            $data["unit_minutes"] > 120 ||
            !is_int($data["policy_version"]) ||
            $data["policy_version"] < 0 ||
            ($data["policy_publication_id"] === null) !== ($data["policy_version"] === 0) ||
            !in_array($data["rounding"], ["nearest", "down", "up"], true) ||
            !is_int($data["weekly_contact_milli"]) ||
            $data["weekly_contact_milli"] !==
                self::calculate(
                    $data["weekly_instruction_minutes"],
                    $data["unit_minutes"],
                    $data["rounding"],
                )
        ) {
            throw new InvalidArgumentException(
                "Use a complete weekly contact-hours calculation and retained policy.",
            );
        }
        if ($data["policy_publication_id"] !== null) {
            new EntityId($data["policy_publication_id"]);
        }
        if (
            $data["policy_version"] === 0 &&
            ($data["unit_minutes"] !== 50 || $data["rounding"] !== "nearest")
        ) {
            throw new InvalidArgumentException(
                "The unpublished contact-hour fallback is fifty minutes, rounded to the nearest thousandth.",
            );
        }
    }
    public static function calculate(int $minutes, int $unit, string $rounding): int
    {
        if (
            $minutes < 0 ||
            $minutes > 10080 ||
            $unit < 1 ||
            $unit > 120 ||
            !in_array($rounding, ["nearest", "down", "up"], true)
        ) {
            throw new InvalidArgumentException("Use valid instructional minutes and rounding.");
        }
        $value = $minutes * 1000;
        return match ($rounding) {
            "down" => intdiv($value, $unit),
            "up" => intdiv($value + $unit - 1, $unit),
            default => intdiv($value * 2 + $unit, $unit * 2),
        };
    }
}
