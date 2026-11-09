<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use InvalidArgumentException;

/** A complete scale, not a partial merge with another policy. */
final readonly class GradingScaleDefinition
{
    /** @param array<string,mixed> $definition */
    public function __construct(public array $definition)
    {
        if (
            array_keys($definition) !== ["grades"] ||
            !is_array($definition["grades"]) ||
            !array_is_list($definition["grades"]) ||
            count($definition["grades"]) > 200
        ) {
            self::invalid();
        }
        $seen = [];
        $ranges = [];
        foreach ($definition["grades"] as $g) {
            if (
                !is_array($g) ||
                count($g) !== 6 ||
                array_diff(array_keys($g), [
                    "code",
                    "min_score_milli",
                    "max_score_milli",
                    "points_milli",
                    "passes",
                    "counts_gpa",
                ]) !== [] ||
                !is_string($g["code"]) ||
                preg_match('/^[A-Z0-9+.-]{1,12}$/D', $g["code"]) !== 1 ||
                isset($seen[$g["code"]]) ||
                !is_int($g["points_milli"]) ||
                $g["points_milli"] < 0 ||
                $g["points_milli"] > 10000 ||
                !is_bool($g["passes"]) ||
                !is_bool($g["counts_gpa"])
            ) {
                self::invalid();
            }
            $lo = $g["min_score_milli"];
            $hi = $g["max_score_milli"];
            if (
                ($lo === null) !== ($hi === null) ||
                ($lo !== null && (!is_int($lo) || !is_int($hi) || $lo < 0 || $hi > 100000 || $lo > $hi))
            ) {
                self::invalid();
            }
            if ($lo !== null) {
                foreach ($ranges as [$a, $b]) {
                    if ($lo <= $b && $hi >= $a) {
                        throw new InvalidArgumentException(
                            "Grade score ranges overlap. Each score must identify at most one grade.",
                        );
                    }
                }
            }
            if ($lo !== null) {
                $ranges[] = [$lo, $hi];
            }
            $seen[$g["code"]] = true;
        }
    }
    /** Percentage lookup only; gaps and administrative grades require an explicit grade decision.
     * @return array<string,mixed>|null
     */
    public function gradeForScore(int $scoreMilli): ?array
    {
        if ($scoreMilli < 0 || $scoreMilli > 100000) {
            throw new InvalidArgumentException("Use a percentage from zero through 100.");
        }
        foreach ($this->definition["grades"] as $grade) {
            if (
                $grade["min_score_milli"] !== null &&
                $scoreMilli >= $grade["min_score_milli"] &&
                $scoreMilli <= $grade["max_score_milli"]
            ) {
                return $grade;
            }
        }
        return null;
    }
    public function maximum(): int
    {
        $points = array_column($this->definition["grades"], "points_milli");
        return $points === [] ? 4000 : max($points);
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException(
            "Use at most 200 distinct grade codes with valid point values and credit/GPA choices.",
        );
    }
}
