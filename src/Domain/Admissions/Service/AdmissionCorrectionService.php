<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\ValueObject\AdmissionDecisionSchema;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class AdmissionCorrectionService
{
    public function __construct(
        private AdmissionsRepository $admissions,
        private ApplicationContext $context,
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
        $actor = $this->actor();
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 10000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                ["field" => "reason", "message" => "Enter a correction reason of up to 10,000 characters."],
            ]);
        }
        $route = $this->admissions->application($application);
        return $this->transactions->run(function () use ($route, $actor, $accept, $reason): array {
            $a = $this->lock($route, $actor);
            $this->eligible($a, $accept);
            $source = $this->context->source($a);
            $person = $this->admissions->person($a->personId);
            $token = $this->cipher->seal(
                json_encode(
                    [
                        "purpose" => "admission-correction.v1",
                        "application" => $a->id->value,
                        "actor" => $actor->value,
                        "operation" => EntityId::generate()->value,
                        "accept" => $accept,
                        "reason" => trim($reason),
                        "context" => $this->fingerprint($a, $source),
                        "expires" => $this->clock->now()->getTimestamp() + 1200,
                    ],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            );
            return [
                "before" => $this->ledger->current($a),
                "status" => $accept ? "accepted" : "rejected",
                "source" => AdmissionDecisionSchema::displaySource($source),
                "effective_on" => $this->clock->now()->format("Y-m-d"),
                "person" => [
                    "given_name" => $person->givenName,
                    "family_name" => $person->familyName,
                    "institutional_id" => $person->institutionalId->value,
                ],
                "review_token" => $token,
            ];
        });
    }
    /** @return array<string,mixed> */
    public function commit(EntityId $application, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->actor();
        try {
            if (strlen($token) > 100000) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) ||
                count($p) !== 8 ||
                ($p["purpose"] ?? null) !== "admission-correction.v1" ||
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
                ["field" => "review_token", "message" => "Review this admissions correction again."],
            ]);
        }
        $route = $this->admissions->application($application);
        return $this->transactions->run(function () use ($route, $actor, $p, $operation): array {
            $a = $this->lock($route, $actor);
            $receipt = $this->ledger->receipt($a, $operation, $actor);
            if ($receipt !== null) {
                return $receipt;
            }
            $this->eligible($a, $p["accept"]);
            $source = $this->context->source($a);
            if (
                $p["expires"] < $this->clock->now()->getTimestamp() ||
                !hash_equals($p["context"], $this->fingerprint($a, $source))
            ) {
                throw new ConcurrentModificationException(
                    "This correction review expired or its context changed. Review it again.",
                );
            }
            $after = $a->correctDecision($p["accept"]);
            $this->admissions->saveCorrection($a, $after);
            $result = $this->ledger->correct($a, $after, $source, $operation, $actor, $p["reason"]);
            $this->audit->record($actor, "admissions.decision_corrected", $a->id);
            return $result;
        });
    }
    private function actor(): EntityId
    {
        $actor = $this->access->requirePermission("sis:admissions:manage");
        if ($this->access->requirePermission("sis:admissions:correct")->value !== $actor->value) {
            throw new AccessDeniedException("The admissions correction actor changed.");
        }
        return $actor;
    }
    private function lock(AdmissionApplication $route, EntityId $actor): AdmissionApplication
    {
        $this->admissions->lockPerson($route->personId);
        $this->admissions->lockApplication($route->id);
        if ($this->actor()->value !== $actor->value) {
            throw new AccessDeniedException("The correction actor changed.");
        }
        $a = $this->admissions->application($route->id);
        if (
            $a->personId->value !== $route->personId->value ||
            $a->programId->value !== $route->programId->value ||
            $a->entryTermId->value !== $route->entryTermId->value
        ) {
            throw new ConcurrentModificationException("The application changed. Review it again.");
        }
        if (in_array($a->status, [ApplicationStatus::Accepted, ApplicationStatus::Rejected], true)) {
            $this->catalogs->lockTerm($a->entryTermId);
            $this->catalogs->lockProgram($a->programId);
            if ($this->actor()->value !== $actor->value) {
                throw new AccessDeniedException("The correction actor changed.");
            }
        }
        $this->ledger->verify($a);
        return $a;
    }
    private function eligible(AdmissionApplication $a, bool $accept): void
    {
        if (!in_array($a->status, [ApplicationStatus::Accepted, ApplicationStatus::Rejected], true)) {
            throw new ValidationException([
                [
                    "field" => "application",
                    "message" =>
                        "Only accepted or rejected applications can be corrected here. " .
                        "Matriculated records require the student-record workflow.",
                ],
            ]);
        }
        if (($accept ? ApplicationStatus::Accepted : ApplicationStatus::Rejected) === $a->status) {
            throw new ValidationException([
                [
                    "field" => "decision",
                    "message" => "This is already the current outcome. Reload its history.",
                ],
            ]);
        }
    }
    /** @param array<string,mixed> $source */
    private function fingerprint(AdmissionApplication $a, array $source): string
    {
        $p = $this->admissions->person($a->personId);
        $r = $this->context->submission($a->id);
        return hash(
            "sha256",
            json_encode(
                [
                    AdmissionDecisionSchema::facts($a),
                    $this->ledger->verify($a),
                    $p->identityVersion,
                    $p->givenName,
                    $p->familyName,
                    $p->institutionalId->value,
                    $source,
                    $r === null ? null : hash("sha256", $r["encrypted_answers"]),
                    $this->clock->now()->format("Y-m-d"),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
