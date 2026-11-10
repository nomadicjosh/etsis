<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Repository\ApplicationFormRepository;
use Domain\Admissions\ValueObject\AdmissionDecisionSchema;
use Domain\Admissions\ValueObject\SubmissionIntegrity;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;

final readonly class ApplicationContext
{
    public function __construct(
        private ApplicationFormRepository $forms,
        private StudentRecordRepository $records,
        private MessageCipher $cipher,
    ) {
    }
    /** @return array<string,mixed>|null */
    public function submission(EntityId $application): ?array
    {
        return $this->forms->submissionForApplication($application);
    }
    /** @return array<string,mixed> */
    public function source(AdmissionApplication $a): array
    {
        $program = $this->records->program($a->programId);
        $term = $this->records->term($a->entryTermId);
        $r = $this->forms->submissionForApplication($a->id);
        $submission = null;
        if ($r !== null) {
            $form = $this->forms->get(new EntityId($r["form_id"]));
            if ($r["application_id"] !== $a->id->value || $form->programId->value !== $a->programId->value) {
                throw new CorruptHistoryException();
            }
            $submission = [
                "id" => $r["id"],
                "form_id" => $r["form_id"],
                "form_version" => $form->version,
                "form_name" => $form->name,
                "fingerprint" => $r["fingerprint"],
            ];
        }
        $source = [
            "program_code" => $program["code"],
            "program_title" => $program["title"],
            "entry_term_code" => $term["code"],
            "submission" => $submission,
        ];
        AdmissionDecisionSchema::source($source);
        return $source;
    }

    /** @param array<string,mixed> $birth */
    public function verify(
        AdmissionApplication $a,
        #[\SensitiveParameter] array $birth,
        EntityId $actor,
    ): void {
        $ref = $birth["source"]["submission"];
        $r = $this->submission($a->id);
        if ($ref === null) {
            if ($r !== null) {
                throw new CorruptHistoryException();
            }
            return;
        }
        if (
            $r === null ||
            $r["id"] !== $ref["id"] ||
            $r["form_id"] !== $ref["form_id"] ||
            $r["application_id"] !== $a->id->value ||
            $r["owner_user_id"] !== $actor->value ||
            $r["fingerprint"] !== $ref["fingerprint"]
        ) {
            throw new CorruptHistoryException();
        }
        try {
            $form = $this->forms->get(new EntityId($ref["form_id"]));
            if (
                $form->programId->value !== $a->programId->value ||
                $form->version !== $ref["form_version"] ||
                $form->name !== $ref["form_name"] ||
                !hash_equals($birth["form_definition_hash"], SubmissionIntegrity::formHash($form))
            ) {
                throw new CorruptHistoryException();
            }
            $answers = json_decode(
                $this->cipher->open($r["encrypted_answers"]),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($answers) ||
                !hash_equals(
                    $ref["fingerprint"],
                    SubmissionIntegrity::fingerprint($form->id, $a->personId, $a->entryTermId, $answers),
                )
            ) {
                throw new CorruptHistoryException();
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }
}
