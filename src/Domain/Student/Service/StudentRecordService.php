<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Student;
use Domain\Student\StudentProgram;
use DomainException;

final readonly class StudentRecordService
{
    public function __construct(
        private StudentRecordRepository $records,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private \Domain\Catalog\Service\CatalogEnrollment $catalogs,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(EntityId $id): array
    {
        $student = $this->read($id);
        $person = $this->admissions->person($student->personId);

        return ['id' => $id->value, 'person_id' => $person->id->value,
            'given_name' => $person->givenName, 'family_name' => $person->familyName,
            'institutional_id' => $person->institutionalId->value, 'active' => $student->active,
            'primary_program' => $this->records->program($student->programId),
            'entry_term' => $this->records->term($student->entryTermId),
            'catalog' => $this->records->primaryCatalog($id), 'revision' => $this->revision($student)];
    }

    public function activity(EntityId $id, bool $active, string $revision): void
    {
        $actor = $this->access->requirePermission('sis:registration:manage');
        $this->transactions->run(function () use ($id, $active, $revision, $actor): void {
            $this->records->lock($id);
            $student = $this->admissions->student($id);
            $this->check($this->revision($student), $revision);
            if ($student->active === $active) {
                return;
            }
            $this->records->setActive($id, $active);
            $this->audit->record($actor, 'student.activity_updated', $id);
        });
    }

    public function assign(EntityId $id, EntityId $program, EntityId $term): EntityId
    {
        $actor = $this->access->requirePermission('sis:registration:manage');
        return $this->transactions->run(function () use ($id, $program, $term, $actor): EntityId {
            $this->records->lock($id);
            $this->admissions->student($id);
            $this->records->program($program);
            $this->records->term($term);
            $this->assertNoActiveProgram($id, $program);
            $assignment = new StudentProgram(EntityId::generate(), $id, $program, $term);
            $this->records->addAssignment($assignment);
            $this->catalogs->initialize($assignment, $actor);
            $this->audit->record($actor, 'student.program_assigned', $id);

            return $assignment->id;
        });
    }

    public function primary(EntityId $id, EntityId $assignmentId, string $revision): void
    {
        $actor = $this->access->requirePermission('sis:registration:manage');
        $this->transactions->run(function () use ($id, $assignmentId, $revision, $actor): void {
            $this->records->lock($id);
            $student = $this->admissions->student($id);
            $this->check($this->revision($student), $revision);
            $assignment = $this->ownedAssignment($id, $assignmentId);
            if ($assignment->status !== 'active') {
                throw new DomainException('The primary program must be active.');
            }
            if ($assignment->programId->value === $student->programId->value) {
                return;
            }
            $this->records->setPrimary($id, $assignment->programId);
            $this->audit->record($actor, 'student.primary_program_changed', $id);
        });
    }

    public function programActivity(EntityId $id, EntityId $assignmentId, bool $active, string $revision): void
    {
        $actor = $this->access->requirePermission('sis:registration:manage');
        $this->transactions->run(function () use ($id, $assignmentId, $active, $revision, $actor): void {
            $this->records->lock($id);
            $student = $this->admissions->student($id);
            $assignment = $this->ownedAssignment($id, $assignmentId);
            $this->check($this->programRevision($assignment), $revision);
            $status = $active ? 'active' : 'closed';
            if ($assignment->status === $status) {
                return;
            }
            if (!$active && $assignment->programId->value === $student->programId->value) {
                throw new DomainException('Choose another primary program before closing this assignment.');
            }
            if ($active) {
                $this->assertNoActiveProgram($id, $assignment->programId, $assignmentId);
            }
            $this->records->saveAssignment(new StudentProgram(
                $assignment->id,
                $id,
                $assignment->programId,
                $assignment->entryTermId,
                $status
            ));
            $this->audit->record($actor, $active ? 'student.program_reopened' : 'student.program_closed', $id);
        });
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function programs(EntityId $id, ?EntityId $after = null): array
    {
        $student = $this->read($id);
        $rows = $this->records->assignments($id, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as &$row) {
            $assignment = new StudentProgram(
                new EntityId($row['id']),
                $id,
                new EntityId($row['program_id']),
                new EntityId($row['entry_term_id']),
                $row['status']
            );
            $row['revision'] = $this->programRevision($assignment);
            $row['is_primary'] = $assignment->status === 'active'
            && $assignment->programId->value === $student->programId->value;
        }
        unset($row);

        return ['items' => $rows, 'next_after' => $more ? (string) $rows[49]['id'] : null];
    }

    private function read(EntityId $id): Student
    {
        $this->access->authenticatedUserId();
        $student = $this->admissions->student($id);
        $this->access->requireOwnerOrPermission(
            $this->admissions->person($student->personId)->ownerUserId,
            'sis:student:read'
        );

        return $student;
    }

    private function ownedAssignment(EntityId $student, EntityId $id): StudentProgram
    {
        $assignment = $this->records->assignment($id);
        if ($assignment->studentId->value !== $student->value) {
            throw new RecordNotFoundException('Program assignment not found.');
        }

        return $assignment;
    }

    private function assertNoActiveProgram(EntityId $student, EntityId $program, ?EntityId $excluding = null): void
    {
        if ($this->records->activeProgram($student, $program, $excluding)) {
            throw new ValidationException([['field' => 'program_id',
                'message' => 'This program already has an active assignment for the student.']]);
        }
    }

    private function check(string $current, string $expected): void
    {
        if (!hash_equals($current, $expected)) {
            throw new ConcurrentModificationException('Reload student records before saving.');
        }
    }

    private function revision(Student $student): string
    {
        return hash('sha256', json_encode([$student->id->value, $student->personId->value,
            $student->programId->value, $student->entryTermId->value, $student->active], JSON_THROW_ON_ERROR));
    }

    private function programRevision(StudentProgram $program): string
    {
        return hash('sha256', json_encode([$program->id->value, $program->studentId->value,
            $program->programId->value, $program->entryTermId->value, $program->status], JSON_THROW_ON_ERROR));
    }
}
