<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Access\Repository\AccessRepository;
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

/** Staff application workflow only: no person, student, program, credential or account closure. */
final readonly class AdmissionReviewStateService
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
        private AccessRepository $permissions,
        private \Domain\Admissions\Repository\AdmissionWithdrawalRepository $requests,
    ) {
    }

    /** @return array<string,mixed> */
    public function review(EntityId $application, bool $withdraw, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->actor();
        if (
            !mb_check_encoding($reason, 'UTF-8') || trim($reason) === '' || mb_strlen($reason) > 10000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                ['field' => 'reason', 'message' => 'Enter a reason up to 10,000 characters.'],
            ]);
        }
        $route = $this->admissions->application($application);
        return $this->transactions->run(function () use ($route, $actor, $withdraw, $reason): array {
            $a = $this->lock($route, $actor);
            $this->noPendingRequest($a, $withdraw);
            $after = $a->changeReviewState($withdraw);
            $source = $this->context->source($a);
            $person = $this->admissions->person($a->personId);
            $token = $this->cipher->seal(json_encode([
                'purpose' => 'admission-review-state', 'application' => $a->id->value, 'actor' => $actor->value,
                'operation' => EntityId::generate()->value, 'withdraw' => $withdraw, 'reason' => trim($reason),
                'context' => $this->fingerprint($a, $source), 'expires' => $this->clock->now()->getTimestamp() + 1200,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $this->authorize($actor);
            return ['previous_status' => $a->status->value, 'status' => $after->status->value,
                'source' => AdmissionDecisionSchema::displaySource($source),
                'person' => ['given_name' => $person->givenName, 'family_name' => $person->familyName,
                    'institutional_id' => $person->institutionalId->value],
                'effective_on' => $this->clock->now()->format('Y-m-d'), 'review_token' => $token];
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
                !is_array($p) || count($p) !== 8 || ($p['purpose'] ?? null) !== 'admission-review-state'
                || ($p['application'] ?? null) !== $application->value || ($p['actor'] ?? null) !== $actor->value
                || !is_string($p['operation'] ?? null) || !is_bool($p['withdraw'] ?? null)
                || !is_string($p['reason'] ?? null) || !is_string($p['context'] ?? null)
                || !is_int($p['expires'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $operation = new EntityId($p['operation']);
        } catch (\Throwable) {
            throw new ValidationException([
                ['field' => 'review_token', 'message' => 'Review this application change again.'],
            ]);
        }
        $route = $this->admissions->application($application);
        return $this->transactions->run(function () use ($route, $actor, $p, $operation): array {
            $a = $this->lock($route, $actor);
            $receipt = $this->ledger->receipt($a, $operation, $actor);
            if ($receipt !== null) {
                $this->authorize($actor);
                return $receipt;
            }
            $this->noPendingRequest($a, $p['withdraw']);
            $after = $a->changeReviewState($p['withdraw']);
            $source = $this->context->source($a);
            if (
                $p['expires'] <= $this->clock->now()->getTimestamp()
                || !hash_equals($p['context'], $this->fingerprint($a, $source))
            ) {
                throw new ConcurrentModificationException(
                    'This review changed or expired. Review the application again.'
                );
            }
            $this->admissions->saveReviewState($a, $after);
            $result = $this->ledger->changeReviewState($a, $after, $source, $operation, $actor, $p['reason']);
            $this->audit->record($actor, $p['withdraw'] ? 'admissions.withdrawn' : 'admissions.review_resumed', $a->id);
            $this->authorize($actor);
            return $result;
        });
    }

    private function noPendingRequest(AdmissionApplication $a, bool $withdraw): void
    {
        if ($withdraw && $this->requests->pending($a->id) !== null) {
            throw new ValidationException([['field' => 'request',
                'message' => 'Open Withdrawal to approve or close the applicant’s pending request first.']]);
        }
    }
    private function actor(): EntityId
    {
        $actor = $this->access->requirePermission('sis:admissions:manage');
        if ($this->access->requirePermission('sis:admissions:withdraw')->value !== $actor->value) {
            throw new AccessDeniedException('The admissions actor changed.');
        }
        return $actor;
    }
    private function authorize(EntityId $actor): void
    {
        if ($this->actor()->value !== $actor->value) {
            throw new AccessDeniedException('The admissions actor changed.');
        }
    }
    private function lock(AdmissionApplication $route, EntityId $actor): AdmissionApplication
    {
        $this->permissions->lock();
        $this->admissions->lockPerson($route->personId);
        $this->admissions->lockApplication($route->id);
        $this->authorize($actor);
        $a = $this->admissions->application($route->id);
        if (
            $a->personId->value !== $route->personId->value || $a->programId->value !== $route->programId->value
            || $a->entryTermId->value !== $route->entryTermId->value
        ) {
            throw new ConcurrentModificationException('The application changed. Reload it.');
        }
        if ($a->status !== ApplicationStatus::Matriculated) {
            $this->catalogs->lockTerm($a->entryTermId);
            $this->catalogs->lockProgram($a->programId);
        }
        $this->ledger->verify($a);
        $this->authorize($actor);
        return $a;
    }
    /** @param array<string,mixed> $source */
    private function fingerprint(AdmissionApplication $a, array $source): string
    {
        $person = $this->admissions->person($a->personId);
        $submission = $this->context->submission($a->id);
        return hash('sha256', json_encode([AdmissionDecisionSchema::facts($a), $this->ledger->verify($a),
            $person->identityVersion, $person->givenName, $person->familyName, $person->institutionalId->value,
            $source, $this->requests->revision($a->id),
            $submission === null ? null : hash('sha256', $submission['encrypted_answers']),
            $this->clock->now()->format('Y-m-d')], JSON_THROW_ON_ERROR));
    }
}
