<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Admissions\ApplicationForm;
use Domain\Admissions\Repository\ApplicationFormRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use DomainException;

final readonly class ApplicationFormService
{
    public function __construct(
        private ApplicationFormRepository $repository,
        private AdmissionsService $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private ApplicationSubmissionService $submissions,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listing(): array
    {
        $this->access->requirePermission("sis:forms:manage");

        return $this->repository->listing();
    }

    public function get(EntityId $id): ApplicationForm
    {
        $this->access->requirePermission("sis:forms:manage");

        return $this->repository->get($id);
    }

    /** @return list<array<string,mixed>> */
    public function levels(): array
    {
        $this->access->requirePermission('sis:forms:manage');
        return $this->repository->levels();
    }

    public function active(EntityId $program): ApplicationForm
    {
        $this->access->authenticatedUserId();

        return $this->repository->active($program);
    }

    /** @param list<array<string, mixed>> $fields */
    public function save(
        ?EntityId $id,
        ?EntityId $program,
        string $name,
        array $fields,
        ?int $version,
        ?EntityId $academicLevel = null,
    ): ApplicationForm {
        return $this->transactions->run(function () use (
            $id,
            $program,
            $name,
            $fields,
            $version,
            $academicLevel,
        ): ApplicationForm {
            $actor = $this->access->requirePermission("sis:forms:manage");
            $form = new ApplicationForm(
                $id ?? EntityId::generate(),
                $program,
                $name,
                $fields,
                'draft',
                $id === null ? 0 : $version + 1,
                $academicLevel
            );
            if ($program !== null) {
                $this->repository->lockProgram($program);
            } else {
                $this->repository->lockLevel($form->targetId());
            }
            if ($this->access->requirePermission('sis:forms:manage')->value !== $actor->value) {
                throw new \Domain\Shared\Exception\AccessDeniedException('Access denied.');
            }
            if ($id !== null) {
                $existing = $this->repository->get($id);
                if (
                    $existing->status !== "draft" ||
                    $existing->targetKind() !== $form->targetKind() ||
                    $existing->targetId()->value !== $form->targetId()->value ||
                    $version !== $existing->version
                ) {
                    throw new ConcurrentModificationException(
                        "Create a new revision for a published form, or reload the draft.",
                    );
                }
            }
            $this->repository->save($form, $id === null ? null : $version);
            $this->audit->record($actor, "admissions.form_saved", $form->id);

            return $form;
        });
    }

    public function publish(EntityId $id, int $version): void
    {
        $this->transactions->run(function () use ($id, $version): void {
            $actor = $this->access->requirePermission("sis:forms:publish");
            $form = $this->repository->get($id);
            if ($form->programId !== null) {
                $this->repository->lockProgram($form->programId);
            } else {
                $this->repository->lockLevel($form->targetId());
            }
            if ($this->access->requirePermission('sis:forms:publish')->value !== $actor->value) {
                throw new \Domain\Shared\Exception\AccessDeniedException('Access denied.');
            }
            $form = $this->repository->get($id);
            if ($form->version !== $version || $form->status !== "draft") {
                throw new ConcurrentModificationException("Reload the current draft before publishing.");
            }
            $this->repository->publish($form);
            $this->audit->record($actor, "admissions.form_published", $id);
        });
    }

    public function disable(EntityId $program, bool $academicLevel = false): void
    {
        $this->transactions->run(function () use ($program, $academicLevel): void {
            $actor = $this->access->requirePermission("sis:forms:publish");
            if ($academicLevel) {
                $this->repository->lockLevel($program);
            } else {
                $this->repository->lockProgram($program);
            }
            if ($this->access->requirePermission('sis:forms:publish')->value !== $actor->value) {
                throw new \Domain\Shared\Exception\AccessDeniedException('Access denied.');
            }
            if ($academicLevel) {
                $this->repository->disableLevel($program);
            } else {
                $this->repository->disable($program);
            }
            $this->audit->record($actor, "admissions.form_disabled", $program);
        });
    }

    /** @param array<string, mixed> $answers */
    public function submit(
        EntityId $submission,
        EntityId $formId,
        EntityId $person,
        EntityId $term,
        #[\SensitiveParameter] array $answers,
        ?EntityId $program = null,
    ): EntityId {
        return $this->submissions->submit($submission, $formId, $person, $term, $answers, $program);
    }

    /** @return array<string, mixed> */
    public function reviewApplication(EntityId $application): array
    {
        $actor = $this->access->requirePermission("sis:admissions:manage");
        $review = $this->admissions->review($application);
        $submission = $this->repository->submissionForApplication($application);
        $review["labels"] = $this->repository->applicationLabels($application);
        $review["submission"] =
        $submission === null ? null : $this->submission(new EntityId($submission["id"]));
        $this->audit->record($actor, "admissions.answers_reviewed", $application);

        return $review;
    }

    /** @return array<string, mixed> */
    public function submission(EntityId $id): array
    {
        return $this->submissions->submission($id);
    }
}
