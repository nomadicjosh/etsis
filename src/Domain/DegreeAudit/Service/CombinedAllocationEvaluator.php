<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Catalog\ValueObject\ComponentCourseSharing;
use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\DegreeAudit\ValueObject\AcademicInputSet;
use Domain\DegreeAudit\ValueObject\AllocationOutcome;
use Domain\DegreeAudit\ValueObject\AllocationProblem;
use Domain\DegreeAudit\ValueObject\AllocationResult;
use Domain\DegreeAudit\ValueObject\AllocationSharing;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;

/** One exact joint search over separately prepared retained curriculum components. No persistence. */
final readonly class CombinedAllocationEvaluator
{
    public function __construct(
        private DegreeProgressEvaluator $progress,
        private CourseAllocationPlanner $planner,
        private AllocationCoverageReview $coverage,
    ) {
    }

    /** Caller authorizes and holds one student lock; components contain no current-library fallbacks.
     * @param list<array<string,mixed>> $components
     * @return array<string,mixed>
     */
    public function evaluate(
        #[\SensitiveParameter] AcademicInputSet $inputs,
        #[\SensitiveParameter] array $components,
        ComponentCourseSharing $sharing,
        HistoryReadBudget $budget,
    ): array {
        if ($components === [] || count($components) > 25) {
            throw new AllocationBudgetExhaustedException();
        }
        $prepared = $maps = $reverseMaps = $groupComponents = $reuse = $groups = $courses = $waived = [];
        $choiceCount = $evidenceCount = $contributionCount = 0;
        $occurrence = 0;
        foreach ($components as $component) {
            $id = $component['requirements']['version_id'];
            if (isset($prepared[$id])) {
                throw new CorruptHistoryException();
            }
            $part = $this->progress->prepare($inputs, $component['requirements'], $component['effective'], $budget);
            if ($part->problem === null) {
                return ['status' => 'needs_review', 'issues' => ['component_inputs_need_review']];
            }
            $prepared[$id] = $part;
            $reuse[$id] = $part->problem->reuse;
            foreach ($part->problem->groups as $group) {
                if (++$occurrence > 25) {
                    throw new AllocationBudgetExhaustedException();
                }
                // Deterministic request-local keys, never business identities or persisted historical facts.
                $alias = str_pad((string) $occurrence, 26, '0', STR_PAD_LEFT);
                $maps[$id][$group['id']] = $alias;
                $reverseMaps[$id][$alias] = $group['id'];
                $groupComponents[$alias] = $id;
                $group['id'] = $alias;
                $groups[] = $group;
            }
            foreach ($part->problem->courses as $course) {
                foreach ($course['choices'] as $choice) {
                    $evidenceCount += count($choice['work_ids']);
                    $contributionCount += count($choice['rule_credits_milli'] ?? []);
                    if (++$choiceCount > 5000 || $evidenceCount > 20000 || $contributionCount > 20000) {
                        throw new AllocationBudgetExhaustedException();
                    }
                    $choice['group_id'] = $maps[$id][$choice['group_id']];
                    $courses[$course['course_id']]['course_id'] = $course['course_id'];
                    $courses[$course['course_id']]['choices'][] = $choice;
                }
            }
            foreach ($part->problem->waivedCourseIds as $course) {
                $waived[$course] = true;
            }
        }
        if (count($waived) > 5000) {
            throw new AllocationBudgetExhaustedException();
        }
        $problem = new AllocationProblem(
            $groups,
            array_values($courses),
            CourseGroupReuse::OneGroup,
            array_keys($waived),
            new AllocationSharing($groupComponents, $reuse, $sharing)
        );
        $result = $this->planner->plan($problem);
        if ($result->outcome === AllocationOutcome::Undetermined) {
            throw new AllocationBudgetExhaustedException();
        }
        $this->coverage->review($problem, $result);
        $byComponent = [];
        foreach ($result->placements as $placement) {
            $component = $groupComponents[$placement['group_id']];
            $original = $reverseMaps[$component][$placement['group_id']];
            $placement['group_id'] = $original;
            $byComponent[$component][] = $placement;
        }
        $reviews = [];
        foreach ($components as $component) {
            $r = $component['requirements'];
            $id = $r['version_id'];
            $review = $prepared[$id]->review(new AllocationResult(
                $result->outcome,
                $byComponent[$id] ?? [],
                $result->steps,
            ));
            $review['mode'] = 'combined_component';
            $review['context'] = [
                'mode' => 'combined_component', 'kind' => $component['kind'],
                'requirements_version_id' => $id, 'version' => $r['version'],
                'name' => $r['definition']['name'], 'academic_year_code' => $r['context']['academic_year_code'],
                'specialization' => $r['specialization'] ?? null, 'cohort_changed' => false,
                'group_reuse' => $r['definition']['policies']['group_reuse'] ?? null,
            ];
            $reviews[] = ['kind' => $component['kind'], 'progress' => $review];
        }
        return ['status' => 'review', 'outcome' => $result->outcome->value,
            'sharing' => $sharing->value, 'input_status' => $inputs->status->value,
            'current_record_count' => count($inputs->items), 'components' => $reviews];
    }
}
