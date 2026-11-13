<?php

declare(strict_types=1);

namespace Domain\Portal\ValueObject;

/** Student-facing snapshot: no internal IDs, approval reasons, actors or raw requirement grammar. */
final readonly class StudentDegreeProgress
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $review */
    public function __construct(#[\SensitiveParameter] array $review)
    {
        if ($review["status"] !== "review") {
            $this->data = ["status" => $review["status"]];
            return;
        }
        $pick = static fn(array $value, array $keys): array => array_intersect_key($value, array_flip($keys));
        $data = $pick($review, ["status", "input_status", "current_record_count"]);
        $data["context"] = $pick($review["context"], [
            "name",
            "academic_year_code",
            "version",
            "cohort_changed",
            "group_reuse",
        ]);
        $data["minimums"] = array_map(
            static fn(array $value): array => $pick($value, [
                "key",
                "required_milli",
                "actual_minimum_milli",
                "actual_maximum_milli",
                "published_milli",
                "adjusted",
                "status",
            ]),
            $review["minimums"],
        );
        $data["gpa"] = [];
        foreach (["cumulative", "overall"] as $scope) {
            $data["gpa"][$scope] = $pick($review["gpa"][$scope], [
                "ready",
                "milli",
                "credits_milli",
                "quality_points_milli",
            ]);
        }
        $data["work_summary"] = $pick($review["work_summary"], [
            "placement_established",
            "unused_completed_count",
            "awaiting_grade_count",
            "awaiting_grade_milli",
        ]);
        $data["policy"] = $pick($review["policy"], [
            "repeat_credit",
            "repeat_gpa",
            "transfer_overall_gpa",
            "attempt_order",
        ]);
        $data["allocation"] = ["outcome" => $review["allocation"]["outcome"], "groups" => []];
        foreach ($review["allocation"]["groups"] as $value) {
            $group = $pick($value, [
                "name",
                "requirement_type",
                "required_credits_milli",
                "required_courses",
                "minimum_grade_points_milli",
            ]);
            $group["placements"] =
            $review["allocation"]["outcome"] !== "feasible"
            ? []
            : array_map(
                static fn(array $placement): array => $pick($placement, [
                            "course_code",
                            "course_title",
                            "target_code",
                            "target_title",
                            "via_substitution",
                            "credits_milli",
                        ]),
                $value["placements"],
            );
            $data["allocation"]["groups"][] = $group;
        }
        $data["records"] = array_map(
            static fn(array $value): array => $pick($value, [
                "course_code",
                "course_title",
                "term_code",
                "term_label_retained",
                "academic_state",
                "placement",
                "attempted_milli",
                "credit",
                "overall_gpa",
                "cumulative_gpa",
            ]),
            $review["records"],
        );
        $this->data = $data;
    }
    /** @return array<string,mixed> */
    public function describe(): array
    {
        return $this->data;
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["progress" => "[private student progress]"];
    }
}
