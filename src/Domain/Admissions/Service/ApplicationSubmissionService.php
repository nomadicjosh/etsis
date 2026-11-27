<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\Repository\ApplicationFormRepository;
use Domain\Admissions\ValueObject\SubmissionIntegrity;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class ApplicationSubmissionService
{
    public function __construct(
        private AdmissionsRepository $admissions,
        private ApplicationFormRepository $forms,
        private CatalogYearRepository $catalogs,
        private ApplicationContext $context,
        private AdmissionDecisionLedger $ledger,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private \Domain\User\Service\OwnerEmailVerification $verification,
    ) {
    }

    public function apply(
        EntityId $id,
        EntityId $person,
        EntityId $program,
        EntityId $term,
    ): AdmissionApplication {
        $actor = $this->access->requirePermission("sis:admissions:manage");
        return $this->transactions->run(function () use (
            $id,
            $person,
            $program,
            $term,
            $actor,
        ): AdmissionApplication {
            $this->admissions->lockPerson($person);
            $this->recheckDirect($actor);
            $existing = $this->existing($id);
            if ($existing !== null) {
                $this->admissions->lockApplication($id);
                $this->recheckDirect($actor);
                $existing = $this->admissions->application($id);
                if (
                    $existing->personId->value !== $person->value
                ) {
                    throw new ConcurrentModificationException(
                        "This application identifier has already been used.",
                    );
                }
                $receipt = $this->ledger->submissionReceipt($existing, $id, $actor);
                if (
                    $receipt === null || $receipt["channel"] !== "direct"
                    || $receipt["application"]["program_id"] !== $program->value
                    || $receipt["application"]["entry_term_id"] !== $term->value
                ) {
                    throw new ConcurrentModificationException(
                        "This application identifier has already been used.",
                    );
                }
                return new AdmissionApplication($id, $person, $program, $term);
            }
            $this->catalogs->lockTerm($term);
            $this->catalogs->lockProgram($program);
            $this->recheckDirect($actor);
            $a = new AdmissionApplication($id, $person, $program, $term);
            $this->admissions->addApplication($a);
            $this->ledger->submitted(
                $a,
                $this->context->source($a),
                "direct",
                "admissions_staff",
                null,
                $id,
                $actor,
            );
            $this->audit->record($actor, "admissions.applied", $a->id);
            return $a;
        });
    }
    /** @param array<string,mixed> $answers */
    public function submit(
        EntityId $submission,
        EntityId $formId,
        EntityId $person,
        EntityId $term,
        #[\SensitiveParameter] array $answers,
        ?EntityId $program = null,
    ): EntityId {
        $actor = $this->authorize($person);
        return $this->transactions->run(function () use (
            $submission,
            $formId,
            $person,
            $term,
            $answers,
            $actor,
            $program,
        ): EntityId {
            $this->admissions->lockPerson($person);
            $this->recheck($person, $actor);
            $form = $this->forms->get($formId);
            $program ??= $form->programId;
            if ($program === null || ($form->programId !== null && $form->programId->value !== $program->value)) {
                throw new ValidationException([
                    ['field' => 'program_id', 'message' => 'Choose the program for this application.'],
                ]);
            }
            $canonical = $form->validate($answers);
            $fingerprint = SubmissionIntegrity::fingerprint(
                $formId,
                $person,
                $term,
                $canonical,
                $form->programId === null ? $program : null
            );
            $r = $this->forms->submission($submission);
            if ($r !== null) {
                if ($r["owner_user_id"] !== $actor->value || !hash_equals($r["fingerprint"], $fingerprint)) {
                    throw new ValidationException([
                        [
                            "field" => "submission_id",
                            "message" => "This submission identifier has already been used.",
                        ],
                    ]);
                }
                $id = new EntityId($r["application_id"]);
                $this->admissions->lockApplication($id);
                $a = $this->admissions->application($id);
                $this->recheck($person, $actor);
                if (
                    $a->personId->value !== $person->value
                ) {
                    throw new CorruptHistoryException();
                }
                $origin = $this->ledger->verifySubmission($a, $submission);
                if (($origin['program_id'] ?? $a->programId->value) !== $program->value) {
                    throw new CorruptHistoryException();
                }
                // Earlier receipts remain usable without inventing their original submission events.
                $this->ledger->submissionReceipt($a, $submission, $actor);
                return $id;
            }
            $ownerSubmission = $this->authorization($person, $actor) === 'owner';
            if ($ownerSubmission) {
                $this->verification->requireForApplication($actor, $person);
            }
            $this->lockCatalog($person, $program, $term, $actor);
            if ($form->academicLevelId !== null) {
                $this->forms->lockLevel($form->academicLevelId);
                $this->recheck($person, $actor);
            }
            if ($this->forms->active($program)->id->value !== $formId->value) {
                throw new ValidationException([
                    [
                        "field" => "form_id",
                        "message" => "This application form has been replaced. Reload the current form.",
                    ],
                ]);
            }
            $form = $this->forms->get($formId);
            $canonical = $form->validate($answers);
            $fingerprint = SubmissionIntegrity::fingerprint(
                $formId,
                $person,
                $term,
                $canonical,
                $form->programId === null ? $program : null
            );
            $id = PublishedGradeId::forVersion($submission, "admissions.application");
            if ($this->existing($id) !== null) {
                throw new ConcurrentModificationException(
                    "This application identity already exists. Start a new submission.",
                );
            }
            $a = new AdmissionApplication($id, $person, $program, $term);
            $this->admissions->addApplication($a);
            $this->forms->storeSubmission(
                $submission,
                $formId,
                $id,
                $actor,
                $this->cipher->seal(json_encode($canonical, JSON_THROW_ON_ERROR)),
                $fingerprint,
            );
            $this->ledger->submitted(
                $a,
                $this->context->source($a),
                "form",
                $this->authorization($person, $actor),
                SubmissionIntegrity::formHash($form),
                $submission,
                $actor,
            );
            $this->audit->record($actor, "admissions.applied", $id);
            $this->audit->record($actor, "admissions.form_submitted", $id);
            $this->recheck($person, $actor);
            if ($ownerSubmission) {
                $this->verification->requireForApplication($actor, $person);
            }
            return $id;
        });
    }
    /** Owner/staff read; never return stored ciphertext, fingerprints or raw event payloads.
     * @return array<string,mixed> */
    public function submission(EntityId $id): array
    {
        $this->access->authenticatedUserId();
        $route = $this->forms->submission($id) ?? throw new RecordNotFoundException("Submission not found.");
        $a = $this->admissions->application(new EntityId($route["application_id"]));
        $actor = $this->authorize($a->personId);
        return $this->transactions->run(function () use ($id, $a, $actor): array {
            $this->admissions->lockPerson($a->personId);
            $this->admissions->lockApplication($a->id);
            $this->recheck($a->personId, $actor);
            $current = $this->admissions->application($a->id);
            if ($current->personId->value !== $a->personId->value) {
                throw new CorruptHistoryException();
            }
            $r = $this->forms->submission($id) ?? throw new CorruptHistoryException();
            if ($r["application_id"] !== $a->id->value) {
                throw new CorruptHistoryException();
            }
            $origin = $this->ledger->verifySubmission($current, $id);
            try {
                $answers = json_decode(
                    $this->cipher->open($r["encrypted_answers"]),
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                );
            } catch (\Throwable) {
                throw new CorruptHistoryException();
            }
            if (!is_array($answers)) {
                throw new CorruptHistoryException();
            }
            return [
                "form" => $this->forms->get(new EntityId($r["form_id"])),
                "answers" => $answers,
                "application_id" => $a->id->value,
                "submitted_term_code" => $origin["entry_term_code"] ?? null,
            ];
        });
    }
    private function recheckDirect(EntityId $actor): void
    {
        if ($this->access->requirePermission("sis:admissions:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The direct-entry actor changed.");
        }
    }
    private function authorize(EntityId $person): EntityId
    {
        $this->access->authenticatedUserId();
        $p = $this->admissions->person($person);
        return $this->access->requireOwnerOrPermission($p->ownerUserId, "sis:admissions:manage");
    }
    private function recheck(EntityId $person, EntityId $actor): void
    {
        if ($this->authorize($person)->value !== $actor->value) {
            throw new AccessDeniedException("The submission actor changed.");
        }
    }
    private function lockCatalog(EntityId $person, EntityId $program, EntityId $term, EntityId $actor): void
    {
        $this->catalogs->lockTerm($term);
        $this->catalogs->lockProgram($program);
        $this->recheck($person, $actor);
    }
    private function authorization(EntityId $person, EntityId $actor): string
    {
        return $this->admissions->person($person)->ownerUserId?->value === $actor->value
        ? "owner"
        : "admissions_staff";
    }
    private function existing(EntityId $id): ?AdmissionApplication
    {
        try {
            return $this->admissions->application($id);
        } catch (RecordNotFoundException) {
            return null;
        }
    }
}
