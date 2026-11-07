<?php

declare(strict_types=1);

namespace Domain\Transfer\Service;

use DateTimeImmutable;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferRepository;
use Domain\Transfer\TransferEquivalency;
use InvalidArgumentException;

final readonly class TransferService
{
    public function __construct(
        private TransferRepository $repository,
        private RegistrationRepository $registration,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    public function addInstitution(string $code, string $name): EntityId
    {
        $actor = $this->access->requirePermission('sis:transfer:manage');
        $this->validateLabel($code, $name);
        $id = EntityId::generate();
        $this->transactions->run(function () use ($id, $code, $name, $actor): void {
            $this->repository->addInstitution($id, $code, $name);
            $this->audit->record($actor, 'transfer.institution_created', $id);
        });

        return $id;
    }

    public function addExternalCourse(EntityId $institution, string $code, string $title): EntityId
    {
        $actor = $this->access->requirePermission('sis:transfer:manage');
        $this->validateLabel($code, $title);
        $id = EntityId::generate();
        $this->transactions->run(function () use ($id, $institution, $code, $title, $actor): void {
            $this->repository->addExternalCourse($id, $institution, $code, $title);
            $this->audit->record($actor, 'transfer.course_created', $id);
        });

        return $id;
    }

    public function equate(TransferEquivalency $equivalency): void
    {
        $actor = $this->access->requirePermission('sis:transfer:manage');
        $this->transactions->run(function () use ($equivalency, $actor): void {
            $this->repository->addEquivalency($equivalency);
            $this->audit->record($actor, 'transfer.equated', $equivalency->id);
        });
    }

    public function award(
        EntityId $student,
        EntityId $equivalencyId,
        EntityId $institution,
        EntityId $term,
        DateTimeImmutable $completedOn,
        int $gradeRank
    ): EntityId {
        $actor = $this->access->requirePermission('sis:transfer:manage');

        return $this->transactions->run(function () use (
            $student,
            $equivalencyId,
            $institution,
            $term,
            $completedOn,
            $gradeRank,
            $actor
): EntityId {
            $this->registration->lockStudent($student);
            $this->admissions->student($student);
            $equivalency = $this->repository->equivalency($equivalencyId);
            $equivalency->assertEligible($institution, $completedOn, $gradeRank);
            $credit = $this->repository->award(EntityId::generate(), $student, $equivalency, $term, $completedOn);
            $this->audit->record($actor, 'transfer.awarded', $credit);

            return $credit;
        });
    }

    /** @return list<array<string, mixed>> */
    public function transcript(EntityId $studentId): array
    {
        $this->access->authenticatedUserId();
        $student = $this->admissions->student($studentId);
        $person = $this->admissions->person($student->personId);
        $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:student:read');

        return $this->repository->transcript($studentId);
    }

    private function validateLabel(string $code, string $title): void
    {
        if (trim($code) === '' || strlen($code) > 80 || trim($title) === '' || mb_strlen($title) > 191) {
            throw new InvalidArgumentException('A code and title are required.');
        }
    }
}
