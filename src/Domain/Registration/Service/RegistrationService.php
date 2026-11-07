<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DomainException;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Enrollment;
use Domain\Registration\Enum\RegistrationStatus;
use Domain\Registration\RegistrationPolicy;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class RegistrationService
{
    public function __construct(
        private RegistrationRepository $repository,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private RegistrationBilling $billing,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    public function register(EntityId $studentId, EntityId $sectionId, bool $byStaff = false): Enrollment
    {
        $actor = $this->access->authenticatedUserId();
        $owner = $this->admissions->person($this->admissions->student($studentId)->personId)->ownerUserId;
        $this->access->requireOwnerOrPermission($owner, 'sis:registration:manage');
        if ($byStaff) {
            $this->access->requirePermission('sis:registration:manage');
        }

        return $this->transactions->run(function () use ($studentId, $sectionId, $byStaff, $actor): Enrollment {
            // Lock the student first to protect term limits across different sections, then seats for this section.
            $this->repository->lockStudent($studentId);
            $this->repository->lockSection($sectionId);
            $student = $this->admissions->student($studentId);
            $person = $this->admissions->person($student->personId);
            $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:registration:manage');
            $byStaff = $byStaff || $person->ownerUserId?->value !== $actor->value;
            if ($byStaff) {
                $this->access->requirePermission('sis:registration:manage');
            }
            $section = $this->repository->section($sectionId);
            $course = $this->repository->course($section->courseId);
            $window = $this->repository->window($section->termId);
            $existing = $this->repository->enrollment($studentId, $sectionId);
            if ($existing !== null) {
                if ($existing->status->isEnrolled()) {
                    return $existing;
                }
                throw new DomainException('A closed enrollment requires registrar review before re-enrollment.');
            }
            if (
                !$section->active || !$course->active
                || $this->repository->sectionCount($sectionId) >= $section->capacity
            ) {
                throw new DomainException('The section is inactive or full.');
            }
            $now = $this->clock->now();
            new RegistrationPolicy()->assertEligible(
                $student,
                $this->repository->restrictions($student->personId),
                $now,
                $byStaff,
                $window->isOpen($now),
                $section->webRegistration,
                $this->repository->enrolledCount($studentId, $section->termId),
                $window->courseLimit,
                $this->repository->prerequisitesMet($studentId, $section->courseId)
            );
            $enrollment = new Enrollment(
                EntityId::generate(),
                $studentId,
                $sectionId,
                $section->termId,
                $byStaff ? RegistrationStatus::StaffRegistered : RegistrationStatus::SelfRegistered,
                $now,
                $now
            );
            $this->repository->addEnrollment($enrollment, $course);
            $this->billing->charge($studentId, $section->termId, $enrollment->id, $section->fee, $actor);
            $this->audit->record($actor, 'registration.created', $enrollment->id);

            return $enrollment;
        });
    }

    public function drop(EntityId $studentId, EntityId $sectionId): Enrollment
    {
        $actor = $this->access->authenticatedUserId();
        $owner = $this->admissions->person($this->admissions->student($studentId)->personId)->ownerUserId;
        $this->access->requireOwnerOrPermission($owner, 'sis:registration:manage');

        return $this->transactions->run(function () use ($studentId, $sectionId, $actor): Enrollment {
            $this->repository->lockStudent($studentId);
            $this->repository->lockSection($sectionId);
            $student = $this->admissions->student($studentId);
            $person = $this->admissions->person($student->personId);
            $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:registration:manage');
            $enrollment = $this->repository->enrollment($studentId, $sectionId)
                ?? throw new RecordNotFoundException('Enrollment not found.');
            $dropped = $enrollment->drop($this->repository->term($enrollment->termId), $this->clock->now());
            $this->repository->saveDrop($dropped, $enrollment->version);
            $this->audit->record($actor, 'registration.dropped', $enrollment->id);

            return $dropped;
        });
    }
}
