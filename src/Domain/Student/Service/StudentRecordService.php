<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Student;
use Domain\Student\StudentProgram;

final readonly class StudentRecordService
{
    public function __construct(
        private StudentRecordRepository $records,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private \Domain\Catalog\Service\CatalogEnrollment $catalogs,
        private StudentActivityLedger $activity,
        private StudentProgramStatusLedger $programStatuses,
        private StudentPrimaryProgramLedger $primaryPrograms,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(EntityId $id): array
    {
        return $this->transactions->run(function () use ($id): array {
            $this->records->lock($id);
            $student = $this->read($id);
            $this->activity->verify($student);
            $this->primaryPrograms->verify($student);
            $person = $this->admissions->person($student->personId);

            return [
                "id" => $id->value,
                "person_id" => $person->id->value,
                "given_name" => $person->givenName,
                "family_name" => $person->familyName,
                "institutional_id" => $person->institutionalId->value,
                "active" => $student->active,
                "primary_program" => $this->records->program($student->programId),
                "entry_term" => $this->records->term($student->entryTermId),
                "catalog" => $this->records->primaryCatalog($id),
                "revision" => $this->revision($student),
            ];
        });
    }

    public function assign(EntityId $id, EntityId $program, EntityId $term): EntityId
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        return $this->transactions->run(function () use ($id, $program, $term, $actor): EntityId {
            $this->records->lock($id);
            $this->registrar($actor);
            $this->admissions->student($id);
            $this->records->program($program);
            $this->records->term($term);
            $this->programStatuses->assertNoActiveProgram($id, $program);
            $assignment = new StudentProgram(EntityId::generate(), $id, $program, $term);
            $this->records->addAssignment($assignment);
            $this->catalogs->initialize($assignment, $actor);
            $this->audit->record($actor, "student.program_assigned", $id);

            return $assignment->id;
        });
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function programs(EntityId $id, ?EntityId $after = null): array
    {
        return $this->transactions->run(function () use ($id, $after): array {
            $this->records->lock($id);
            $student = $this->read($id);
            $this->primaryPrograms->verify($student);
            $rows = $this->records->assignments($id, $after, 51);
            $more = count($rows) > 50;
            $rows = array_slice($rows, 0, 50);
            foreach ($rows as &$row) {
                $assignment = new StudentProgram(
                    new EntityId($row["id"]),
                    $id,
                    new EntityId($row["program_id"]),
                    new EntityId($row["entry_term_id"]),
                    $row["status"],
                );
                $this->programStatuses->verify($assignment);
                $row["revision"] = $this->programRevision($assignment);
                $row["is_primary"] =
                    $assignment->status === "active" &&
                    $assignment->programId->value === $student->programId->value;
            }
            unset($row);

            return ["items" => $rows, "next_after" => $more ? (string) $rows[49]["id"] : null];
        });
    }

    private function read(EntityId $id): Student
    {
        $this->access->authenticatedUserId();
        $student = $this->admissions->student($id);
        $this->access->requireOwnerOrPermission(
            $this->admissions->person($student->personId)->ownerUserId,
            "sis:student:read",
        );

        return $student;
    }

    private function registrar(EntityId $actor): void
    {
        if ($this->access->requirePermission("sis:registration:manage")->value !== $actor->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException("The registrar actor changed.");
        }
    }

    private function revision(Student $student): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $student->id->value,
                    $student->personId->value,
                    $student->programId->value,
                    $student->entryTermId->value,
                    $student->active,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function programRevision(StudentProgram $program): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $program->id->value,
                    $program->studentId->value,
                    $program->programId->value,
                    $program->entryTermId->value,
                    $program->status,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
