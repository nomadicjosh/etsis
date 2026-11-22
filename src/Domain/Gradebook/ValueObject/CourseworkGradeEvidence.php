<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use JsonSerializable;

/** Protected accepted inputs, not a live link to mutable coursework or a second grade authority. */
final readonly class CourseworkGradeEvidence implements JsonSerializable
{
    public const int MAX_BYTES = 49152;

    /** @param array<string,mixed> $facts */
    public function __construct(#[\SensitiveParameter] public array $facts)
    {
        $keys = [
            "student_id",
            "section_id",
            "mode",
            "weights",
            "categories",
            "calculation_version",
            "scores",
            "grade_id",
            "min_score_milli",
            "max_score_milli",
            "exact_percent",
        ];
        if (
            array_key_exists("grade_policy", $facts) ||
            array_key_exists("grading_percent", $facts)
        ) {
            array_push($keys, "grade_policy", "grading_percent");
        }
        if (
            count($facts) !== count($keys) ||
            array_diff(array_keys($facts), $keys) !== [] ||
            strlen(json_encode($facts, JSON_THROW_ON_ERROR)) > self::MAX_BYTES
        ) {
            self::invalid();
        }
        foreach (["student_id", "section_id", "grade_id"] as $key) {
            if (!is_string($facts[$key])) {
                self::invalid();
            }
            new EntityId($facts[$key]);
        }
        if (
            !is_string($facts["mode"]) ||
            !is_array($facts["weights"]) ||
            !is_array($facts["categories"]) ||
            !is_int($facts["calculation_version"]) ||
            $facts["calculation_version"] < 0 ||
            !is_array($facts["scores"]) ||
            !array_is_list($facts["scores"]) ||
            $facts["scores"] === [] ||
            count($facts["scores"]) > GradebookCalculation::LIMIT ||
            !is_int($facts["min_score_milli"]) ||
            !is_int($facts["max_score_milli"]) ||
            $facts["min_score_milli"] < 0 ||
            $facts["max_score_milli"] > 100000 ||
            $facts["min_score_milli"] > $facts["max_score_milli"] ||
            !is_string($facts["exact_percent"]) ||
            preg_match('/^(0|[1-9][0-9]*)(\/[1-9][0-9]*)?$/D', $facts["exact_percent"]) !== 1
        ) {
            self::invalid();
        }
        $seen = [];
        $bonus = [];
        foreach ($facts["scores"] as $row) {
            if (
                !is_array($row) ||
                !array_is_list($row) ||
                count($row) !== 6 ||
                !is_string($row[0]) ||
                isset($seen[$row[0]]) ||
                !is_int($row[1]) ||
                !is_int($row[2]) ||
                ($row[3] !== null && !is_int($row[3])) ||
                !is_bool($row[4]) ||
                !is_int($row[5]) ||
                $row[5] < 0 ||
                $row[5] > 2147483646
            ) {
                self::invalid();
            }
            new EntityId($row[0]);
            new AssignmentPoints($row[1], $row[2])->assertEarned($row[3]);
            $seen[$row[0]] = true;
            if ($row[1] === 0) {
                $bonus[] = $row[0];
            }
        }
        $calculation = new GradebookCalculation(
            $facts["mode"],
            $facts["weights"],
            $facts["categories"],
            $bonus,
        );
        if (array_diff_key(array_fill_keys($calculation->assignmentIds(), true), $seen) !== []) {
            self::invalid();
        }
        $raw = BigRational::of($facts["exact_percent"]);
        $score = $raw;
        if (array_key_exists("grade_policy", $facts)) {
            if (!is_array($facts["grade_policy"]) || !is_string($facts["grading_percent"])) {
                self::invalid();
            }
            try {
                $score = new CourseworkGradePolicy($facts["grade_policy"])->apply($raw);
            } catch (\Domain\Shared\Exception\ValidationException) {
                self::invalid();
            }
            if ((string) $score !== $facts["grading_percent"]) {
                self::invalid();
            }
        }
        if (
            (string) $raw !== $facts["exact_percent"] ||
            $score->multipliedBy(1000)->compareTo($facts["min_score_milli"]) < 0 ||
            $score->multipliedBy(1000)->compareTo($facts["max_score_milli"]) > 0
        ) {
            self::invalid();
        }
    }

    public function revision(): string
    {
        return hash("sha256", json_encode(self::canonical($this->facts), JSON_THROW_ON_ERROR));
    }

    /** Explicit verification/recovery can recalculate; ordinary academic replay retains the accepted decision. */
    public function verifyCalculation(): void
    {
        $scores = [];
        $bonus = [];
        foreach ($this->facts["scores"] as [$id, $possible, $limit, $earned, $excused, $version]) {
            $scores[] = [
                "assignment_id" => $id,
                "possible_hundredths" => $possible,
                "bonus_hundredths" => $limit,
                "earned_hundredths" => $earned,
                "excused" => $excused,
                "version" => $version,
            ];
            if ($possible === 0) {
                $bonus[] = $id;
            }
        }
        $result = new GradebookCalculation(
            $this->facts["mode"],
            $this->facts["weights"],
            $this->facts["categories"],
            $bonus,
        )->summarize($scores, true);
        if (
            $result["pending"] !== 0 ||
            ($result["awaiting_categories"] ?? 0) !== 0 ||
            ($result["unapplied_bonus_hundredths"] ?? 0) !== 0 ||
            $result["exact_percent"] !== $this->facts["exact_percent"]
        ) {
            self::invalid();
        }
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $score = BigRational::of($this->facts["exact_percent"]);
        return [
            "score_display" => self::formatPercent($score),
            ...array_key_exists("grade_policy", $this->facts)
                ? [
                    "grade_policy" => array_replace(
                        CourseworkGradePolicy::DEFAULT,
                        $this->facts["grade_policy"],
                    ),
                    "grading_display" => self::formatPercent(
                        BigRational::of($this->facts["grading_percent"]),
                    ),
                ]
                : [],
            "mode" => $this->facts["mode"],
            "assignment_count" => count($this->facts["scores"]),
            "revision" => $this->revision(),
            "grade_id" => $this->facts["grade_id"],
        ];
    }

    public static function formatPercent(BigRational $score): string
    {
        $display = $score->toScale(6, RoundingMode::Down);
        return ($score->compareTo($display) === 0 ? "" : "≈ ") .
        rtrim(rtrim((string) $display, "0"), ".") . "%";
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->summary();
    }

    public function __debugInfo(): array
    {
        return ["evidence" => "[protected coursework inputs]"];
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map(self::canonical(...), $value);
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException("The retained coursework evidence needs review.");
    }
}
