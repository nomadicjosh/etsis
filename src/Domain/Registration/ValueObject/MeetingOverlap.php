<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

/** Half-open UTC intervals: a meeting ending when another begins does not overlap. */
final class MeetingOverlap
{
    /** Both lists are ordered by start time by SectionMeetingPlanDefinition.
     * @param list<array<string,mixed>> $target
     * @param list<array<string,mixed>> $other
     * @return array<string,string>|null */
    public static function first(array $target, array $other): ?array
    {
        $a = $b = 0;
        while (isset($target[$a], $other[$b])) {
            $left = $target[$a];
            $right = $other[$b];
            $start = max($left["starts_at"], $right["starts_at"]);
            $end = min($left["ends_at"], $right["ends_at"]);
            if ($start < $end) {
                return [
                    "target_occurrence_id" => $left["occurrence_id"],
                    "other_occurrence_id" => $right["occurrence_id"],
                    "starts_at" => $start,
                    "ends_at" => $end,
                ];
            }
            if ($left["ends_at"] <= $right["ends_at"]) {
                $a++;
            } else {
                $b++;
            }
        }
        return null;
    }
}
