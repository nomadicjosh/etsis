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
final readonly class DraftDegreeAuditService
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

    /** Tests the supplied editor snapshot; group draft choices are explicit, never published automatically.
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
        $actor = $this->authorize($student, $assignment);
        if ($definition->definition['groups'] === []) {
            throw new InvalidArgumentException('Add a course group before testing this degree draft.');
        }
        try {
            return $this->transactions->run(function () use (
                $template,
                $student,
                $assignment,
                $definition,
                $useGroupDrafts,
                $budget,
                $actor,
            ): array {
                $this->students->lock($student);
                $this->authorize($student, $assignment, $actor);
                $row = $this->templates->get($template);
                $pursuit = $this->students->assignment($assignment);
                if ($pursuit->programId->value !== $row['program_id']) {
                    throw new InvalidArgumentException('Choose a pursuit in the degree draft\'s academic program.');
                }
                $budget ??= new HistoryReadBudget();
                $context = $this->templates->context(
                    new EntityId($row['program_id']),
                    new EntityId($row['academic_year_id']),
                );
                [$groups, $sources] = $this->groupInputs($definition, $useGroupDrafts, $budget);
                $requirements = [
                    'definition' => $definition->definition,
                    'group_details' => $groups,
                    'grade_scale' => $this->scales->resolve(new EntityId($row['program_id']), $budget),
                ];
                $effective = [
                    'degree' => array_intersect_key(
                        $definition->definition,
                        array_flip(RequirementAdjustmentDefinitionV2::DEGREE_MINIMUMS),
                    ),
                    'groups' => array_map(static fn(array $g): array => $g['definition'], $groups),
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
                    'mode' => 'draft_test',
                    'context' => [
                        'mode' => 'draft_test',
                        'name' => $definition->definition['name'],
                        'academic_year_code' => $context['academic_year_code'],
                        'group_reuse' => $definition->groupReuse->value,
                        'cohort_changed' => false,
                        'version' => null,
                        'requirements_version_id' => null,
                    ],
                    'sources' => $sources,
                    'draft_hash' => hash('sha256', json_encode($requirements, JSON_THROW_ON_ERROR)),
                    'exceptions_applied' => false,
                ];
            });
        } catch (HistoryReadLimitException | AllocationBudgetExhaustedException) {
            $status = 'limited';
        } catch (CorruptHistoryException | RecordNotFoundException) {
            $status = 'inconsistent';
        }
        $this->authorize($student, $assignment, $actor);
        return ['status' => $status, 'mode' => 'draft_test'];
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
                $key = $course['course_id'] . ':' . $course['version_id'];
                if (!isset($courses[$key])) {
                    if (count($courses) >= 200) {
                        throw new HistoryReadLimitException();
                    }
                    $published = $this->courses->version(
                        new EntityId($course['course_id']),
                        new EntityId($course['version_id']),
                        $budget,
                    );
                    $courses[$key] = [
                        'code' => $published->definition->code,
                        'title' => $published->definition->text->transcriptTitle ?? $published->definition->title,
                        'credits_milli' => $published->definition->creditsMilli,
                    ];
                }
                $details[$course['course_id']] = $courses[$key];
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
