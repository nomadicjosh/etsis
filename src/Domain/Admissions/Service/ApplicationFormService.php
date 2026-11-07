<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Admissions\ApplicationForm;
use Domain\Admissions\Repository\ApplicationFormRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
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
        private MessageCipher $cipher
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listing(): array
    {
        $this->access->requirePermission('sis:forms:manage');

        return $this->repository->listing();
    }

    public function get(EntityId $id): ApplicationForm
    {
        $this->access->requirePermission('sis:forms:manage');

        return $this->repository->get($id);
    }

    public function active(EntityId $program): ApplicationForm
    {
        $this->access->authenticatedUserId();

        return $this->repository->active($program);
    }

    /** @param list<array<string, mixed>> $fields */
    public function save(?EntityId $id, EntityId $program, string $name, array $fields, ?int $version): ApplicationForm
    {
        return $this->transactions->run(function () use ($id, $program, $name, $fields, $version): ApplicationForm {
            $actor = $this->access->requirePermission('sis:forms:manage');
            $this->repository->lockProgram($program);
            if ($id !== null) {
                $existing = $this->repository->get($id);
                if (
                    $existing->status !== 'draft' || $existing->programId->value !== $program->value
                    || $version !== $existing->version
                ) {
                    throw new ConcurrentModificationException(
                        'Create a new revision for a published form, or reload the draft.'
                    );
                }
            }
            $form = new ApplicationForm(
                $id ?? EntityId::generate(),
                $program,
                $name,
                $fields,
                'draft',
                $id === null ? 0 : $version + 1
            );
            $this->repository->save($form, $id === null ? null : $version);
            $this->audit->record($actor, 'admissions.form_saved', $form->id);

            return $form;
        });
    }

    public function publish(EntityId $id, int $version): void
    {
        $this->transactions->run(function () use ($id, $version): void {
            $actor = $this->access->requirePermission('sis:forms:publish');
            $form = $this->repository->get($id);
            $this->repository->lockProgram($form->programId);
            $form = $this->repository->get($id);
            if ($form->version !== $version || $form->status !== 'draft') {
                throw new ConcurrentModificationException('Reload the current draft before publishing.');
            }
            $this->repository->publish($form);
            $this->audit->record($actor, 'admissions.form_published', $id);
        });
    }

    public function disable(EntityId $program): void
    {
        $this->transactions->run(function () use ($program): void {
            $actor = $this->access->requirePermission('sis:forms:publish');
            $this->repository->lockProgram($program);
            $this->repository->disable($program);
            $this->audit->record($actor, 'admissions.form_disabled', $program);
        });
    }

    /** @param array<string, mixed> $answers */
    public function submit(
        EntityId $submission,
        EntityId $formId,
        EntityId $person,
        EntityId $term,
        array $answers
    ): EntityId {
        return $this->transactions->run(function () use ($submission, $formId, $person, $term, $answers): EntityId {
            $actor = $this->access->authenticatedUserId();
            $this->admissions->person($person); // Owner or explicit staff authorization.
            $form = $this->repository->get($formId);
            $this->repository->lockProgram($form->programId);
            $canonical = $form->validate($answers);
            $json = json_encode($canonical, JSON_THROW_ON_ERROR);
            $fingerprint = hash('sha256', json_encode(
                [$formId->value, $person->value, $term->value, $canonical],
                JSON_THROW_ON_ERROR
            ));
            $existing = $this->repository->submission($submission);
            if ($existing !== null) {
                if ($existing['owner_user_id'] !== $actor->value || $existing['fingerprint'] !== $fingerprint) {
                    throw new DomainException('This submission identifier has already been used.');
                }

                return new EntityId($existing['application_id']);
            }
            if ($this->repository->active($form->programId)->id->value !== $formId->value) {
                throw new DomainException('This application form has been replaced. Reload the current form.');
            }
            $application = $this->admissions->apply($person, $form->programId, $term);
            $this->repository->storeSubmission(
                $submission,
                $formId,
                $application->id,
                $actor,
                $this->cipher->seal($json),
                $fingerprint
            );
            $this->audit->record($actor, 'admissions.form_submitted', $application->id);

            return $application->id;
        });
    }

    /** @return array<string, mixed> */
    public function reviewApplication(EntityId $application): array
    {
        $actor = $this->access->requirePermission('sis:admissions:manage');
        $review = $this->admissions->review($application);
        $submission = $this->repository->submissionForApplication($application);
        $review['labels'] = $this->repository->applicationLabels($application);
        $review['submission'] = $submission === null ? null : $this->submission(new EntityId($submission['id']));
        $this->audit->record($actor, 'admissions.answers_reviewed', $application);

        return $review;
    }

    /** @return array<string, mixed> */
    public function submission(EntityId $id): array
    {
        $this->access->authenticatedUserId();
        $record = $this->repository->submission($id)
        ?? throw new \Domain\Shared\Exception\RecordNotFoundException('Submission not found.');
        $this->access->requireOwnerOrPermission(new EntityId($record['owner_user_id']), 'sis:admissions:manage');

        return ['form' => $this->repository->get(new EntityId($record['form_id'])),
            'answers' => json_decode(
                $this->cipher->open($record['encrypted_answers']),
                true,
                flags: JSON_THROW_ON_ERROR
            ),
            'application_id' => $record['application_id']];
    }
}
