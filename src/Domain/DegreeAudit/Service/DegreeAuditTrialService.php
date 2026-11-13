<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Catalog\Service\PublishedCourseReader;
use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\DegreeAudit\Repository\CourseGroupRepository;
use Domain\DegreeAudit\Repository\RequirementsRepository;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\DegreeAudit\ValueObject\RequirementAdjustmentDefinitionV2;
use Domain\Gradebook\Service\GradingScaleService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use InvalidArgumentException;

/** Runtime-only curriculum trial. No publication, assignment, approval or result persistence. */
final readonly class DegreeAuditTrialService
{
    public function __construct(
        private RequirementsRepository $templates,
        private CourseGroupRepository $groups,
        private PublishedRequirementsReader $published,
        private PublishedCourseReader $courses,
        private GradingScaleService $scales,
        private StudentRequirementsService $requirements,
        private AcademicInputService $inputs,
        private DegreeProgressEvaluator $evaluator,
        private StudentRecordRepository $students,
        private StudentAccess $access,
        private UnitOfWork $transactions,
    ) {
    }

    /** Test the editor snapshot without publishing it or assigning it to the student.
     * @return array<string,mixed>
     */
    public function test(
        EntityId $template,
        EntityId $student,
        EntityId $assignment,
        AuditRequirementsV3 $definition,
        bool $useGroupDrafts = false,
        ?HistoryReadBudget $budget = null,
    ): array {
        return $this->run($student, $assignment, function (HistoryReadBudget $budget) use (
            $template,
            $definition,
            $useGroupDrafts,
        ): array {
            if ($definition->definition['groups'] === []) {
                throw new InvalidArgumentException('Add a course group before testing this degree draft.');
            }
            $row = $this->templates->get($template);
            $context = $this->templates->context(
                new EntityId($row['program_id']),
                new EntityId($row['academic_year_id']),
            );
            [$groups, $sources] = $this->groupInputs($definition, $useGroupDrafts, $budget);
            return [
                'source' => 'draft',
                'requirements' => [
                    'definition' => $definition->definition,
                    'group_details' => $groups,
                    'grade_scale' => $this->scales->resolve(new EntityId($row['program_id']), $budget),
                ],
                'academic_year_code' => $context['academic_year_code'],
                'program_code' => $context['program_code'],
                'program_title' => $context['program_title'],
                'sources' => $sources,
                'version' => null,
                'version_id' => null,
            ];
        }, $budget);
    }

    /** Exact immutable requirements, including retained grading context; no draft or current-scale fallback.
     * @return array<string,mixed>
     */
    public function testPublication(
        EntityId $template,
        EntityId $version,
        EntityId $student,
        EntityId $assignment,
        ?HistoryReadBudget $budget = null,
    ): array {
        return $this->run($student, $assignment, function (HistoryReadBudget $budget) use (
            $template,
            $version,
        ): array {
            $requirements = $this->published->version($template, $version, $budget);
            $sources = [];
            foreach ($requirements['group_details'] as $id => $group) {
                $sources[] = [
                    'group_id' => $id,
                    'name' => $group['definition']['name'],
                    'source' => 'publication',
                    'revision' => $group['version'],
                ];
            }
            return [
                'source' => 'publication',
                'requirements' => $requirements,
                'academic_year_code' => $requirements['context']['academic_year_code'],
                'program_code' => $requirements['context']['program_code'],
                'program_title' => $requirements['context']['program_title'],
                'sources' => $sources,
                'version' => $requirements['version'],
                'version_id' => $version->value,
            ];
        }, $budget);
    }

    /** Caller-supplied source resolution is internal; HTTP clients cannot supply academic inputs.
     * @param callable(HistoryReadBudget):array<string,mixed> $resolve
     * @return array<string,mixed>
     */
    private function run(
        EntityId $student,
        EntityId $assignment,
        callable $resolve,
        ?HistoryReadBudget $budget = null,
    ): array {
        $actor = $this->authorize($student, $assignment);
        try {
            return $this->transactions->run(function () use (
                $student,
                $assignment,
                $resolve,
                $budget,
                $actor,
            ): array {
                $this->students->lock($student);
                $this->authorize($student, $assignment, $actor);
                $budget ??= new HistoryReadBudget();
                $source = $resolve($budget);
                $requirements = $source['requirements'];
                $effective = [
                    'degree' => array_intersect_key(
                        $requirements['definition'],
                        array_flip(RequirementAdjustmentDefinitionV2::DEGREE_MINIMUMS),
                    ),
                    'groups' => array_map(
                        static fn(array $g): array => $g['definition'],
                        $requirements['group_details'],
                    ),
                    'waived_course_ids' => [],
                    'substitutions' => [],
                    'substitution_conflicts' => [],
                    'placements' => [],
                ];
                $inputs = $this->inputs->collect($student, $assignment, $budget);
                if ($inputs->status === AcademicInputStatus::Limited) {
                    throw new HistoryReadLimitException();
                }
                if ($inputs->status === AcademicInputStatus::Inconsistent) {
                    throw new CorruptHistoryException();
                }
                $result = $this->evaluator->evaluate($inputs, $requirements, $effective, $budget);
                $this->authorize($student, $assignment, $actor);
                return $result + [
                    'mode' => 'runtime_test',
                    'context' => [
                        'mode' => 'runtime_test',
                        'source' => $source['source'],
                        'name' => $requirements['definition']['name'],
                        'academic_year_code' => $source['academic_year_code'],
                        'program_code' => $source['program_code'],
                        'program_title' => $source['program_title'],
                        'group_reuse' => $requirements['definition']['policies']['group_reuse'] ?? null,
                        'cohort_changed' => false,
                        'version' => $source['version'],
                        'requirements_version_id' => $source['version_id'],
                    ],
                    'sources' => $source['sources'],
                    'definition_hash' => hash('sha256', json_encode($requirements, JSON_THROW_ON_ERROR)),
                    'exceptions_applied' => false,
                ];
            });
        } catch (HistoryReadLimitException | AllocationBudgetExhaustedException) {
            $status = 'limited';
        } catch (CorruptHistoryException | RecordNotFoundException) {
            $status = 'inconsistent';
        }
        $this->authorize($student, $assignment, $actor);
        return ['status' => $status, 'mode' => 'runtime_test'];
    }

    /** @return array{array<string,array<string,mixed>>,list<array<string,mixed>>} */
    private function groupInputs(AuditRequirementsV3 $definition, bool $drafts, HistoryReadBudget $budget): array
    {
        $references = [];
        foreach ($definition->definition['groups'] as $reference) {
            if (!$drafts && $reference['version_id'] !== null) {
                $references[] = $reference;
            }
        }
        $selected = $this->published->groupVersions($references, $budget);
        $groups = [];
        $sources = [];
        $courses = [];
        foreach ($definition->definition['groups'] as $reference) {
            $id = $reference['group_id'];
            if (isset($selected[$id])) {
                $groups[$id] = $selected[$id];
                $sources[] = [
                    'group_id' => $id,
                    'name' => $selected[$id]['definition']['name'],
                    'source' => 'publication',
                    'revision' => $selected[$id]['version'],
                ];
                continue;
            }
            $row = $this->groups->get(new EntityId($id));
            $d = CourseGroupDefinitionV2::draft($row['definition']);
            $d->assertPublishable();
            $details = [];
            foreach ($d->definition['courses'] as $course) {
                $ids = [$course['version_id'], ...($course['accepted_version_ids'] ?? [])];
                $missing = array_values(array_filter(
                    $ids,
                    static fn(string $v): bool => !isset($courses[$course['course_id'] . ':' . $v])
                ));
                if (count($courses) + count($missing) > 2000) {
                    throw new HistoryReadLimitException();
                }
                if ($missing !== []) {
                    $selectedVersions = $this->courses->selectedVersions(
                        new EntityId($course['course_id']),
                        array_map(static fn(string $v): EntityId => new EntityId($v), $missing),
                        $budget,
                    );
                    foreach ($selectedVersions as $version => $selectedVersion) {
                        $published = $selectedVersion['publication']->definition;
                        $courses[$course['course_id'] . ':' . $version] = [
                            'version_id' => $version, 'version' => $selectedVersion['version'],
                            'code' => $published->code,
                            'title' => $published->text->transcriptTitle ?? $published->title,
                            'credits_milli' => $published->creditsMilli,
                        ];
                    }
                }
                $details[$course['course_id']] = $courses[$course['course_id'] . ':' . $course['version_id']];
                if (($course['accepted_version_ids'] ?? []) !== []) {
                    $details[$course['course_id']]['accepted_versions'] = array_map(
                        static fn(string $v): array => $courses[$course['course_id'] . ':' . $v],
                        $course['accepted_version_ids'],
                    );
                }
            }
            $groups[$id] = ['definition' => $d->definition, 'version_id' => null, 'course_details' => $details];
            $sources[] = [
                'group_id' => $id,
                'name' => $d->definition['name'],
                'source' => 'draft',
                'revision' => $row['revision'],
            ];
        }
        return [$groups, $sources];
    }

    private function authorize(EntityId $student, EntityId $assignment, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $this->access->requirePermission('sis:student:read');
        $this->requirements->authorizeRead($student, $assignment);
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException('The signed-in account changed. Reload before testing.');
        }
        return $actor;
    }
}
