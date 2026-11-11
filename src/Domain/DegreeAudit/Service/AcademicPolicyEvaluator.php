<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\AcademicPolicyResult;
use Domain\DegreeAudit\ValueObject\AcademicGpaScope;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\DegreeAudit\ValueObject\GpaTotals;
use Domain\DegreeAudit\ValueObject\PolicyAttempt;
use Domain\DegreeAudit\ValueObject\RepeatCreditPolicy;
use Domain\DegreeAudit\ValueObject\RepeatAttemptOrder;
use Domain\DegreeAudit\ValueObject\RepeatGpaPolicy;
use Domain\DegreeAudit\ValueObject\TransferGpaPolicy;
use InvalidArgumentException;
use OverflowException;

/** Pure bounded calculation. Its caller must establish complete, authorized, verified retained inputs. */
final readonly class AcademicPolicyEvaluator
{
    /** @param list<PolicyAttempt> $attempts */
    public function evaluate(
        array $attempts,
        AuditCalculationPolicy $policy,
        AcademicGpaScope $scope = AcademicGpaScope::Overall,
    ): AcademicPolicyResult {
        if (count($attempts) > 1000) {
            throw new InvalidArgumentException("Evaluate at most 1,000 supplied academic records.");
        }
        // Cumulative GPA excludes transfers before repeat selection; earned credit keeps its full scope.
        $transferGpa =
        $scope === AcademicGpaScope::Institutional ? TransferGpaPolicy::Exclude : $policy->transferGpa;
        $byCourse = [];
        $dispositions = [];
        foreach ($attempts as $a) {
            if (isset($dispositions[$a->id->value])) {
                throw new InvalidArgumentException("An academic record cannot enter the calculation twice.");
            }
            $byCourse[$a->courseId->value][] = $a;
            $dispositions[$a->id->value] = [
                "id" => $a->id->value,
                "credit" => "no_earned_credit",
                "gpa" => "not_included",
            ];
        }
        ksort($byCourse, SORT_STRING);
        foreach ($byCourse as &$course) {
            usort(
                $course,
                static fn(PolicyAttempt $a, PolicyAttempt $b): int => strcmp($a->id->value, $b->id->value),
            );
        }
        unset($course);
        ksort($dispositions, SORT_STRING);
        $issues = [];
        $creditPools = [];
        $gpaGroups = [];
        $earned = $residentMin = $residentMax = 0;
        $quality = $gpaCredits = 0;
        if ($policy->credit === null) {
            $issues[] = self::issue("credit", "policy_unknown");
        }
        if ($policy->gpa === null || $transferGpa === null) {
            $issues[] = self::issue("gpa", "policy_unknown");
        }
        foreach ($byCourse as $id => $course) {
            $creditEligible = [];
            $gpaEligible = [];
            foreach ($course as $a) {
                $d = &$dispositions[$a->id->value];
                if (in_array($a->status, ["D", "V"], true)) {
                    $d["credit"] = $d["gpa"] = $a->status === "D" ? "removed" : "voided";
                    unset($d);
                    continue;
                }
                $lateDrop = $a->status === "W";
                $pending =
                !$a->gradeRecorded &&
                $a->grade === null &&
                $a->earnedMilli === 0 &&
                $a->qualityMilli === 0;
                if ($lateDrop) {
                    $d["credit"] = "late_drop";
                } elseif ($pending) {
                    $d["credit"] = "in_progress";
                } elseif ($a->grade === null) {
                    $d["credit"] = "grade_unknown";
                    $issues[] = self::issue("credit", "grade_unknown", $id, [$a->id->value]);
                } elseif ($a->grade->facts["passes"] && ($a->earnedMilli > 0 || $a->attemptedMilli === 0)) {
                    $creditEligible[] = $a;
                    $d["credit"] = $policy->credit === null ? "policy_unknown" : "eligible";
                }
                if ($a->origin === "transfer" && $transferGpa === TransferGpaPolicy::Exclude) {
                    $d["gpa"] = "transfer_excluded";
                } elseif ($pending) {
                    $d["gpa"] = $lateDrop ? "default_drop_marker" : "in_progress";
                } elseif ($a->grade === null) {
                    $d["gpa"] = "grade_unknown";
                    $issues[] = self::issue("gpa", "grade_unknown", $id, [$a->id->value]);
                } elseif (!$a->grade->facts["counts_gpa"]) {
                    $d["gpa"] = "grade_excludes_gpa";
                } elseif ($a->attemptedMilli === 0) {
                    $d["gpa"] = "no_gpa_credits";
                } elseif ($policy->gpa === null || $transferGpa === null) {
                    $d["gpa"] = "policy_unknown";
                } elseif (
                    $policy->gradeScaleVersion === null ||
                    $a->grade->facts["scale_version_id"] === null
                ) {
                    $d["gpa"] = "grading_policy_unknown";
                    $issues[] = self::issue("gpa", "grading_policy_unknown", $id, [$a->id->value]);
                } elseif ($a->grade->facts["scale_version_id"] !== $policy->gradeScaleVersion->value) {
                    $d["gpa"] = "grading_policy_differs";
                    $issues[] = self::issue("gpa", "grading_policy_differs", $id, [$a->id->value]);
                } else {
                    $gpaEligible[] = $a;
                    $d["gpa"] = "eligible";
                }
                unset($d);
            }
            if ($policy->credit !== null && $creditEligible !== []) {
                try {
                    $pool = $this->credit($id, $creditEligible, $policy->credit, $dispositions);
                    $creditPools[] = $pool;
                    $earned = self::add($earned, $pool["credits_milli"]);
                    $residency = array_column($pool["options"], "resident_milli");
                    $residentMin = self::add($residentMin, min($residency));
                    $residentMax = self::add($residentMax, max($residency));
                } catch (OverflowException) {
                    $issues[] = self::issue("credit", "arithmetic_limit", $id);
                }
            }
            if ($policy->gpa !== null && $gpaEligible !== []) {
                try {
                    $group = $this->gpa(
                        $id,
                        $gpaEligible,
                        $policy->gpa,
                        $policy->attemptOrder,
                        $dispositions,
                    );
                    if (isset($group["issue"])) {
                        $issues[] = self::issue(
                            "gpa",
                            $group["issue"],
                            $id,
                            array_map(static fn(PolicyAttempt $a): string => $a->id->value, $gpaEligible),
                        );
                    } else {
                        $gpaGroups[] = $group;
                        $quality = self::add($quality, $group["quality_points_milli"]);
                        $gpaCredits = self::add($gpaCredits, $group["credits_milli"]);
                    }
                } catch (OverflowException) {
                    $issues[] = self::issue("gpa", "arithmetic_limit", $id);
                }
            }
        }
        $creditKnown = $gpaKnown = true;
        foreach ($issues as $issue) {
            if ($issue["component"] === "credit") {
                $creditKnown = false;
            } else {
                $gpaKnown = false;
            }
        }
        foreach ($dispositions as &$d) {
            foreach (["credit" => $creditKnown, "gpa" => $gpaKnown] as $component => $known) {
                if (
                    !$known &&
                    in_array(
                        $d[$component],
                        [
                            "included",
                            "eligible",
                            "repeat_not_selected",
                            "equivalent_credit_alternative",
                            "equivalent_gpa_alternative",
                        ],
                        true,
                    )
                ) {
                    $d[$component] = "undetermined";
                }
            }
        }
        unset($d);
        return new AcademicPolicyResult(
            policy: $policy,
            gpaScope: $scope,
            creditPools: $creditKnown ? $creditPools : [],
            gpaGroups: $gpaKnown ? $gpaGroups : [],
            dispositions: array_values($dispositions),
            issues: $issues,
            earnedMilli: $creditKnown ? $earned : null,
            residentMinimumMilli: $creditKnown ? $residentMin : null,
            residentMaximumMilli: $creditKnown ? $residentMax : null,
            gpa: $gpaKnown ? new GpaTotals($quality, $gpaCredits) : null,
        );
    }

    /** @param non-empty-list<PolicyAttempt> $attempts
     * @param array<string,array{id:string,credit:string,gpa:string}> $dispositions
     * @return array{course_id:string,credits_milli:int,options:non-empty-list<array{work_ids:list<string>,resident_milli:int}>}
     */
    private function credit(
        string $course,
        array $attempts,
        RepeatCreditPolicy $policy,
        array &$dispositions,
    ): array {
        $options = [];
        $credits = 0;
        if ($policy === RepeatCreditPolicy::AllEligible) {
            $resident = 0;
            $ids = [];
            foreach ($attempts as $a) {
                $credits = self::add($credits, $a->earnedMilli);
                $resident = self::add($resident, $a->origin === "institutional" ? $a->earnedMilli : 0);
                $ids[] = $a->id->value;
                self::reason($dispositions, $a->id->value, "credit", "included");
            }
            $options[] = ["work_ids" => $ids, "resident_milli" => $resident];
        } else {
            foreach ($attempts as $a) {
                $credits = max($credits, $a->earnedMilli);
            }
            foreach ($attempts as $a) {
                if ($a->earnedMilli === $credits) {
                    $options[] = [
                        "work_ids" => [$a->id->value],
                        "resident_milli" => $a->origin === "institutional" ? $a->earnedMilli : 0,
                    ];
                } else {
                    self::reason($dispositions, $a->id->value, "credit", "repeat_not_selected");
                }
            }
            foreach ($options as $option) {
                self::reason(
                    $dispositions,
                    $option["work_ids"][0],
                    "credit",
                    count($options) > 1 ? "equivalent_credit_alternative" : "included",
                );
            }
        }
        if ($options === []) {
            throw new \LogicException("Eligible credit selection must retain its supporting work.");
        }
        return ["course_id" => $course, "credits_milli" => $credits, "options" => $options];
    }

    /** @param non-empty-list<PolicyAttempt> $attempts
     * @param array<string,array{id:string,credit:string,gpa:string}> $dispositions
     * @return array{course_id:string,credits_milli:int,quality_points_milli:int,options:list<list<string>>}|array{issue:string}
     */
    private function gpa(
        string $course,
        array $attempts,
        RepeatGpaPolicy $policy,
        ?RepeatAttemptOrder $ordering,
        array &$dispositions,
    ): array {
        $chosen = $attempts;
        if ($policy === RepeatGpaPolicy::HighestGrade) {
            $points = max(
                array_map(
                    static fn(PolicyAttempt $a): int => $a->grade?->facts["points_milli"] ?? 0,
                    $attempts,
                ),
            );
            $chosen = array_values(
                array_filter(
                    $attempts,
                    static fn(PolicyAttempt $a): bool => $a->grade?->facts["points_milli"] === $points,
                ),
            );
        } elseif ($policy === RepeatGpaPolicy::LatestEligible && count($attempts) > 1) {
            if ($ordering === null) {
                foreach ($attempts as $a) {
                    self::reason($dispositions, $a->id->value, "gpa", "ordering_policy_unknown");
                }
                return ["issue" => "ordering_policy_unknown"];
            }
            $on = static fn(PolicyAttempt $a): ?string => $ordering === RepeatAttemptOrder::AcademicPeriod
                ? $a->academicOn
                : $a->completedOn;
            if (array_any($attempts, static fn(PolicyAttempt $a): bool => $on($a) === null)) {
                foreach ($attempts as $a) {
                    self::reason($dispositions, $a->id->value, "gpa", "chronology_unknown");
                }
                return ["issue" => "chronology_unknown"];
            }
            $latest = max(array_map($on, $attempts));
            $chosen = array_values(
                array_filter($attempts, static fn(PolicyAttempt $a): bool => $on($a) === $latest),
            );
        }
        if ($chosen === []) {
            throw new \LogicException("Eligible GPA selection must retain its supporting work.");
        }
        $quality = $credits = 0;
        $options = [];
        if ($policy === RepeatGpaPolicy::AllEligible) {
            $ids = [];
            foreach ($chosen as $a) {
                $quality = self::add($quality, $a->qualityMilli);
                $credits = self::add($credits, $a->attemptedMilli);
                $ids[] = $a->id->value;
            }
            $options[] = $ids;
        } else {
            $first = $chosen[0];
            if (
                array_any(
                    $chosen,
                    static fn(PolicyAttempt $a): bool => $a->qualityMilli !== $first->qualityMilli ||
                        $a->attemptedMilli !== $first->attemptedMilli,
                )
            ) {
                foreach ($chosen as $a) {
                    self::reason($dispositions, $a->id->value, "gpa", "ambiguous_repeat");
                }
                return ["issue" => "ambiguous_repeat"];
            }
            $quality = $first->qualityMilli;
            $credits = $first->attemptedMilli;
            foreach ($chosen as $a) {
                $options[] = [$a->id->value];
            }
        }
        $included = array_fill_keys(array_merge(...$options), true);
        foreach ($attempts as $a) {
            self::reason(
                $dispositions,
                $a->id->value,
                "gpa",
                isset($included[$a->id->value])
                    ? (count($options) > 1
                        ? "equivalent_gpa_alternative"
                        : "included")
                    : "repeat_not_selected",
            );
        }
        return [
            "course_id" => $course,
            "credits_milli" => $credits,
            "quality_points_milli" => $quality,
            "options" => $options,
        ];
    }

    /** @param array<string,array{id:string,credit:string,gpa:string}> $dispositions
     * @param 'credit'|'gpa' $component
     */
    private static function reason(array &$dispositions, string $id, string $component, string $reason): void
    {
        $row = $dispositions[$id];
        $row[$component] = $reason;
        $dispositions[$id] = $row;
    }

    private static function add(int $total, int $amount): int
    {
        if ($amount > PHP_INT_MAX - $total) {
            throw new OverflowException("Academic calculation exceeds the supported integer range.");
        }
        return $total + $amount;
    }
    /** @param list<string> $work
     * @return array{component:string,code:string,course_id:?string,work_ids:list<string>}
     */
    private static function issue(
        string $component,
        string $code,
        ?string $course = null,
        array $work = [],
    ): array {
        return ["component" => $component, "code" => $code, "course_id" => $course, "work_ids" => $work];
    }
}
