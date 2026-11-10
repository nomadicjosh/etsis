<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\RegistrationBatchRepository;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DomainException;

final readonly class RegistrationBatchService
{
    public function __construct(
        private RegistrationBatchRepository $batches,
        private RegistrationRepository $repository,
        private AdmissionsRepository $admissions,
        private RegistrationService $registration,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private SectionVersionRepository $versions,
    ) {
    }

    /** @return array<string, mixed> */
    public function review(RegistrationBatchSelection $selection): array
    {
        $this->access->requirePermission('sis:registration:manage');
        $routing = $this->routing($selection);
        return $this->transactions->run(function () use ($selection, $routing): array {
            $this->lock($selection, $routing);
            $context = $this->context($selection);
            return $context + ['revision' => $this->revision($context), 'operation_id' => EntityId::generate()->value];
        });
    }

    /** @return array<string, mixed> */
    public function commit(EntityId $operation, RegistrationBatchSelection $selection, string $revision): array
    {
        $actor = $this->access->requirePermission('sis:registration:manage');
        $hash = $selection->requestHash($revision);
        if (($receipt = $this->batches->receipt($operation)) !== null) {
            return $this->replay($receipt, $actor, $hash);
        }
        $routing = $this->routing($selection);
        return $this->transactions->run(function () use (
            $operation,
            $selection,
            $revision,
            $hash,
            $actor,
            $routing
        ): array {
            $this->lock($selection, $routing);
            // A matching concurrent request may have committed while these locks were being acquired.
            if (($receipt = $this->batches->receipt($operation)) !== null) {
                return $this->replay($receipt, $actor, $hash);
            }
            $context = $this->context($selection);
            if (!hash_equals($this->revision($context), $revision)) {
                throw new ConcurrentModificationException('Review the current batch before confirming.');
            }
            $items = [];
            $created = 0;
            foreach ($context['students'] as $row) {
                try {
                    $enrollment = $this->registration->register(new EntityId($row['id']), $selection->section, true);
                } catch (DomainException) {
                    throw new ValidationException([['field' => 'students', 'message' => $row['institutional_id']
                        . ': Registration could not be completed. Check activity, holds, prerequisites, course limits '
                        . 'available seats and billing configuration. No changes in this batch were saved.']]);
                }
                $new = $row['enrollment_id'] === null;
                $created += (int) $new;
                $items[] = ['student_id' => $row['id'], 'enrollment_id' => $enrollment->id->value, 'created' => $new];
            }
            $result = ['batch_id' => $operation->value, 'section_id' => $selection->section->value,
                'created' => $created, 'existing' => count($items) - $created, 'registrations' => $items];
            $this->batches->save(
                $operation,
                $actor,
                $selection->section,
                $hash,
                $result,
                $this->clock->now()->format('Y-m-d\TH:i:sP')
            );
            $this->audit->record($actor, 'registration.batch_completed', $operation);
            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function receipt(EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:registration:manage');
        $receipt = $this->batches->receipt($id) ?? throw new RecordNotFoundException('Batch receipt not found.');
        $this->owner($receipt['actor_id'], $actor);
        return $receipt['result'];
    }

    /** @param array{actor_id: string, request_hash: string, result: array<string, mixed>} $receipt
     * @return array<string, mixed>
     */
    private function replay(array $receipt, EntityId $actor, string $hash): array
    {
        $this->owner($receipt['actor_id'], $actor);
        if (!hash_equals($receipt['request_hash'], $hash)) {
            throw new ConcurrentModificationException('This operation belongs to a different batch request.');
        }
        return $receipt['result'];
    }

    private function owner(string $owner, EntityId $actor): void
    {
        if ($owner !== $actor->value) {
            throw new AccessDeniedException('Access denied.');
        }
    }

    /** @return array{people: list<EntityId>, course: EntityId} */
    private function routing(RegistrationBatchSelection $selection): array
    {
        $people = [];
        foreach ($selection->students as $id) {
            $person = $this->admissions->student($id)->personId;
            $people[$person->value] = $person;
        }
        ksort($people, SORT_STRING);
        return ['people' => array_values($people),
            'course' => $this->repository->section($selection->section)->courseId];
    }

    /** @param array{people: list<EntityId>, course: EntityId} $routing */
    private function lock(RegistrationBatchSelection $selection, array $routing): void
    {
        // Sorted students, sorted people, section, course: acquire every eligibility lock before any consistent read.
        foreach ($selection->students as $id) {
            $this->repository->lockStudent($id);
        }
        foreach ($routing['people'] as $id) {
            $this->repository->lockPerson($id);
        }
        $this->repository->lockSection($selection->section);
        $this->repository->lockCourse($routing['course']);
    }

    /** @return array<string, mixed> */
    private function context(RegistrationBatchSelection $selection): array
    {
        $section = $this->repository->section($selection->section);
        $course = $this->repository->courseForSection($section);
        $students = [];
        foreach ($selection->students as $id) {
            $student = $this->admissions->student($id);
            $person = $this->admissions->person($student->personId);
            $enrollment = $this->repository->enrollment($id, $section->id);
            $students[] = ['id' => $id->value, 'given_name' => $person->givenName, 'family_name' => $person->familyName,
                'institutional_id' => $person->institutionalId->value, 'active' => $student->active,
                'enrollment_id' => $enrollment?->id->value, 'enrollment_status' => $enrollment?->status->value];
        }
        return ['section' => ['id' => $section->id->value, 'code' => $section->code,
            'term_id' => $section->termId->value,
            'section_version_id' => $section->publicationVersionId?->value,
            'selection_position' => $this->versions->selectionPosition($section->id),
            'course_version_id' => $course->publicationVersionId?->value,
            'course_code' => $course->code, 'course_title' => $course->title, 'credits_milli' => $course->creditsMilli,
            'active' => $section->active && $course->active, 'fee_minor' => $section->fee->minor,
            'currency' => $section->fee->currency, 'capacity' => $section->capacity,
            'seats_remaining' => max(0, $section->capacity - $this->repository->sectionCount($section->id))],
            'students' => $students];
    }

    /** @param array<string, mixed> $context */
    private function revision(array $context): string
    {
        return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
    }
}
