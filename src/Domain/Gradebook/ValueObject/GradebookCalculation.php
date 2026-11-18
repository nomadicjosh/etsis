<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Working feedback only: never an institutional grade, credit or GPA decision. */
final readonly class GradebookCalculation
{
    public const int LIMIT = 500;
    public const int CATEGORY_LIMIT = 20;
    /** @var array<string, int> */
    private array $categoryByAssignment;
    /** @var array<string, true> */
    private array $bonusByAssignment;

    /** @param array<string, int> $weights Hundredths of a percent, keyed by assignment ULID.
     * @param list<array{title:string, weight_hundredths:int, assignments:list<string>, drop_lowest?:int}> $categories
     * @param list<string> $bonusAssignments Derived from the selected assignments, never client settings.
     */
    public function __construct(
        public string $mode = "points",
        public array $weights = [],
        public array $categories = [],
        array $bonusAssignments = [],
    ) {
        $this->bonusByAssignment = array_fill_keys($bonusAssignments, true);
        if (!in_array($mode, ["points", "weighted", "category"], true) || count($weights) > self::LIMIT) {
            self::invalid("Choose total points, assignment weights or category weights.");
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
            ($mode !== "weighted" && $weights !== []) ||
            ($mode === "weighted" &&
                array_sum(array_diff_key($weights, $this->bonusByAssignment)) !== 10000)
        ) {
            self::invalid("Regular assignment weights must total 100%; bonus-only weights add percentage points.");
        }
        if (array_sum(array_intersect_key($weights, $this->bonusByAssignment)) > 10000) {
            self::invalid("Combined bonus-only weights cannot exceed 100 percentage points.");
        }
        if (
            !array_is_list($categories) ||
            count($categories) > self::CATEGORY_LIMIT ||
            ($mode !== "category" && $categories !== [])
        ) {
            self::invalid("Use up to 20 categories in category-weight mode.", "categories");
        }
        $members = [];
        $names = [];
        $categoryTotal = 0;
        foreach ($categories as $index => $category) {
            if (
                !is_array($category) ||
                array_diff(array_keys($category), [
                    "title",
                    "weight_hundredths",
                    "assignments",
                    "drop_lowest",
                ]) !== [] ||
                !is_string($category["title"] ?? null) ||
                !is_int($category["weight_hundredths"] ?? null) ||
                !is_array($category["assignments"] ?? null) ||
                (array_key_exists("drop_lowest", $category) &&
                    (!is_int($category["drop_lowest"]) ||
                        $category["drop_lowest"] < 0 ||
                        $category["drop_lowest"] >= self::LIMIT))
            ) {
                self::invalid(
                    "Supply a name, weight, assignment list and optional drop count from 0 to 499 for each category.",
                    "categories",
                );
            }
            $name = $category["title"];
            $weight = $category["weight_hundredths"];
            if (
                !mb_check_encoding($name, "UTF-8") ||
                $name === "" ||
                trim($name) !== $name ||
                mb_strlen($name) > 80 ||
                preg_match('/[\x00-\x1F\x7F]/', $name) ||
                isset($names[mb_strtolower($name)]) ||
                $weight < 0 ||
                $weight > 10000 ||
                !array_is_list($category["assignments"]) ||
                count($category["assignments"]) > self::LIMIT
            ) {
                self::invalid(
                    "Use unique category names, valid weights and bounded assignment lists.",
                    "categories",
                );
            }
            $names[mb_strtolower($name)] = true;
            $categoryTotal += $weight;
            foreach ($category["assignments"] as $id) {
                if (!is_string($id)) {
                    self::invalid("Choose valid assignments for each category.", "categories");
                }
                try {
                    new EntityId($id);
                } catch (\InvalidArgumentException) {
                    self::invalid("Choose valid assignments for each category.", "categories");
                }
                if (isset($members[$id]) || count($members) >= self::LIMIT) {
                    self::invalid(
                        "Each assignment can belong to only one category, up to 500 assignments.",
                        "categories",
                    );
                }
                $members[$id] = $index;
            }
        }
        if ($mode === "category" && $categoryTotal !== 10000) {
            self::invalid("Category weights must total 100%.", "categories");
        }
        $this->categoryByAssignment = $members;
    }

    /** @return list<string> */
    public function assignmentIds(): array
    {
        return array_keys($this->mode === "category" ? $this->categoryByAssignment : $this->weights);
    }

    /** @return array<string, mixed> */
    public function assignmentPresentation(string $id): array
    {
        $category = isset($this->categoryByAssignment[$id])
        ? $this->categories[$this->categoryByAssignment[$id]]
        : null;
        $weight = $this->mode === "weighted" ? $this->weights[$id] ?? 0 : null;
        return [
            "weight_hundredths" => $weight,
            "category_title" => $category["title"] ?? null,
            "category_weight_hundredths" => $category["weight_hundredths"] ?? null,
            "category_drop_lowest" => $category === null ? null : $category["drop_lowest"] ?? 0,
            "bonus_only" => isset($this->bonusByAssignment[$id]),
            "excluded" =>
                $this->mode === "category"
                    ? $category === null || $category["weight_hundredths"] === 0
                    : $weight === 0,
        ];
    }

    /** @param list<array<string, mixed>> $scores
     * @return array<string, mixed>
     */
    public function summarize(array $scores): array
    {
        if (count($scores) > self::LIMIT) {
            self::invalid("This gradebook exceeds the 500-assignment calculation limit.");
        }
        $seen = [];
        foreach ($scores as $score) {
            $id = (string) $score["assignment_id"];
            $possible = $score["possible_hundredths"];
            $earned = $score["earned_hundredths"];
            $bonus = array_key_exists("bonus_hundredths", $score) ? $score["bonus_hundredths"] : 0;
            if (
                isset($seen[$id]) ||
                !is_int($possible) ||
                !is_int($bonus) ||
                (array_key_exists("excused", $score) && !is_bool($score["excused"])) ||
                ($earned !== null && !is_int($earned))
            ) {
                self::invalid("An assignment score needs review before calculating this gradebook.");
            }
            try {
                new AssignmentPoints($possible, $bonus)->assertEarned($earned);
            } catch (ValidationException) {
                self::invalid(
                    "An assignment score or bonus limit needs review before calculating this gradebook.",
                );
            }
            $seen[$id] = $score;
        }
        if (array_diff_key(array_fill_keys($this->assignmentIds(), true), $seen) !== []) {
            self::invalid(
                "The weighted assignment list changed. Review the gradebook setup.",
                $this->mode === "category" ? "categories" : "weights",
            );
        }
        if ($this->mode === "weighted") {
            $actualBonus = array_keys(array_filter(
                $seen,
                static fn(array $score): bool => $score["possible_hundredths"] === 0,
            ));
            $weightedIds = array_keys($this->weights);
            $expectedBonus = array_keys($this->bonusByAssignment);
            if (
                array_diff(array_intersect($actualBonus, $weightedIds), $expectedBonus) !== [] ||
                array_diff(array_intersect($expectedBonus, $weightedIds), $actualBonus) !== []
            ) {
                self::invalid("The assignment types changed. Review gradebook setup before calculating.");
            }
        }
        if ($this->mode === "category") {
            return $this->withExcusals($this->categorySummary($seen), $seen);
        }
        $total = 0;
        $graded = 0;
        $contribution = 0.0;
        $pending = 0;
        $included = 0;
        $bonusEarned = 0;
        $bonusEnabled = false;
        $excused = 0;
        $bonusContribution = 0.0;
        $bonusPending = 0;
        $bonusOnly = 0;
        foreach ($scores as $score) {
            $id = (string) $score["assignment_id"];
            $possible = $score["possible_hundredths"];
            $earned = $score["earned_hundredths"];
            $onlyBonus = $possible === 0;
            $weight = $this->mode === "points" ? $possible : $this->weights[$id] ?? 0;
            if ($weight === 0 && !($onlyBonus && $this->mode === "points")) {
                continue;
            }
            ++$included;
            if ($score["excused"] ?? false) {
                ++$excused;
                continue;
            }
            $bonusEnabled = $bonusEnabled || ($score["bonus_hundredths"] ?? 0) > 0;
            if ($onlyBonus) {
                ++$bonusOnly;
                if ($earned === null) {
                    ++$bonusPending;
                } else {
                    $bonusEarned += $earned;
                    $bonusContribution += $this->mode === "points"
                    ? $earned
                    : $weight * ($earned / $score["bonus_hundredths"]);
                }
                continue;
            }
            $total += $weight;
            if ($earned === null) {
                ++$pending;
                continue;
            }
            $graded += $weight;
            $bonusEarned += max(0, $earned - $possible);
            $contribution += $weight * ($earned / $possible);
        }

        return $this->withExcusals(
            [
                "mode" => $this->mode,
                // Floating arithmetic is deliberately restricted to rounded, non-authoritative feedback.
                "percent_hundredths" => $graded === 0 ? null : (int) round(
                    $this->mode === "points"
                        ? (($contribution + $bonusContribution) / $graded) * 10000
                        : ($contribution / $graded) * 10000 + $bonusContribution,
                ),
                "coverage_hundredths" => $total === 0 ? 0 : (int) round(($graded / $total) * 10000),
                "graded" => $included - $pending - $excused - $bonusPending,
                "pending" => $pending,
                "excluded" => count($scores) - $included,
                "excused" => $excused,
                "bonus_earned_hundredths" => $graded === 0 ? 0 : $bonusEarned,
                "bonus_enabled" => $bonusEnabled,
                "bonus_only" => $bonusOnly,
                "bonus_pending" => $bonusPending,
                "unapplied_bonus_hundredths" => $graded === 0 ? $bonusEarned : 0,
            ],
            $seen,
        );
    }

    /** @param array<string, array<string, mixed>> $scores
     * @return array<string, mixed>
     */
    private function categorySummary(array $scores): array
    {
        $items = [];
        $contribution = 0.0;
        $activeWeight = 0;
        $coverage = 0.0;
        $included = 0;
        $pending = 0;
        $awaiting = 0;
        $dropped = [];
        $bonusTotal = 0;
        $bonusEnabled = false;
        $excusedTotal = 0;
        $availableWeight = 0;
        $bonusOnlyTotal = 0;
        $bonusPendingTotal = 0;
        $unappliedBonus = 0;
        foreach ($this->categories as $category) {
            $possible = 0;
            $gradedPossible = 0;
            $earned = 0;
            $graded = 0;
            $bonusEarned = 0;
            $categoryBonus = false;
            $excused = 0;
            $regularAssignments = 0;
            $regularExcused = 0;
            $bonusOnly = 0;
            $bonusPending = 0;
            $weight = $category["weight_hundredths"];
            $omitted = $weight === 0 ? [] : $this->dropped($category, $scores);
            $omittedIds = array_fill_keys(array_column($omitted, "assignment_id"), true);
            array_push($dropped, ...$omitted);
            foreach ($category["assignments"] as $id) {
                $score = $scores[$id];
                if ($score["possible_hundredths"] > 0) {
                    ++$regularAssignments;
                }
                if ($score["excused"] ?? false) {
                    ++$excused;
                    $regularExcused += $score["possible_hundredths"] > 0 ? 1 : 0;
                    continue;
                }
                if (isset($omittedIds[$id])) {
                    continue;
                }
                $possible += $score["possible_hundredths"];
                $categoryBonus = $categoryBonus || ($score["bonus_hundredths"] ?? 0) > 0;
                if ($score["possible_hundredths"] === 0) {
                    ++$bonusOnly;
                    $bonusPending += $score["earned_hundredths"] === null ? 1 : 0;
                }
                if ($score["earned_hundredths"] !== null) {
                    $gradedPossible += $score["possible_hundredths"];
                    $earned += $score["earned_hundredths"];
                    $bonusEarned += max(0, $score["earned_hundredths"] - $score["possible_hundredths"]);
                    ++$graded;
                }
            }
            $fullyExcused = ($regularAssignments > 0 && $regularExcused === $regularAssignments) ||
            ($category["assignments"] !== [] && $excused === count($category["assignments"]));
            if ($weight > 0) {
                $excusedTotal += $excused;
                $bonusOnlyTotal += $bonusOnly;
                $bonusPendingTotal += $bonusPending;
                if (!$fullyExcused) {
                    $availableWeight += $weight;
                }
                if ($gradedPossible > 0) {
                    $bonusTotal += $bonusEarned;
                } else {
                    $unappliedBonus += $bonusEarned;
                }
                $bonusEnabled = $bonusEnabled || $categoryBonus;
                $included += count($category["assignments"]);
                $pending += count($category["assignments"]) - $graded - count($omitted) - $excused - $bonusPending;
                if ($gradedPossible > 0) {
                    $activeWeight += $weight;
                    $contribution += $weight * ($earned / $gradedPossible);
                } elseif (!$fullyExcused) {
                    ++$awaiting;
                }
                $coverage += $possible === 0 ? 0 : $weight * ($gradedPossible / $possible);
            }
            $items[] = [
                "title" => $category["title"],
                "weight_hundredths" => $weight,
                "percent_hundredths" =>
                    $gradedPossible === 0 ? null : (int) round(($earned / $gradedPossible) * 10000),
                "coverage_hundredths" =>
                    $possible === 0 ? 0 : (int) round(($gradedPossible / $possible) * 10000),
                "assignments" => count($category["assignments"]),
                "graded" => $graded + count($omitted),
                "excused" => $excused,
                "fully_excused" => $fullyExcused,
                "dropped" => count($omitted),
                "drop_lowest" => $category["drop_lowest"] ?? 0,
                "bonus_earned_hundredths" => $bonusEarned,
                "bonus_only" => $bonusOnly,
                "bonus_pending" => $bonusPending,
                "unapplied_bonus_hundredths" => $gradedPossible === 0 ? $bonusEarned : 0,
            ];
        }
        return [
            "mode" => "category",
            "percent_hundredths" =>
                $activeWeight === 0 ? null : (int) round(($contribution / $activeWeight) * 10000),
            "coverage_hundredths" =>
                $availableWeight === 0 ? 0 : (int) round($coverage * (10000 / $availableWeight)),
            "graded" => $included - $pending - count($dropped) - $excusedTotal - $bonusPendingTotal,
            "pending" => $pending,
            "excluded" => count($scores) - $included,
            "excused" => $excusedTotal,
            "dropped" => count($dropped),
            "dropped_assignments" => $dropped,
            "bonus_earned_hundredths" => $bonusTotal,
            "bonus_enabled" => $bonusEnabled,
            "bonus_only" => $bonusOnlyTotal,
            "bonus_pending" => $bonusPendingTotal,
            "unapplied_bonus_hundredths" => $unappliedBonus,
            "awaiting_categories" => $awaiting,
            "categories" => $items,
        ];
    }

    /** Drop by exact percentage; equal percentages drop the larger-point task, then stable assignment ID.
     * Always retain at least one graded task. Pending tasks and stored scores remain untouched.
     * @param array<string,mixed> $category
     * @param array<string,array<string,mixed>> $scores
     * @return list<array{assignment_id:string,title:string,earned_hundredths:int,possible_hundredths:int}>
     */
    private function dropped(array $category, array $scores): array
    {
        $count = $category["drop_lowest"] ?? 0;
        if ($count === 0) {
            return [];
        }
        $graded = [];
        foreach ($category["assignments"] as $id) {
            $score = $scores[$id];
            if (
                $score["possible_hundredths"] > 0 &&
                $score["earned_hundredths"] !== null &&
                !($score["excused"] ?? false)
            ) {
                $graded[] = self::scoreSummary($score, $id);
            }
        }
        usort(
            $graded,
            static fn(array $a, array $b): int => $a["earned_hundredths"] * $b["possible_hundredths"] <=>
            $b["earned_hundredths"] * $a["possible_hundredths"] ?:
            $b["possible_hundredths"] <=> $a["possible_hundredths"] ?:
            strcmp($a["assignment_id"], $b["assignment_id"]),
        );
        return array_slice($graded, 0, min($count, max(0, count($graded) - 1)));
    }

    private static function invalid(string $message, string $field = "weights"): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
    /** Excusal is an explicit student status even when the rubric currently excludes that assignment.
     * @param array<string,mixed> $result
     * @param array<string,array<string,mixed>> $scores
     * @return array<string,mixed>
     */
    private function withExcusals(array $result, array $scores): array
    {
        $items = [];
        foreach ($scores as $id => $score) {
            if ($score["excused"] ?? false) {
                $items[] = self::scoreSummary($score, (string) $id);
            }
        }
        $result["excluded"] -= count($items) - $result["excused"];
        $result["excused"] = count($items);
        $result["excused_assignments"] = $items;
        return $result;
    }
    /** @param array<string,mixed> $score
     * @return array<string,mixed>
     */
    private static function scoreSummary(array $score, string $id): array
    {
        return [
            "assignment_id" => $id,
            "title" => is_string($score["title"] ?? null) ? $score["title"] : $id,
            "earned_hundredths" => $score["earned_hundredths"],
            "possible_hundredths" => $score["possible_hundredths"],
        ];
    }
}
