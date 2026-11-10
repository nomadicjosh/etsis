<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\Repository\ApplicationFormRepository;
use Domain\Admissions\ValueObject\AdmissionDecisionSchema;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;

final readonly class AdmissionDecisionService
{
    public function __construct(
        private AdmissionsRepository $admissions,
        private ApplicationFormRepository $forms,
        private StudentRecordRepository $records,
        private CatalogYearRepository $catalogs,
        private AdmissionDecisionLedger $ledger,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function review(EntityId $application, bool $accept, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->access->requirePermission("sis:admissions:manage");
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 10000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                ["field" => "reason", "message" => "Enter a reason of up to 10,000 characters."],
            ]);
        }
        $route = $this->admissions->application($application);
        return $this->transactions->run(function () use ($route, $actor, $accept, $reason): array {
            $a = $this->lock($route, $actor);
            $this->eligible($a);
            $source = $this->source($a);
            $person = $this->admissions->person($a->personId);
            $token = $this->cipher->seal(
                json_encode(
                    [
                        "purpose" => "admission-decision.v1",
                        "application" => $a->id->value,
                        "actor" => $actor->value,
                        "operation" => EntityId::generate()->value,
                        "accept" => $accept,
                        "reason" => trim($reason),
                        "context" => $this->context($a, $source),
                        "expires" => $this->clock->now()->getTimestamp() + 1200,
                    ],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            );
            return [
                "status" => $accept ? "accepted" : "rejected",
                "source" => AdmissionDecisionSchema::displaySource($source),
                "person" => [
                    "given_name" => $person->givenName,
                    "family_name" => $person->familyName,
                    "institutional_id" => $person->institutionalId->value,
                ],
                "review_token" => $token,
                "effective_on" => $this->clock->now()->format("Y-m-d"),
            ];
        });
    }
    /** @return array<string,mixed> */
    public function commit(EntityId $application, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission("sis:admissions:manage");
        try {
            if (strlen($token) > 100000) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) ||
                count($p) !== 8 ||
                ($p["purpose"] ?? null) !== "admission-decision.v1" ||
                ($p["application"] ?? null) !== $application->value ||
                ($p["actor"] ?? null) !== $actor->value ||
                !is_string($p["operation"] ?? null) ||
                !is_bool($p["accept"] ?? null) ||
                !is_string($p["reason"] ?? null) ||
                !is_string($p["context"] ?? null) ||
                !is_int($p["expires"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $operation = new EntityId($p["operation"]);
        } catch (\Throwable) {
            throw new ValidationException([
                ["field" => "review_token", "message" => "Review this admissions decision again."],
            ]);
        }
        $route = $this->admissions->application($application);
        return $this->transactions->run(function () use ($route, $actor, $p, $operation): array {
            $a = $this->lock($route, $actor);
            $receipt = $this->ledger->receipt($a, $operation, $actor);
            if ($receipt !== null) {
                return $receipt;
            }
            $this->eligible($a);
            $source = $this->source($a);
            if (
                $p["expires"] < $this->clock->now()->getTimestamp() ||
                !hash_equals($p["context"], $this->context($a, $source))
            ) {
                throw new ConcurrentModificationException(
                    "This decision review expired or its context changed. Review it again.",
                );
            }
            $after = $a->decide($p["accept"]);
            $this->admissions->saveDecision($after, $a->version);
            $result = $this->ledger->record($a, $after, $source, $operation, $actor, $p["reason"]);
            $this->audit->record(
                $actor,
                $p["accept"] ? "admissions.accepted" : "admissions.rejected",
                $a->id,
            );
            return $result;
        });
    }
    /** @return array<string,mixed> */
    public function history(EntityId $application): array
    {
        $actor = $this->access->requirePermission("sis:admissions:manage");
        $route = $this->admissions->application($application);
        return $this->transactions->run(fn(): array => $this->ledger->timeline($this->lock($route, $actor)));
    }
    private function lock(AdmissionApplication $route, EntityId $actor): AdmissionApplication
    {
        $this->admissions->lockPerson($route->personId);
        $this->admissions->lockApplication($route->id);
        $this->authorize($actor);
        $a = $this->admissions->application($route->id);
        if (
            $a->personId->value !== $route->personId->value ||
            $a->programId->value !== $route->programId->value ||
            $a->entryTermId->value !== $route->entryTermId->value
        ) {
            throw new ConcurrentModificationException("The application changed. Review it again.");
        }
        if ($a->status === ApplicationStatus::Submitted) {
            $this->catalogs->lockTerm($a->entryTermId);
            $this->catalogs->lockProgram($a->programId);
            $this->authorize($actor);
        }
        $this->ledger->verify($a);
        return $a;
    }
    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission("sis:admissions:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The admissions actor changed.");
        }
    }
    private function eligible(AdmissionApplication $a): void
    {
        if ($a->status !== ApplicationStatus::Submitted) {
            throw new ValidationException([
                [
                    "field" => "application",
                    "message" => "This application already has a decision. Reload its review and history.",
                ],
            ]);
        }
    }
    /** @return array<string,mixed> */
    private function source(AdmissionApplication $a): array
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
    /** @param array<string,mixed> $source */
    private function context(AdmissionApplication $a, array $source): string
    {
        $p = $this->admissions->person($a->personId);
        $r = $this->forms->submissionForApplication($a->id);
        return hash(
            "sha256",
            json_encode(
                [
                    AdmissionDecisionSchema::facts($a),
                    $p->identityVersion,
                    $p->givenName,
                    $p->familyName,
                    $p->institutionalId->value,
                    $source,
                    $r === null ? null : hash("sha256", $r["encrypted_answers"]),
                    $this->clock->now()->format("Y-m-d"),
                    $this->ledger->verify($a),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
