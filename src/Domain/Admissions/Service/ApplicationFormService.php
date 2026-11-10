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

    public function active(EntityId $program): ApplicationForm
    {
        $this->access->authenticatedUserId();

        return $this->repository->active($program);
    }

    /** @param list<array<string, mixed>> $fields */
    public function save(
        ?EntityId $id,
        EntityId $program,
        string $name,
        array $fields,
        ?int $version,
    ): ApplicationForm {
        return $this->transactions->run(function () use (
            $id,
            $program,
            $name,
            $fields,
            $version,
        ): ApplicationForm {
            $actor = $this->access->requirePermission("sis:forms:manage");
            $this->repository->lockProgram($program);
            if ($id !== null) {
                $existing = $this->repository->get($id);
                if (
                    $existing->status !== "draft" ||
                    $existing->programId->value !== $program->value ||
                    $version !== $existing->version
                ) {
                    throw new ConcurrentModificationException(
                        "Create a new revision for a published form, or reload the draft.",
                    );
                }
            }
            $form = new ApplicationForm(
                $id ?? EntityId::generate(),
                $program,
                $name,
                $fields,
                "draft",
                $id === null ? 0 : $version + 1,
            );
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
            $this->repository->lockProgram($form->programId);
            $form = $this->repository->get($id);
            if ($form->version !== $version || $form->status !== "draft") {
                throw new ConcurrentModificationException("Reload the current draft before publishing.");
            }
            $this->repository->publish($form);
            $this->audit->record($actor, "admissions.form_published", $id);
        });
    }

    public function disable(EntityId $program): void
    {
        $this->transactions->run(function () use ($program): void {
            $actor = $this->access->requirePermission("sis:forms:publish");
            $this->repository->lockProgram($program);
            $this->repository->disable($program);
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
    ): EntityId {
        return $this->submissions->submit($submission, $formId, $person, $term, $answers);
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
