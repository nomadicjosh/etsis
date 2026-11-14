<?php

declare(strict_types=1);

namespace Domain\Portal\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;

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
                $components[] = ['kind' => $component['kind'],
                    'label' => $specialization === null ? 'Degree'
                        : 'Specialization ' . $specialization['code'] . ' · ' . $specialization['title'],
                    'progress' => $progress];
            }
            $this->data = ['status' => 'review', 'mode' => 'combined_curriculum', 'sharing' => $review['sharing'],
                'outcome' => $review['outcome'], 'current_record_count' => $review['current_record_count'],
                'components' => $components];
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
        $referenceCount = 0;
        $data["allocation"] = ["outcome" => $review["allocation"]["outcome"], "groups" => []];
        foreach ($review["allocation"]["groups"] as $value) {
            $group = $pick($value, [
                "name",
                "requirement_type",
                "required_credits_milli",
                "required_courses",
                "minimum_grade_points_milli",
            ]);
            $group['coverage'] = null;
            $group['course_review'] = self::courseReview(
                $value['course_review'] ?? null,
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
}
