<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\PolicyAttempt;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use InvalidArgumentException;

/** Explain current work without assigning partial placements or projecting future earned credit. */
final readonly class DegreeWorkReview
{
    /** @param list<array<string,mixed>> $work Verified current inputs, with early removals omitted.
     * @param array<string,mixed> $allocation Result from the same selected requirements and input boundary.
     * @return array{summary:array<string,mixed>,records:array<string,array<string,mixed>>}
     */
    public function review(array $work, array $allocation): array
    {
        if (count($work) > 200) {
            throw new InvalidArgumentException("Review at most 200 verified current work records.");
        }
        $established = $allocation["outcome"] === "feasible";
        $placements = $established ? array_column($allocation["records"], null, "id") : [];
        if (
            $established &&
            (count($placements) !== count($work) || count($placements) !== count($allocation["records"]))
        ) {
            throw new CorruptHistoryException();
        }
        $records = [];
        $pendingCount = $pendingCredits = $unused = 0;
        foreach ($work as $item) {
            $a = PolicyAttempt::fromAcademicWork($item);
            $id = $a->id->value;
            if ($a->status === "D" || isset($records[$id]) || ($established && !isset($placements[$id]))) {
                throw new CorruptHistoryException();
            }
            $state = match (true) {
                $a->status === "V" => "voided",
                $a->status === "W" => "late_drop",
                $a->origin === "institutional" &&
                !$a->gradeRecorded &&
                $a->grade === null &&
                $a->earnedMilli === 0 &&
                $a->qualityMilli === 0
                => "awaiting_grade",
                $a->grade === null => "grade_unknown",
                !$a->grade->facts["passes"] => "not_passing",
                $a->earnedMilli > 0 || $a->attemptedMilli === 0 => "completed",
                default => "no_earned_credit",
            };
            $uses = $established ? $placements[$id]["uses"] : [];
            $placement = !$established ? "not_established" : ($state === "completed" ? "not_used" : "unplaced");
            if ($uses !== []) {
                if ($state !== "completed") {
                    throw new CorruptHistoryException();
                }
                $placement = in_array(false, array_column($uses, "alternative"), true)
                ? "proposed"
                : "alternative";
            }
            if ($state === "awaiting_grade") {
                $pendingCount++;
                $pendingCredits =
                $pendingCredits === null || $pendingCredits > 2147483646 - $a->attemptedMilli
                ? null
                : $pendingCredits + $a->attemptedMilli;
            }
            if ($state === "completed" && $placement === "not_used") {
                $unused++;
            }
            $records[$id] = [
                "academic_state" => $state,
                "placement" => $placement,
                "uses" => $uses,
                "attempted_milli" => $a->attemptedMilli,
                "term_id" => $item["term_id"],
                "term_code" => $item["term_code"],
                "term_label_retained" => $item["term_label_retained"],
            ];
        }
        return [
            "summary" => [
                "placement_established" => $established,
                "unused_completed_count" => $established ? $unused : null,
                "awaiting_grade_count" => $pendingCount,
                "awaiting_grade_milli" => $pendingCredits,
            ],
            "records" => $records,
        ];
    }
}
