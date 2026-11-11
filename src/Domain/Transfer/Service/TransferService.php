<?php

declare(strict_types=1);

namespace Domain\Transfer\Service;

use DateTimeImmutable;
use DomainException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferRepository;
use Domain\Transfer\TransferEquivalency;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Transfer\Repository\TransferCatalogRepository;
use Domain\Transfer\ValueObject\TransferLabel;
use Domain\Transfer\ValueObject\TransferRevision;

final readonly class TransferService
{
    public function __construct(
        private TransferRepository $repository,
        private RegistrationRepository $registration,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private TransferCatalogRepository $catalog,
        private TransferCreditLedger $credits,
    ) {
    }

    public function addInstitution(string $code, string $name): EntityId
    {
        $actor = $this->access->requirePermission("sis:transfer:manage");
        $label = new TransferLabel($code, $name, "name");
        $id = EntityId::generate();
        $this->transactions->run(function () use ($id, $label, $actor): void {
            $this->repository->addInstitution($id, $label->code, $label->title);
            $this->audit->record($actor, "transfer.institution_created", $id);
        });

        return $id;
    }

    public function addExternalCourse(EntityId $institution, string $code, string $title): EntityId
    {
        $actor = $this->access->requirePermission("sis:transfer:manage");
        $label = new TransferLabel($code, $title);
        $id = EntityId::generate();
        $this->transactions->run(function () use ($id, $institution, $label, $actor): void {
            $this->catalog->lock("institutions", $institution);
            $this->repository->addExternalCourse($id, $institution, $label->code, $label->title);
            $this->audit->record($actor, "transfer.course_created", $id);
        });

        return $id;
    }

    public function equate(TransferEquivalency $equivalency): void
    {
        $actor = $this->access->requirePermission("sis:transfer:manage");
        $this->transactions->run(function () use ($equivalency, $actor): void {
            $this->catalog->lock("institutions", $equivalency->externalInstitutionId);
            $this->catalog->lock("external", $equivalency->externalCourseId);
            $this->catalog->lock("courses", $equivalency->institutionalCourseId);
            $this->repository->addEquivalency($equivalency);
            $this->audit->record($actor, "transfer.equated", $equivalency->id);
        });
    }

    public function award(
        EntityId $student,
        EntityId $equivalencyId,
        EntityId $institution,
        EntityId $term,
        DateTimeImmutable $completedOn,
        int $gradeRank,
        string $revision,
        EntityId $courseVersion,
    ): EntityId {
        $actor = $this->access->requirePermission("sis:transfer:manage");
        $initial = $this->catalog->get("equivalencies", $equivalencyId);
        $this->access->requirePermission("sis:student:read");

        return $this->transactions->run(function () use (
            $student,
            $equivalencyId,
            $institution,
            $term,
            $completedOn,
            $gradeRank,
            $actor,
            $revision,
            $initial,
            $courseVersion,
        ): EntityId {
            $this->registration->lockStudent($student);
            TransferCatalogLock::acquire($this->catalog, "equivalencies", $initial);
            $this->admissions->student($student);
            $reviewed = $this->catalog->get("equivalencies", $equivalencyId);
            if (!hash_equals(TransferRevision::of("equivalencies", $reviewed), $revision)) {
                throw new ConcurrentModificationException(
                    "The reviewed equivalency changed. Reload before awarding credit.",
                );
            }
            if (!(bool) $reviewed["passes"]) {
                throw new DomainException("A transfer award requires a passing grade.");
            }
            $equivalency = new TransferEquivalency(
                $equivalencyId,
                new EntityId($reviewed["institution_id"]),
                new EntityId($reviewed["external_course_id"]),
                new EntityId($reviewed["course_id"]),
                new DateRange(
                    new DateTimeImmutable($reviewed["starts_on"]),
                    $reviewed["ends_on"] === null ? null : new DateTimeImmutable($reviewed["ends_on"]),
                ),
                $reviewed["minimum_grade_rank"],
                $reviewed["credits_milli"],
                $reviewed["awarded_grade"],
            );
            $equivalency->assertEligible($institution, $completedOn, $gradeRank);
            $this->awardActor($actor);
            $credit = $this->credits->award(
                $student,
                $equivalency,
                $term,
                $completedOn,
                $gradeRank,
                $reviewed,
                $actor,
                $courseVersion,
            );
            $this->audit->record($actor, "transfer.awarded", $credit);
            $this->awardActor($actor);

            return $credit;
        });
    }

    /** @return list<array<string, mixed>> */
    public function transcript(EntityId $studentId): array
    {
        $this->access->authenticatedUserId();
        $student = $this->admissions->student($studentId);
        $person = $this->admissions->person($student->personId);
        $this->access->requireOwnerOrPermission($person->ownerUserId, "sis:student:read");

        return $this->repository->transcript($studentId);
    }
    /** Curated choices require transfer/student access rather than catalog-edit authority.
     * @return array<string,mixed>
     */
    public function courseVersions(EntityId $equivalency, ?string $cursor = null): array
    {
        $actor = $this->access->requirePermission("sis:transfer:manage");
        $this->access->requirePermission("sis:student:read");
        $row = $this->catalog->get("equivalencies", $equivalency);
        $page = $this->credits->courseChoices(new EntityId($row["course_id"]), $cursor);
        $this->awardActor($actor);
        return $page;
    }
    private function awardActor(EntityId $actor): void
    {
        $current = $this->access->requirePermission("sis:transfer:manage");
        $this->access->requirePermission("sis:student:read");
        if ($current->value !== $actor->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException("The transfer decision actor changed.");
        }
    }
}
