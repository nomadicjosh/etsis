<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class CreditSnapshot
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
        "course_version_id",
    ];
    /** @param array<string,mixed> $facts */
    public function __construct(public array $facts)
    {
        if (count($facts) !== count(self::FIELDS) || array_diff(array_keys($facts), self::FIELDS) !== []) {
            self::invalid();
        }
        $identifiers = ["id", "student_id", "course_id", "term_id", "source_id", "grade_id", "course_version_id"];
        foreach ($identifiers as $key) {
            if (in_array($key, ["grade_id", "course_version_id"], true) && $facts[$key] === null) {
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
            $facts["credit_type"] !== "institutional" ||
            $facts["completed_milli"] > $facts["attempted_milli"] ||
            !in_array($facts["status"], ["A", "N", "W", "D"], true)
        ) {
            self::invalid();
        }
        foreach (["course_code" => 96, "course_title" => 191] as $key => $max) {
            if (
                !is_string($facts[$key]) ||
                trim($facts[$key]) === "" ||
                !mb_check_encoding($facts[$key], "UTF-8") ||
                mb_strlen($facts[$key]) > $max ||
                preg_match('/[\x00-\x1F\x7F]/', $facts[$key])
            ) {
                self::invalid();
            }
        }
    }
    public function posted(): bool
    {
        return $this->facts["grade_id"] !== null ||
        $this->facts["completed_milli"] !== 0 ||
        $this->facts["grade_points_milli"] !== 0;
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException("Review the academic credit before recording a decision.");
    }
}
