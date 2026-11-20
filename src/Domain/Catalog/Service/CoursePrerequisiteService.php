<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\CatalogWorkflowRepository;
use Domain\Catalog\ValueObject\PrerequisiteGroups;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class CoursePrerequisiteService
{
    public function __construct(
        private CatalogRevisionRepository $catalog,
        private CatalogWorkflowRepository $relationships,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function current(EntityId $course): array
    {
        $actor = $this->authorize();
        $context = $this->context($course);
        $this->authorize($actor);
        return $context + ['revision' => $this->revision($context)];
    }

    /** @param list<list<string>> $choices
     * @param list<int|null>|null $gradePointsMilli
     * @param list<int>|null $minimums
     * @return array<string,mixed> */
    public function save(
        EntityId $course,
        array $choices,
        string $revision,
        ?array $minimums = null,
        ?array $gradePointsMilli = null,
        ?array $corequisites = null,
    ): array {
        $actor = $this->authorize();
        $groups = new PrerequisiteGroups($choices, $minimums, $gradePointsMilli, $corequisites);
        if (in_array($course->value, $groups->references(), true)) {
            throw new ValidationException([['field' => 'groups', 'message' => 'A course cannot require itself.']]);
        }
        $routing = array_unique([$course->value, ...$groups->references(),
            ...($this->relationships->prerequisiteGroups($course)?->references() ??
            array_column($this->relationships->prerequisites($course), 'id'))]);
        sort($routing, SORT_STRING);
        return $this->transactions->run(function () use ($course, $groups, $revision, $routing, $actor): array {
            foreach ($routing as $id) {
                $this->catalog->lock('courses', new EntityId($id));
            }
            $this->authorize($actor);
            $context = $this->context($course);
            if (!hash_equals($this->revision($context), $revision)) {
                throw new ConcurrentModificationException('Reload the prerequisite editor before saving your changes.');
            }
            $previous = array_column($context['courses'], 'id');
            $labels = $this->relationships->prerequisiteLabels($groups->references());
            if (count($labels) !== count($groups->references())) {
                throw new ValidationException([['field' => 'groups',
                    'message' => 'Choose existing prerequisite courses.']]);
            }
            foreach ($labels as $label) {
                if (!(bool) $label['active'] && !in_array($label['id'], $previous, true)) {
                    throw new ValidationException([['field' => 'groups',
                        'message' => 'Choose active prerequisite courses.']]);
                }
            }
            $this->relationships->savePrerequisiteGroups($course, $groups);
            $this->audit->record($actor, 'course.prerequisite_groups_saved', $course);
            $this->authorize($actor);
            return $this->current($course);
        });
    }

    /** @param list<string> $ids
     * @return array{items:array} */
    public function labels(array $ids): array
    {
        $actor = $this->authorize();
        $labels = $this->relationships->prerequisiteLabels($ids);
        $this->authorize($actor);
        return ['items' => $labels];
    }

    /** @return array<string,mixed> */
    private function context(EntityId $course): array
    {
        $row = $this->catalog->get('courses', $course);
        $courses = $this->relationships->prerequisites($course);
        $groups = $this->relationships->prerequisiteGroups($course) ??
        PrerequisiteGroups::all(array_column($courses, 'id'));
        return ['course' => ['id' => $course->value, 'code' => $row['code'], 'title' => $row['title']],
            'groups' => $groups->groups, 'minimums' => $groups->counts(),
            'grade_points_milli' => $groups->gradeFloors(), 'corequisites' => $groups->corequisites ?? [],
            'courses' => $this->relationships->prerequisiteLabels($groups->references())];
    }

    /** @param array<string,mixed> $context */
    private function revision(array $context): string
    {
        return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException('The catalog staff account changed. Reload the prerequisite editor.');
        }
        return $actor;
    }
}
