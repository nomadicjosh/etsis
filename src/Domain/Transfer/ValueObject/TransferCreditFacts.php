<?php

declare(strict_types=1);

namespace Domain\Transfer\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Frozen version-1 projected transfer facts, independent of current catalog rules. */
final readonly class TransferCreditFacts
{
    public const array FIELDS = [
        "id",
        "student_id",
        "course_id",
        "term_id",
        "source_id",
        "credit_type",
        "course_code",
        "course_title",
        "attempted_milli",
        "completed_milli",
        "grade_points_milli",
        "grade_id",
        "status",
        "version",
    ];
    /** @param array<string,mixed> $facts */
    public function __construct(#[\SensitiveParameter] public array $facts)
    {
        if (count($facts) !== 14 || array_diff(array_keys($facts), self::FIELDS) !== []) {
            self::invalid();
        }
        foreach (["id", "student_id", "course_id", "term_id", "source_id", "grade_id"] as $key) {
            if ($key === "grade_id" && $facts[$key] === null) {
                continue;
            }
            if (!is_string($facts[$key])) {
                self::invalid();
            }
            new EntityId($facts[$key]);
        }
        foreach (["attempted_milli", "completed_milli", "grade_points_milli", "version"] as $key) {
            if (!is_int($facts[$key]) || $facts[$key] < 0 || $facts[$key] > 2147483646) {
                self::invalid();
            }
        }
        if (
            $facts["credit_type"] !== "transfer" ||
            !in_array($facts["status"], ["A", "N", "V"], true) ||
            $facts["completed_milli"] > $facts["attempted_milli"]
        ) {
            self::invalid();
        }
        foreach (["course_code" => 96, "course_title" => 191] as $key => $max) {
            if (
                !is_string($facts[$key]) ||
                trim($facts[$key]) === "" ||
                mb_strlen($facts[$key]) > $max ||
                !mb_check_encoding($facts[$key], "UTF-8") ||
                preg_match('/[\x00-\x1F\x7F]/', $facts[$key])
            ) {
                self::invalid();
            }
        }
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException("Invalid retained transfer credit facts.");
    }
}
