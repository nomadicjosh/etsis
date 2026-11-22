<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Domain\Shared\Exception\ValidationException;

/** Explicit final-grade comparison rules; working averages keep their original mathematics. */
final readonly class CourseworkGradePolicy
{
    public const array DEFAULT = [
        "rounding" => "none",
        "decimal_places" => null,
        "cap_at_hundred" => false,
    ];

    public function __construct(public array $facts = self::DEFAULT)
    {
        if (
            count($facts) !== 3 ||
            array_diff(array_keys($facts), array_keys(self::DEFAULT)) !== [] ||
            !in_array(
                $facts["rounding"] ?? null,
                ["none", "half_up", "half_even", "floor", "ceiling"],
                true,
            ) ||
            !is_bool($facts["cap_at_hundred"] ?? null) ||
            ($facts["rounding"] === "none"
                ? $facts["decimal_places"] !== null
                : !is_int($facts["decimal_places"]) ||
                    $facts["decimal_places"] < 0 ||
                    $facts["decimal_places"] > 3)
        ) {
            throw new ValidationException([
                [
                    "field" => "grade_policy",
                    "message" =>
                        "Choose valid rounding, zero to three decimal places, and a cap setting.",
                ],
            ]);
        }
    }

    public static function hash(array $data): string
    {
        $canonical = static function (array $value) use (&$canonical): array {
            if (!array_is_list($value)) {
                ksort($value);
            }
            return array_map(
                static fn(mixed $v): mixed => is_array($v) ? $canonical($v) : $v,
                $value,
            );
        };
        return hash(
            "sha256",
            json_encode(
                $canonical($data),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
    public static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (
            $reason === '' || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason)
        ) {
            throw new ValidationException([[
                'field' => 'reason',
                'message' => \Codefy\Framework\Helpers\trans(
                    'Enter a clear policy publication reason of at most 1,000 characters.'
                ),
            ]]);
        }
        return $reason;
    }
    public function apply(BigRational $percent): BigRational
    {
        if ($percent->compareTo(0) < 0) {
            throw new \InvalidArgumentException("Coursework percentages cannot be negative.");
        }
        if ($this->facts["cap_at_hundred"] && $percent->compareTo(100) > 0) {
            $percent = BigRational::of(100);
        }
        $rounding = match ($this->facts["rounding"]) {
            "half_up" => RoundingMode::HalfUp,
            "half_even" => RoundingMode::HalfEven,
            "floor" => RoundingMode::Floor,
            "ceiling" => RoundingMode::Ceiling,
            default => null,
        };
        return $rounding === null
        ? $percent
        : $percent->toScale($this->facts["decimal_places"], $rounding)->toBigRational();
    }
}
