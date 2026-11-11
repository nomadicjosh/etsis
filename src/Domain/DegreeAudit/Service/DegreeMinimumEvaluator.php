<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\AcademicGpaScope;
use Domain\DegreeAudit\ValueObject\AcademicPolicyResult;
use Domain\DegreeAudit\ValueObject\GpaTotals;
use InvalidArgumentException;

/** General minimum checks for one verified current work set; never a credential/standing decision. */
final readonly class DegreeMinimumEvaluator
{
    /** @param array<string,mixed> $minimums Verified effective degree thresholds for this selection/pursuit.
     * @return list<array{key:string,required_milli:?int,actual_minimum_milli:?int,actual_maximum_milli:?int,status:string}>
     */
    public function evaluate(
        AcademicPolicyResult $overall,
        AcademicPolicyResult $institutional,
        array $minimums,
    ): array {
        if (
            $overall->gpaScope !== AcademicGpaScope::Overall ||
            $institutional->gpaScope !== AcademicGpaScope::Institutional ||
            $overall->policy != $institutional->policy ||
            $overall->earnedMilli !== $institutional->earnedMilli ||
            $overall->residentMinimumMilli !== $institutional->residentMinimumMilli ||
            $overall->residentMaximumMilli !== $institutional->residentMaximumMilli
        ) {
            throw new InvalidArgumentException(
                "Use matching retained policies, credit inputs and distinct GPA scopes.",
            );
        }
        $fields = [
            "min_credits_milli",
            "min_resident_milli",
            "min_cumulative_gpa_milli",
            "min_overall_gpa_milli",
        ];
        if (count($minimums) !== 4 || array_diff(array_keys($minimums), $fields) !== []) {
            throw new InvalidArgumentException("Provide all effective degree minimums.");
        }
        foreach ($fields as $i => $key) {
            $value = $minimums[$key];
            if (
                ($value === null && $i < 2) ||
                ($value !== null && (!is_int($value) || $value < 0 || $value > ($i < 2 ? 9999999 : 10000)))
            ) {
                throw new InvalidArgumentException("Use valid retained credit and GPA thresholds.");
            }
        }
        if ($minimums["min_resident_milli"] > $minimums["min_credits_milli"]) {
            throw new InvalidArgumentException(
                "Institutional-origin credits cannot exceed total required credits.",
            );
        }
        return [
            $this->row(
                "min_credits_milli",
                $minimums["min_credits_milli"],
                $overall->earnedMilli,
                $overall->earnedMilli,
            ),
            $this->row(
                "min_resident_milli",
                $minimums["min_resident_milli"],
                $overall->residentMinimumMilli,
                $overall->residentMaximumMilli,
            ),
            $this->gpa(
                "min_cumulative_gpa_milli",
                $minimums["min_cumulative_gpa_milli"],
                $institutional->gpa,
            ),
            $this->gpa("min_overall_gpa_milli", $minimums["min_overall_gpa_milli"], $overall->gpa),
        ];
    }
    /** @return array{key:string,required_milli:?int,actual_minimum_milli:?int,actual_maximum_milli:?int,status:string} */
    private function gpa(string $key, ?int $required, ?GpaTotals $gpa): array
    {
        $actual = $gpa?->milli();
        return $this->row($key, $required, $actual, $actual);
    }
    /** @return array{key:string,required_milli:?int,actual_minimum_milli:?int,actual_maximum_milli:?int,status:string} */
    private function row(string $key, ?int $required, ?int $minimum, ?int $maximum): array
    {
        $status = match (true) {
            $required === null || $required === 0 => "not_required",
            $minimum === null || $maximum === null => "unknown",
            $minimum >= $required => "satisfied",
            $maximum < $required => "below_minimum",
            default => "unknown",
        };
        return [
            "key" => $key,
            "required_milli" => $required,
            "actual_minimum_milli" => $minimum,
            "actual_maximum_milli" => $maximum,
            "status" => $status,
        ];
    }
}
