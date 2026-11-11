<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Minimal retained facts for one current attempt/award, not a declaration of history completeness. */
final readonly class PolicyAttempt
{
    public function __construct(
        public EntityId $id,
        public EntityId $courseId,
        public string $origin,
        public string $status,
        public int $attemptedMilli,
        public int $earnedMilli,
        public int $qualityMilli,
        public bool $gradeRecorded,
        public ?GradeSnapshot $grade,
        public ?string $completedOn = null,
        public ?string $academicOn = null,
    ) {
        $statuses = match ($origin) {
            "institutional" => ["A", "N", "W", "D"],
            "transfer" => ["A", "N", "V"],
            default => throw new InvalidArgumentException(
                "Use an institutional or transfer academic record.",
            ),
        };
        if (
            !in_array($status, $statuses, true) ||
            $earnedMilli > $attemptedMilli ||
            ($grade !== null && !$gradeRecorded)
        ) {
            self::invalid();
        }
        foreach ([$attemptedMilli, $earnedMilli, $qualityMilli] as $quantity) {
            if ($quantity < 0 || $quantity > 2147483646) {
                self::invalid();
            }
        }
        foreach ([$completedOn, $academicOn] as $on) {
            if ($on !== null) {
                $date = DateTimeImmutable::createFromFormat("!Y-m-d", $on, new DateTimeZone("UTC"));
                if ($date === false || $date->format("Y-m-d") !== $on) {
                    self::invalid();
                }
            }
        }
        if (in_array($status, ["V", "D"], true) && ($earnedMilli !== 0 || $qualityMilli !== 0)) {
            self::invalid();
        }
        if ($status === "W" && $earnedMilli !== 0) {
            self::invalid();
        }
        if ($grade !== null && !in_array($status, ["V", "D"], true)) {
            $g = $grade->facts;
            if (
                (!$g["passes"] && $earnedMilli !== 0) ||
                $qualityMilli !== ($g["counts_gpa"] ? self::weighted($attemptedMilli, $g["points_milli"]) : 0)
            ) {
                self::invalid();
            }
        }
    }

    /** @param array<string,mixed> $work Curated, verified work from the academic-work service. */
    public static function fromAcademicWork(array $work): self
    {
        foreach (["id", "course_id", "credit_type", "status"] as $key) {
            if (!is_string($work[$key] ?? null)) {
                self::invalid();
            }
        }
        foreach (["attempted_milli", "completed_milli", "quality_points_milli"] as $key) {
            if (!is_int($work[$key] ?? null)) {
                self::invalid();
            }
        }
        if (
            !is_bool($work["grade_recorded"] ?? null) ||
            !array_key_exists("grade", $work) ||
            ($work["grade"] !== null && !is_array($work["grade"])) ||
            !array_key_exists("completed_on", $work) ||
            ($work["completed_on"] !== null && !is_string($work["completed_on"]))
        ) {
            self::invalid();
        }
        return new self(
            new EntityId($work["id"]),
            new EntityId($work["course_id"]),
            $work["credit_type"],
            $work["status"],
            $work["attempted_milli"],
            $work["completed_milli"],
            $work["quality_points_milli"],
            $work["grade_recorded"],
            $work["grade"] === null ? null : new GradeSnapshot($work["grade"]),
            $work["completed_on"],
        );
    }

    private static function weighted(int $credits, int $points): int
    {
        $whole = intdiv($credits, 1000);
        if ($points !== 0 && $whole > intdiv(2147483646, $points)) {
            self::invalid();
        }
        $base = $whole * $points;
        $fraction = intdiv(($credits % 1000) * $points, 1000);
        if ($base > 2147483646 - $fraction) {
            self::invalid();
        }
        return $base + $fraction;
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException("Use consistent retained academic work and grade facts.");
    }
}
