<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Version-bound student exceptions; no executable expressions or invented earned credit. */
final readonly class RequirementAdjustmentDefinition
{
    public const array DEGREE_MINIMUMS = [
        "min_credits_milli",
        "min_resident_milli",
        "min_cumulative_gpa_milli",
        "min_overall_gpa_milli",
    ];
    public const array GROUP_MINIMUMS = [
        "min_credits_milli",
        "min_gpa_milli",
        "min_courses",
        "min_course_grade_points_milli",
    ];
    /** @param array<string, mixed> $definition */
    public function __construct(public array $definition)
    {
        if (($definition["kind"] ?? null) === "waiver") {
            self::keys($definition, ["kind", "course_id"]);
            if (!is_string($definition["course_id"])) {
                self::invalid();
            }
            new EntityId($definition["course_id"]);
            return;
        }
        self::keys($definition, [
            "kind",
            "group_id",
            "group_version_id",
            "minimum",
            "original_milli",
            "required_milli",
        ]);
        $maximum = match ($definition["minimum"]) {
            "min_courses" => 200000,
            "min_course_grade_points_milli",
            "min_gpa_milli",
            "min_cumulative_gpa_milli",
            "min_overall_gpa_milli"
            => 10000,
            default => 9999999,
        };
        if (
            $definition["kind"] !== "minimum" ||
            !is_string($definition["minimum"]) ||
            !in_array(
                $definition["minimum"],
                $definition["group_id"] === null ? self::DEGREE_MINIMUMS : self::GROUP_MINIMUMS,
                true,
            ) ||
            !is_int($definition["original_milli"]) ||
            !is_int($definition["required_milli"]) ||
            $definition["required_milli"] < 0 ||
            $definition["required_milli"] >= $definition["original_milli"] ||
            $definition["original_milli"] > $maximum ||
            ($definition["minimum"] === "min_courses" &&
                ($definition["original_milli"] % 1000 !== 0 || $definition["required_milli"] % 1000 !== 0))
        ) {
            self::invalid();
        }
        if ($definition["group_id"] !== null) {
            if (!is_string($definition["group_id"]) || !is_string($definition["group_version_id"])) {
                self::invalid();
            }
            new EntityId($definition["group_id"]);
            new EntityId($definition["group_version_id"]);
        } elseif ($definition["group_version_id"] !== null) {
            self::invalid();
        }
    }
    public function key(): string
    {
        $d = $this->definition;
        return $d["kind"] === "waiver"
        ? "waiver:" . $d["course_id"]
        : "minimum:" . ($d["group_id"] ?? "degree") . ":" . $d["minimum"];
    }
    /** @param array<string, mixed> $value
     * @param list<string> $keys */
    public static function keys(array $value, array $keys): void
    {
        if (count($value) !== count($keys) || array_diff(array_keys($value), $keys) !== []) {
            self::invalid();
        }
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException(
            "Choose a course waiver or a valid reduction to a published requirement minimum. Course counts must be whole numbers.",
        );
    }
}
