<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DomainException;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Course;
use Domain\Registration\RegistrationWindow;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Section;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class RegistrarCatalogService
{
    public function __construct(
        private RegistrationRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    public function addCourse(Course $course): void
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $this->transactions->run(function () use ($course, $actor): void {
            $this->repository->addCourse($course);
            $this->audit->record($actor, 'course.created', $course->id);
        });
    }

    public function offer(Section $section): void
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $this->transactions->run(function () use ($section, $actor): void {
            $this->repository->addSection($section);
            $this->audit->record($actor, 'section.created', $section->id);
        });
    }

    public function configureWindow(EntityId $term, RegistrationWindow $window): void
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $this->transactions->run(function () use ($term, $window, $actor): void {
            $this->repository->configureWindow($term, $window);
            $this->audit->record($actor, 'registration.window_changed', $term);
        });
    }

    public function requireCourse(EntityId $course, EntityId $required): void
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        if ($course->value === $required->value) {
            throw new DomainException('A course cannot require itself.');
        }
        $this->transactions->run(function () use ($course, $required, $actor): void {
            $ids = [$course->value, $required->value];
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $this->repository->lockCourse(new EntityId($id));
            }
            $this->repository->addPrerequisite($course, $required);
            $this->audit->record($actor, 'course.prerequisite_added', $course);
        });
    }

    public function removeRequiredCourse(EntityId $course, EntityId $required): void
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $this->transactions->run(function () use ($course, $required, $actor): void {
            $ids = [$course->value, $required->value];
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $this->repository->lockCourse(new EntityId($id));
            }
            $this->repository->removePrerequisite($course, $required);
            $this->audit->record($actor, 'course.prerequisite_removed', $course);
        });
    }
}
