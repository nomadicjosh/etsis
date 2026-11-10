<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Exception\InstitutionalIdUnavailableException;
use Domain\Person\Person;
use Domain\Person\Service\InstitutionalIdGenerator;
use Domain\Person\ValueObject\InstitutionalId;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Student;

final readonly class AdmissionsService
{
    public function __construct(
        private AdmissionsRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private InstitutionalIdGenerator $identifiers,
        private AdmissionDecisionLedger $decisions,
        private ApplicationSubmissionService $submissions,
    ) {
    }

    public function createPerson(
        ?EntityId $ownerUserId,
        string $givenName,
        string $familyName,
        ?string $institutionalId = null,
    ): Person {
        $actor = $this->access->requirePermission("sis:person:manage");
        $assigned = $this->assignedId($institutionalId);
        $id = EntityId::generate();
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $person = new Person(
                $id,
                $ownerUserId,
                $givenName,
                $familyName,
                $assigned ?? $this->identifiers->next(),
            );
            try {
                $this->transactions->run(function () use ($person, $actor): void {
                    $this->repository->addPerson($person);
                    $this->audit->record($actor, "person.created", $person->id);
                });

                return $person;
            } catch (InstitutionalIdUnavailableException $error) {
                if ($assigned !== null) {
                    throw $error;
                }
            }
        }
        throw new \RuntimeException("Unable to allocate an institutional ID.");
    }

    public function person(EntityId $id): Person
    {
        $this->access->authenticatedUserId();
        $person = $this->repository->person($id);
        $this->access->requireOwnerOrPermission($person->ownerUserId, "sis:person:manage");

        return $person;
    }

    public function personByInstitutionalId(string $value): Person
    {
        $this->access->requirePermission("sis:person:manage");

        return $this->repository->personByInstitutionalId(new InstitutionalId($value));
    }

    public function updateInstitutionalId(EntityId $id, ?string $value, int $expectedVersion): Person
    {
        $actor = $this->access->requirePermission("sis:person:assign-id");
        $assigned = $value === null || trim($value) === "" ? null : new InstitutionalId($value);
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            try {
                return $this->transactions->run(function () use (
                    $id,
                    $assigned,
                    $expectedVersion,
                    $actor,
                ): Person {
                    $person = $this->repository->person($id);
                    if ($person->identityVersion !== $expectedVersion) {
                        throw new ConcurrentModificationException("The person identity changed. Reload it.");
                    }
                    $changed = $person->withInstitutionalId($assigned ?? $this->identifiers->next());
                    if ($changed !== $person) {
                        $this->repository->saveInstitutionalId($changed, $expectedVersion);
                        $this->audit->record($actor, "person.institutional_id_changed", $id);
                    }

                    return $changed;
                });
            } catch (InstitutionalIdUnavailableException $error) {
                if ($assigned !== null) {
                    throw $error;
                }
            }
        }
        throw new \RuntimeException("Unable to allocate an institutional ID.");
    }

    private function assignedId(?string $value): ?InstitutionalId
    {
        if ($value === null || trim($value) === "") {
            return null;
        }
        $this->access->requirePermission("sis:person:assign-id");

        return new InstitutionalId($value);
    }

    public function apply(
        EntityId $person,
        EntityId $program,
        EntityId $term,
        EntityId $application,
    ): AdmissionApplication {
        return $this->submissions->apply($application, $person, $program, $term);
    }

    /** @return array{application: AdmissionApplication, person: \Domain\Person\Person} */
    public function review(EntityId $id): array
    {
        $actor = $this->access->requirePermission("sis:admissions:manage");
        $route = $this->repository->application($id);
        return $this->transactions->run(function () use ($id, $route, $actor): array {
            $this->repository->lockPerson($route->personId);
            $this->repository->lockApplication($id);
            if ($this->access->requirePermission("sis:admissions:manage")->value !== $actor->value) {
                throw new \Domain\Shared\Exception\AccessDeniedException("The admissions actor changed.");
            }
            $application = $this->repository->application($id);
            if ($application->personId->value !== $route->personId->value) {
                throw new ConcurrentModificationException("The application changed. Reload it.");
            }
            $this->decisions->verify($application);
            return [
                "application" => $application,
                "person" => $this->repository->person($application->personId),
            ];
        });
    }

    public function student(EntityId $studentId): Student
    {
        return $this->studentProfile($studentId)->student;
    }

    public function studentProfile(EntityId $studentId): \Domain\Student\Dto\StudentProfile
    {
        $this->access->authenticatedUserId();
        $student = $this->repository->student($studentId);
        $person = $this->repository->person($student->personId);
        $this->access->requireOwnerOrPermission($person->ownerUserId, "sis:student:read");

        return new \Domain\Student\Dto\StudentProfile($student, $person);
    }
}
