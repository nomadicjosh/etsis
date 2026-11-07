<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Catalog\Course;
use Domain\Registration\Enrollment;
use Domain\Registration\RegistrationWindow;
use Domain\Registration\Section;
use Domain\Registration\Term;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;

interface RegistrationRepository
{
    public function course(EntityId $id): Course;
    public function section(EntityId $id): Section;
    public function term(EntityId $id): Term;
    public function window(EntityId $termId): RegistrationWindow;
    public function enrollment(EntityId $student, EntityId $section): ?Enrollment;
    public function lockStudent(EntityId $id): void;
    public function lockSection(EntityId $id): void;
    public function enrolledCount(EntityId $student, EntityId $term): int;
    public function sectionCount(EntityId $section): int;
    public function prerequisitesMet(EntityId $student, EntityId $course): bool;
    /** @return list<Restriction> */
    public function restrictions(EntityId $person): array;
    public function addCourse(Course $course): void;
    public function addSection(Section $section): void;
    public function configureWindow(EntityId $termId, RegistrationWindow $window): void;
    public function removePrerequisite(EntityId $course, EntityId $required): void;
    public function addPrerequisite(EntityId $course, EntityId $required): void;
    public function addRestriction(Restriction $restriction): void;
    public function endRestriction(EntityId $id, \DateTimeImmutable $on): EntityId;
    public function addEnrollment(Enrollment $enrollment, Course $course): void;
    public function saveDrop(Enrollment $enrollment, int $expectedVersion): void;
}
