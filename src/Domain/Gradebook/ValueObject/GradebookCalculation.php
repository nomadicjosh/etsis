<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Working feedback only: never an institutional grade, credit or GPA decision. */
final readonly class GradebookCalculation
{
    public const int LIMIT = 500;

    /** @param array<string, int> $weights Hundredths of a percent, keyed by assignment ULID. */
    public function __construct(public string $mode = "points", public array $weights = [])
    {
        if (!in_array($mode, ["points", "weighted"], true) || count($weights) > self::LIMIT) {
            self::invalid("Choose total points or assignment weights.");
        }
        foreach ($weights as $id => $weight) {
            if (!is_string($id)) {
                self::invalid("Choose a valid assignment.");
            }
            try {
                new EntityId($id);
            } catch (\InvalidArgumentException) {
                self::invalid("Choose a valid assignment.");
            }
            if (!is_int($weight) || $weight < 0 || $weight > 10000) {
                self::invalid("Each weight must be between 0 and 100%.");
            }
        }
        if (
            ($mode === "points" && $weights !== []) ||
            ($mode === "weighted" && array_sum($weights) !== 10000)
        ) {
            self::invalid("Assignment weights must total 100%.");
        }
    }

    /** @param list<array<string, mixed>> $scores
     * @return array<string, mixed>
     */
    public function summarize(array $scores): array
    {
        if (count($scores) > self::LIMIT) {
            self::invalid("This gradebook exceeds the 500-assignment calculation limit.");
        }
        $total = 0;
        $graded = 0;
        $contribution = 0.0;
        $pending = 0;
        $included = 0;
        $seen = [];
        foreach ($scores as $score) {
            $id = (string) $score["assignment_id"];
            $possible = $score["possible_hundredths"];
            $earned = $score["earned_hundredths"];
            if (
                isset($seen[$id]) ||
                !is_int($possible) ||
                $possible < 1 ||
                $possible > 1000000 ||
                ($earned !== null && (!is_int($earned) || $earned < 0 || $earned > $possible))
            ) {
                self::invalid("An assignment score needs review before calculating this gradebook.");
            }
            $seen[$id] = true;
            $weight = $this->mode === "points" ? $possible : $this->weights[$id] ?? 0;
            if ($weight === 0) {
                continue;
            }
            ++$included;
            $total += $weight;
            if ($earned === null) {
                ++$pending;
                continue;
            }
            $graded += $weight;
            $contribution += $weight * ($earned / $possible);
        }
        if (array_diff_key($this->weights, $seen) !== []) {
            self::invalid("The weighted assignment list changed. Review the gradebook setup.");
        }

        return [
            "mode" => $this->mode,
            // Floating arithmetic is deliberately restricted to rounded, non-authoritative feedback.
            "percent_hundredths" => $graded === 0 ? null : (int) round(($contribution / $graded) * 10000),
            "coverage_hundredths" => $total === 0 ? 0 : (int) round(($graded / $total) * 10000),
            "graded" => $included - $pending,
            "pending" => $pending,
            "excluded" => count($scores) - $included,
        ];
    }

    private static function invalid(string $message): never
    {
        throw new ValidationException([["field" => "weights", "message" => $message]]);
    }
}
