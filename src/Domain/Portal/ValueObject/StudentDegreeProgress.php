<?php

declare(strict_types=1);

namespace Domain\Portal\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\DegreeAudit\ValueObject\GroupWorkEligibility;

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
        if (isset($review['components'])) {
            if (count($review['components']) > 25 || $review['components'] === []) {
                throw new CorruptHistoryException();
            }
            $components = [];
            foreach ($review['components'] as $component) {
                $progress = new self($component['progress'])->describe();
                $specialization = $component['progress']['context']['specialization'] ?? null;
                $type = $specialization['kind'] ?? null;
                $kind = null;
                if ($type !== null) {
                    $kind = \Domain\Catalog\ValueObject\CurriculumComponentKind::tryFrom($type)
                    ?? throw new CorruptHistoryException();
                }
                $components[] = [
                    'kind' => $component['kind'],
                    'label' => $specialization === null ? 'Degree'
                        : ($kind?->label() ?? 'Component (type not recorded)') . ' ' .
                            $specialization['code'] . ' · ' . $specialization['title'],
                    'progress' => $progress,
                    ...$specialization !== null && array_key_exists('department', $specialization)
                        ? ['department' => self::department($specialization['department'])] : [],
                    ...$specialization !== null && array_key_exists('additional_departments', $specialization)
                        ? ['additional_departments' => array_map(
                            self::department(...),
                            $specialization['additional_departments']
                        )] : [],
                    ...$specialization !== null && array_key_exists('parent_major', $specialization)
                        ? ['parent_major' => self::major($specialization['parent_major'])] : [],
                ];
            }
            $this->data = ['status' => 'review', 'mode' => 'combined_curriculum', 'sharing' => $review['sharing'],
                'outcome' => $review['outcome'], 'current_record_count' => $review['current_record_count'],
                'components' => $components, 'competition' => self::combinedCompetition($review, $components)];
            return;
        }
        $pick = static fn(array $value, array $keys): array => array_intersect_key($value, array_flip($keys));
        $data = $pick($review, ["status", "input_status", "current_record_count"]);
        $data["context"] = $pick($review["context"], [
            "name",
            "kind",
            "academic_year_code",
            "version",
            "cohort_changed",
            "group_reuse",
        ]);
        if (array_key_exists('program_ownership', $review['context'])) {
            $ownership = $review['context']['program_ownership'];
            if (
                !is_array($ownership) || count($ownership) !== 2
                || !array_key_exists('department', $ownership)
                || !is_array($ownership['additional_departments'] ?? null)
            ) {
                throw new CorruptHistoryException();
            }
            $additional = new \Domain\Catalog\ValueObject\AdditionalDepartments(
                $ownership['additional_departments'],
                $ownership['department'] === null ? null
                    : new \Domain\Shared\ValueObject\EntityId($ownership['department']['id']),
            );
            $data['context']['program_ownership'] = [
                'department' => self::department($ownership['department']),
                'additional_departments' => array_map(self::department(...), $additional->definitions),
            ];
        }
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
        $data['records'] = [];
        $indices = [];
        if (count($review['records']) > 200) {
            throw new CorruptHistoryException();
        }
        foreach ($review['records'] as $value) {
            if (isset($indices[$value['id']])) {
                throw new CorruptHistoryException();
            }
            $indices[$value['id']] = count($data['records']);
            $record = $pick($value, [
                'course_code', 'course_title', 'term_code', 'term_label_retained', 'academic_state',
                'placement', 'attempted_milli', 'credit', 'overall_gpa', 'cumulative_gpa',
                'credit_type', 'recorded_earned_milli', 'completed_on', 'grade_state', 'grading_republication_verified',
            ]);
            $record['retained_grade'] = ($value['retained_grade'] ?? null) === null
            ? null : $pick($value['retained_grade'], ['code', 'points_milli', 'grading_publication_retained']);
            $data['records'][] = $record;
        }
        $data['issues'] = [];
        $issues = $review['issues'] ?? [];
        if (!is_array($issues) || !array_is_list($issues) || count($issues) > 24) {
            throw new CorruptHistoryException();
        }
        foreach ($issues as $issue) {
            if (
                !is_array($issue) ||
                !in_array($issue['component'] ?? null, ['credits', 'institutional', 'overall'], true) ||
                !in_array($issue['code'] ?? null, [
                    'policy_unknown', 'grade_unknown', 'grading_policy_unknown', 'grading_policy_differs',
                    'ordering_policy_unknown', 'chronology_unknown', 'ambiguous_repeat', 'arithmetic_limit',
                ], true)
            ) {
                throw new CorruptHistoryException();
            }
            $data['issues'][] = $pick($issue, ['component', 'code']);
        }
        $referenceCount = 0;
        $data["allocation"] = [
            "outcome" => $review["allocation"]["outcome"],
            "groups" => [],
            "eligibility_issues" => array_values(
                array_intersect($review["allocation"]["issues"] ?? [], [
                    "origin_unknown",
                    "evaluation_date_unknown",
                    'transfer_institution_unknown',
                    "completion_date_unknown",
                    'pursuit_start_unknown',
                ]),
            ),
        ];
        foreach ($review["allocation"]["groups"] as $value) {
            $group = $pick($value, [
                "name",
                "requirement_type",
                "required_credits_milli",
                "required_courses",
                "minimum_grade_points_milli",
            ]);
            if (array_key_exists("work_eligibility", $value)) {
                if (!is_array($value["work_eligibility"])) {
                    throw new CorruptHistoryException();
                }
                $facts = $pick($value["work_eligibility"], [
                    "credit_origin",
                    "completed_from",
                    "completed_through",
                    'max_age_months_at_pursuit_start',
                    'transfer_institutions',
                    'max_age_months_at_evaluation',
                ]);
                try {
                    $group["work_eligibility"] = new GroupWorkEligibility($facts)->facts;
                } catch (\InvalidArgumentException) {
                    throw new CorruptHistoryException();
                }
                if (array_key_exists('transfer_institutions', $facts)) {
                    $group['work_eligibility']['transfer_institutions'] = array_map(
                        static fn(array $institution): array => [
                            'code' => $institution['code'], 'name' => $institution['name'],
                        ],
                        $facts['transfer_institutions']
                    );
                }
                if (($facts['max_age_months_at_evaluation'] ?? null) !== null) {
                    $context = $value['work_evaluation_context'] ?? null;
                    if (!is_array($context) || count($context) !== 2) {
                        throw new CorruptHistoryException();
                    }
                    try {
                        $expected = new GroupWorkEligibility($facts)->evaluationContext(
                            $context['evaluated_on'] ?? null,
                        );
                    } catch (\InvalidArgumentException | \TypeError) {
                        throw new CorruptHistoryException();
                    }
                    if ($context != $expected) {
                        throw new CorruptHistoryException();
                    }
                    $group['work_evaluation_context'] = $expected;
                }
                if (($facts['max_age_months_at_pursuit_start'] ?? null) !== null) {
                    $context = $value['work_eligibility_context'] ?? null;
                    if (!is_array($context) || count($context) !== 2) {
                        throw new CorruptHistoryException();
                    }
                    try {
                        $expected = new GroupWorkEligibility($facts)->recencyContext(
                            $context['pursuit_started_on'] ?? null
                        );
                    } catch (\InvalidArgumentException | \TypeError) {
                        throw new CorruptHistoryException();
                    }
                    if ($context != $expected) {
                        throw new CorruptHistoryException();
                    }
                    $group['work_eligibility_context'] = $expected;
                }
            }
            $origins = [];
            $gradeFloors = [];
            foreach ($value['rules'] ?? [] as $index => $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                if (!is_int($index) || $index < 0 || $index >= 100) {
                    throw new CorruptHistoryException();
                }
                if (array_key_exists('min_course_grade_points_milli', $rule)) {
                    try {
                        $floor = \Domain\DegreeAudit\ValueObject\RequirementGradeFloor::fromRule($rule);
                    } catch (\InvalidArgumentException) {
                        throw new CorruptHistoryException();
                    }
                    $gradeFloors[] = ['requirement' => $index + 1, 'minimum_points_milli' => $floor->pointsMilli];
                }
                if (array_key_exists('credit_origin', $rule)) {
                    try {
                        $origin = \Domain\DegreeAudit\ValueObject\RequirementCreditOrigin::fromRule($rule);
                    } catch (\InvalidArgumentException) {
                        throw new CorruptHistoryException();
                    }
                    $origins[] = ['requirement' => $index + 1, 'credit_origin' => $origin->value];
                }
            }
            if ($origins !== []) {
                $group['requirement_origins'] = $origins;
            }
            if ($gradeFloors !== []) {
                $group['requirement_grade_floors'] = $gradeFloors;
            }
            $group["gaps"] = self::groupGaps($value, $review["allocation"]["outcome"]);
            $group["coverage"] = null;
            $group["course_review"] = self::courseReview(
                $value["course_review"] ?? null,
                $indices,
                $referenceCount,
                $review['allocation']['outcome'] === 'feasible',
            );
            if ($review['allocation']['outcome'] === 'feasible' && ($value['coverage'] ?? null) !== null) {
                $group['coverage'] = $pick($value['coverage'], ['applied_credits_milli', 'applied_courses']);
                $group['coverage']['rules'] = array_values(array_map(
                    static fn(array $rule): array => $pick($rule, [
                        'mode', 'applied_courses', 'applied_credits_milli', 'covered_courses',
                        'required_courses', 'required_credits_milli', 'waived_courses',
                    ]),
                    $value['coverage']['rules'],
                ));
            }
            $group['placements'] = [];
            if ($review['allocation']['outcome'] === 'feasible') {
                foreach ($value['placements'] as $placement) {
                    $group['placements'][] = $pick($placement, [
                        'course_code', 'course_title', 'target_code', 'target_title',
                        'via_substitution', 'credits_milli',
                    ]) + ['evidence_options' => self::evidenceOptions(
                        $placement['work_options'],
                        $indices,
                        $referenceCount,
                    )];
                }
            }
            $data["allocation"]["groups"][] = $group;
        }
        $this->data = $data;
    }

    /** Numeric requirement bounds in retained card order, without identities or rule grammar.
     * @param array<string,mixed> $group
     * @return array<string,mixed>|null
     */
    private static function groupGaps(array $group, string $outcome): ?array
    {
        $source = $group["gaps"] ?? null;
        if ($source === null || !in_array($outcome, ["feasible", "infeasible"], true)) {
            return null;
        }
        if (
            !is_array($source) ||
            !is_bool($source["has_shortfall"] ?? null) ||
            !is_array($source["rules"] ?? null) ||
            !array_is_list($source["rules"]) ||
            !is_array($group["rules"] ?? null) ||
            count($group["rules"]) > 100 ||
            count($source["rules"]) !== count($group["rules"])
        ) {
            throw new CorruptHistoryException();
        }
        $quantity = static function (mixed $value, int $maximum): int {
            if (!is_int($value) || $value < 0 || $value > $maximum) {
                throw new CorruptHistoryException();
            }
            return $value;
        };
        $credits = $quantity($group["required_credits_milli"] ?? null, 9999999);
        $courses = $quantity($group["required_courses"] ?? null, 200);
        $row = ["has_shortfall" => $source["has_shortfall"]];
        foreach (
            ["credit_upper_bound_milli", "credit_shortfall_milli", "course_upper_bound", "course_shortfall"] as $field
        ) {
            $row[$field] = $quantity($source[$field] ?? null, str_contains($field, "milli") ? 9999999 : 200);
        }
        if (
            $row["credit_upper_bound_milli"] > $credits ||
            $row["credit_shortfall_milli"] !== $credits - $row["credit_upper_bound_milli"] ||
            $row["course_shortfall"] !== max(0, $courses - $row["course_upper_bound"])
        ) {
            throw new CorruptHistoryException();
        }
        $shortfall = $row["credit_shortfall_milli"] > 0 || $row["course_shortfall"] > 0;
        $byId = [];
        foreach ($source["rules"] as $gap) {
            if (!is_array($gap) || !is_string($gap["id"] ?? null) || isset($byId[$gap["id"]])) {
                throw new CorruptHistoryException();
            }
            $byId[$gap["id"]] = $gap;
        }
        $row["rules"] = [];
        foreach ($group["rules"] as $rule) {
            if (!is_array($rule) || !is_string($rule["id"] ?? null) || !is_string($rule["mode"] ?? null)) {
                throw new CorruptHistoryException();
            }
            $gap = $byId[$rule["id"]] ?? throw new CorruptHistoryException();
            unset($byId[$rule["id"]]);
            if (!in_array($rule["mode"], ["all", "choose", "credits", "pool"], true)) {
                throw new CorruptHistoryException();
            }
            $card = ["mode" => $rule["mode"]];
            foreach (["required_courses", "course_upper_bound", "shortfall_courses"] as $field) {
                $card[$field] = $quantity($gap[$field] ?? null, 200);
            }
            $eligible = $gap["eligible_course_ids"] ?? null;
            if (!is_array($eligible) || count($eligible) > 200) {
                throw new CorruptHistoryException();
            }
            $card["eligible_count"] = isset($rule["eligibility"])
            ? count($eligible)
            : $quantity($gap["eligible_target_count"] ?? null, 200);
            if (
                $card["course_upper_bound"] > $card["eligible_count"] ||
                $card["shortfall_courses"] !== max(0, $card["required_courses"] - $card["course_upper_bound"])
            ) {
                throw new CorruptHistoryException();
            }
            if ($rule["mode"] === "credits") {
                foreach (["required_credits_milli", "credit_upper_bound_milli", "credit_shortfall_milli"] as $field) {
                    $card[$field] = $quantity($gap[$field] ?? null, 9999999);
                }
                if (
                    $card["credit_upper_bound_milli"] > $card["required_credits_milli"] ||
                    $card["credit_shortfall_milli"] !==
                        $card["required_credits_milli"] - $card["credit_upper_bound_milli"]
                ) {
                    throw new CorruptHistoryException();
                }
                $shortfall = $shortfall || $card["credit_shortfall_milli"] > 0;
            }
            $shortfall = $shortfall || $card["shortfall_courses"] > 0;
            $row["rules"][] = $card;
        }
        if ($byId !== [] || $source["has_shortfall"] !== $shortfall || ($outcome === "feasible" && $shortfall)) {
            throw new CorruptHistoryException();
        }
        return $row;
    }

    /** Curated response-local links and numeric bounds only; no raw search aliases or evidence identities.
     * @param array<string,mixed> $review
     * @param list<array<string,mixed>> $components
     * @return array<string,mixed>
     */
    private static function combinedCompetition(array $review, array $components): array
    {
        $source = $review["competition"] ?? ["conflicts" => [], "more_conflicts" => false];
        if (
            !is_array($source) ||
            !is_array($source["conflicts"] ?? null) ||
            !array_is_list($source["conflicts"]) ||
            count($source["conflicts"]) > 10 ||
            !is_bool($source["more_conflicts"] ?? null) ||
            (($review["outcome"] ?? null) !== "infeasible" &&
                ($source["conflicts"] !== [] || $source["more_conflicts"]))
        ) {
            throw new CorruptHistoryException();
        }
        $conflicts = [];
        foreach ($source["conflicts"] as $conflict) {
            if (
                !is_array($conflict) ||
                !is_array($conflict["groups"] ?? null) ||
                !array_is_list($conflict["groups"]) ||
                count($conflict["groups"]) < 2 ||
                count($conflict["groups"]) > 25
            ) {
                throw new CorruptHistoryException();
            }
            $row = ["groups" => []];
            $seen = [];
            foreach ($conflict["groups"] as $reference) {
                if (
                    !is_array($reference) ||
                    !is_int($reference["component"] ?? null) ||
                    !is_int($reference["group"] ?? null) ||
                    !isset(
                        $components[$reference["component"]]["progress"]["allocation"]["groups"][$reference["group"]],
                    )
                ) {
                    throw new CorruptHistoryException();
                }
                $key = $reference["component"] . ":" . $reference["group"];
                if (isset($seen[$key])) {
                    throw new CorruptHistoryException();
                }
                $seen[$key] = true;
                $row["groups"][] = ["component" => $reference["component"], "group" => $reference["group"]];
            }
            foreach (
                [
                    "required_credits_milli",
                    "credit_upper_bound_milli",
                    "credit_shortfall_milli",
                    "required_courses",
                    "course_upper_bound",
                    "course_shortfall",
                ] as $field
            ) {
                $value = $conflict[$field] ?? null;
                if (!is_int($value) || $value < 0 || $value > (str_contains($field, "milli") ? 249999975 : 5000)) {
                    throw new CorruptHistoryException();
                }
                $row[$field] = $value;
            }
            if (
                $row["credit_upper_bound_milli"] > $row["required_credits_milli"] ||
                $row["course_upper_bound"] > $row["required_courses"] ||
                $row["credit_shortfall_milli"] !== $row["required_credits_milli"] - $row["credit_upper_bound_milli"] ||
                $row["course_shortfall"] !== $row["required_courses"] - $row["course_upper_bound"] ||
                $row["credit_shortfall_milli"] + $row["course_shortfall"] === 0
            ) {
                throw new CorruptHistoryException();
            }
            $conflicts[] = $row;
        }
        return ["conflicts" => $conflicts, "more_conflicts" => $source["more_conflicts"]];
    }

    /**
     * Named-course evidence uses only this response's work positions, including incomplete plans.
     * @param list<array<string,mixed>>|null $rows
     * @param array<string,int> $indices
     * @return list<array<string,mixed>>|null */
    private static function courseReview(
        #[\SensitiveParameter] ?array $rows,
        #[\SensitiveParameter] array $indices,
        int &$count,
        bool $established,
    ): ?array {
        if ($rows === null) {
            return null;
        }
        if (count($rows) > 200) {
            throw new CorruptHistoryException();
        }
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $id = $row['course_id'];
            $placement = $row['placement'];
            if (
                isset($seen[$id]) ||
                !is_bool($row['required']) ||
                !is_bool($row['waived']) ||
                !in_array($placement, $established ? ['proposed', 'not_used'] : ['not_established'], true)
            ) {
                throw new CorruptHistoryException();
            }
            $seen[$id] = true;
            $attempts = [];
            $workSeen = [];
            foreach ($row['attempts'] as $attempt) {
                $work = $attempt['work_id'];
                if (
                    ++$count > 20000 ||
                    !isset($indices[$work]) ||
                    isset($workSeen[$work]) ||
                    \Domain\DegreeAudit\ValueObject\CourseMatchStatus::tryFrom($attempt['status']) === null ||
                    !is_bool($attempt['via_substitution']) ||
                    ($established ? !is_bool($attempt['selected']) : $attempt['selected'] !== null) ||
                    ($attempt['selected'] === true &&
                        ($placement !== 'proposed' || $attempt['status'] !== 'candidate'))
                ) {
                    throw new CorruptHistoryException();
                }
                $workSeen[$work] = true;
                $attempts[] = [
                    'record_index' => $indices[$work],
                    'status' => $attempt['status'],
                    'via_substitution' => $attempt['via_substitution'],
                    'selected' => $attempt['selected'],
                ];
            }
            $out[] = [
                'course_code' => $row['course_code'],
                'course_title' => $row['course_title'],
                'required' => $row['required'],
                'waived' => $row['waived'],
                'placement' => $placement,
                'attempts' => $attempts,
            ];
        }
        return $out;
    }
    /**
     * Review-local array positions are descriptive links, never database identities or command inputs.
     * @param list<list<string>> $options
     * @param array<string,int> $indices
     * @return list<list<int>> */
    private static function evidenceOptions(
        #[\SensitiveParameter] array $options,
        #[\SensitiveParameter] array $indices,
        int &$count,
    ): array {
        if ($options === [] || count($options) > 200) {
            throw new CorruptHistoryException();
        }
        $out = [];
        foreach ($options as $option) {
            if ($option === [] || count($option) > 200) {
                throw new CorruptHistoryException();
            }
            $seen = [];
            $rows = [];
            foreach ($option as $id) {
                if (++$count > 20000 || !isset($indices[$id]) || isset($seen[$id])) {
                    throw new CorruptHistoryException();
                }
                $seen[$id] = true;
                $rows[] = $indices[$id];
            }
            $out[] = $rows;
        }
        return $out;
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
    private static function major(?array $major): ?array
    {
        return $major === null ? null : array_intersect_key($major, array_flip(['code', 'title']));
    }

    private static function department(?array $department): ?array
    {
        if ($department === null) {
            return null;
        }
        $known = new \Domain\Catalog\ValueObject\DepartmentDefinition($department)->data;
        return [
            "code" => $known["code"],
            "title" => $known["title"],
            "school" =>
                $known["school"] === null
                    ? null
                    : array_intersect_key($known["school"], array_flip(["code", "title"])),
        ];
    }
}
