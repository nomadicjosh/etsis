<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Explicit institutional level mapping. Academic minima are never waived by either approval. */
final readonly class GraduateRegistrationRules
{
    public const array DEFAULT = [
        "enabled" => false,
        "undergraduate_level_id" => null,
        "graduate_level_id" => null,
        "minimum_gpa_milli" => 2000,
        "minimum_graduate_grade_milli" => null,
    ];

    public function __construct(public array $facts)
    {
        try {
            if (
                count($facts) !== count(self::DEFAULT) ||
                array_diff(array_keys($facts), array_keys(self::DEFAULT)) !== [] ||
                !is_bool($facts["enabled"]) ||
                !is_int($facts["minimum_gpa_milli"]) ||
                $facts["minimum_gpa_milli"] < 0 ||
                $facts["minimum_gpa_milli"] > 10000 ||
                ($facts["minimum_graduate_grade_milli"] !== null &&
                    (!is_int($facts["minimum_graduate_grade_milli"]) ||
                        $facts["minimum_graduate_grade_milli"] < 0 ||
                        $facts["minimum_graduate_grade_milli"] > 10000))
            ) {
                throw new \InvalidArgumentException();
            }
            foreach (["undergraduate_level_id", "graduate_level_id"] as $key) {
                if ($facts[$key] !== null) {
                    new EntityId($facts[$key]);
                } elseif ($facts["enabled"]) {
                    throw new \InvalidArgumentException();
                }
            }
            if (
                $facts["undergraduate_level_id"] !== null &&
                $facts["undergraduate_level_id"] === $facts["graduate_level_id"]
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            throw new ValidationException([
                [
                    "field" => "policy",
                    "message" => \Codefy\Framework\Helpers\trans(
                        "Choose distinct undergraduate and graduate levels and valid GPA and grade-point minimums.",
                    ),
                ],
            ]);
        }
    }

    public static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (
            $reason === "" ||
            mb_strlen($reason) > 1000 ||
            !mb_check_encoding($reason, "UTF-8") ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                [
                    "field" => "reason",
                    "message" => \Codefy\Framework\Helpers\trans(
                        "Enter a valid reason of up to 1000 characters.",
                    ),
                ],
            ]);
        }
        return $reason;
    }

    public static function hash(array $data): string
    {
        $canonical = static function (array $values) use (&$canonical): array {
            if (!array_is_list($values)) {
                ksort($values);
            }
            return array_map(static fn($value) => is_array($value) ? $canonical($value) : $value, $values);
        };
        return hash("sha256", json_encode($canonical($data), JSON_THROW_ON_ERROR));
    }
}
