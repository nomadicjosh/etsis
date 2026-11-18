<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Repository\AdmissionPolicyRepository;
use Domain\Admissions\Repository\AdmissionWithdrawalRepository;
use Domain\Admissions\Repository\AdmissionUpdatesRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\ValueObject\AdmissionOfferSchema;
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

/** Applicant working requests and their independently authorized formal application outcome. */
final readonly class AdmissionWithdrawalService
{
    public const int MAX_REVIEW_BYTES = 100000;
    public function __construct(
        private AdmissionsRepository $admissions,
        private AdmissionWithdrawalRepository $requests,
        private AdmissionPolicyRepository $policy,
        private AdmissionDecisionLedger $ledger,
        private ApplicationContext $context,
        private CatalogYearRepository $catalogs,
        private StudentAccess $access,
        private AccessRepository $permissions,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private AdmissionUpdatesRepository $updates,
    ) {
    }
    public function current(EntityId $id, bool $mine, ?EntityId $after = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $route = $this->admissions->application($id);
        return $this->transactions->run(function () use ($route, $actor, $mine, $after): array {
            $a = $this->lock($route, $actor, $mine, false);
            $rows = $this->requests->page($a->id, $after);
            $more = count($rows) > 10;
            $rows = array_slice($rows, 0, 10);
            $next = $more ? $rows[array_key_last($rows)]['id'] : null;
            if ($mine) {
                $rows = array_values(array_filter(
                    $rows,
                    static fn(array $r): bool => $r['owner_user_id'] === $actor->value
                ));
            }
            $pending = $this->requests->pending($a->id);
            $result = ['items' => array_map($this->display(...), $rows), 'next' => $next,
                'policy' => $this->policy->withdrawals(), 'application_status' => $a->status->value,
                'has_pending' => $pending !== null, 'pending_id' => $pending !== null
                    && (!$mine || $pending['owner_user_id'] === $actor->value) ? $pending['id'] : null];
            $this->authorize($a, $actor, $mine, false);
            return $result;
        });
    }
    public function queue(?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission('sis:admissions:manage');
        $rows = $this->requests->queue($after);
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $this->authorize(null, $actor, false, false);
        return ['items' => $rows, 'next' => $more ? $rows[array_key_last($rows)]['id'] : null];
    }
    public function review(
        EntityId $id,
        string $action,
        ?EntityId $request,
        #[\SensitiveParameter] string $reason,
        bool $mine
    ): array {
        $actor = $this->access->authenticatedUserId();
        $this->action($action, $mine);
        if ($action === 'cancel') {
            $reason = 'The applicant cancelled their withdrawal request.';
        }
        if (!AdmissionOfferSchema::text($reason, 2000)) {
            throw new ValidationException([
                ['field' => 'reason',
                    'message' => 'Enter a reason or response up to 2,000 characters.'],
            ]);
        }
        $route = $this->admissions->application($id);
        return $this->transactions->run(function () use ($route, $actor, $mine, $action, $request, $reason): array {
            $a = $this->lock($route, $actor, $mine, true);
            $operation = EntityId::generate();
            $new = in_array($action, ['request', 'direct'], true);
            $requestId = $new ? $operation : ($request ?? throw new ValidationException([
                ['field' => 'request', 'message' => 'Choose the current request.'],
            ]));
            $r = $new ? null : $this->requests->find($requestId);
            $this->eligible($a, $r, $action, $actor);
            $token = $this->cipher->seal(json_encode([
                'purpose' => 'admission-withdrawal', 'application' => $a->id->value,
                'actor' => $actor->value, 'mine' => $mine, 'operation' => $operation->value, 'action' => $action,
                'request' => $requestId->value, 'reason' => trim($reason), 'context' => $this->fingerprint($a, $r),
                'expires' => $this->clock->now()->getTimestamp() + 1200,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $this->authorize($a, $actor, $mine, true);
            return ['action' => $action, 'reason' => trim($reason),
                'request' => $r === null ? null : $this->display($r),
                'application_status' => $a->status->value, 'review_token' => $token];
        });
    }
    public function commit(EntityId $id, #[\SensitiveParameter] string $token, bool $mine): array
    {
        $actor = $this->access->authenticatedUserId();
        try {
            if (strlen($token) > self::MAX_REVIEW_BYTES) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 10, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) || count($p) !== 10 || ($p['purpose'] ?? null) !== 'admission-withdrawal'
                || ($p['application'] ?? null) !== $id->value || ($p['actor'] ?? null) !== $actor->value
                || ($p['mine'] ?? null) !== $mine || !is_string($p['action'] ?? null)
                || !is_string($p['context'] ?? null) || !is_int($p['expires'] ?? null)
                || !AdmissionOfferSchema::text($p['reason'] ?? null, 2000)
            ) {
                throw new \InvalidArgumentException();
            }
            $operation = new EntityId($p['operation']);
            $request = new EntityId($p['request']);
            $this->action($p['action'], $mine);
        } catch (\Throwable) {
            throw new ValidationException([
                ['field' => 'review_token',
                    'message' => 'Review this application request again.'],
            ]);
        }
        $route = $this->admissions->application($id);
        return $this->transactions->run(function () use ($route, $actor, $mine, $p, $operation, $request): array {
            $a = $this->lock($route, $actor, $mine, true);
            $r = $this->requests->find($request);
            if ($r !== null && $r['application_id'] !== $a->id->value) {
                throw new AccessDeniedException('This request belongs to another application.');
            }
            $receipt = $r['receipts'][$operation->value] ?? null;
            if ($receipt !== null) {
                if ($receipt['actor'] !== $actor->value || $receipt['mine'] !== $mine) {
                    throw new AccessDeniedException('This request confirmation belongs to another actor.');
                }
                $this->authorize($a, $actor, $mine, true);
                return $receipt['result'];
            }
            if (
                $p['expires'] <= $this->clock->now()->getTimestamp()
                || !hash_equals($p['context'], $this->fingerprint($a, $r))
            ) {
                throw new ConcurrentModificationException('This application or request changed. Review it again.');
            }
            $action = $p['action'];
            $this->eligible($a, $r, $action, $actor);
            $new = $r === null;
            $now = $this->clock->now()->format(DATE_ATOM);
            $policy = $this->policy->withdrawals();
            $revision = $this->requests->revision($a->id);
            if ($new) {
                $r = ['id' => $request->value, 'application_id' => $a->id->value, 'owner_user_id' => $actor->value,
                    'status' => 'pending', 'version' => 0, 'reason' => $p['reason'], 'requested_at' => $now,
                    'application_status' => $a->status->value, 'application_version' => $a->version,
                    'policy_mode' => $policy['mode'], 'policy_revision' => $policy['revision'],
                    'request_revision' => $revision + 1, 'resolution' => null, 'receipts' => []];
            }
            $r['version']++;
            $decision = null;
            if (in_array($action, ['approve', 'direct'], true)) {
                $after = $a->changeReviewState(true);
                $this->admissions->saveReviewState($a, $after);
                $fact = ['channel' => $mine ? 'applicant' : 'staff', 'request_id' => $r['id'],
                    'requested_by' => $r['owner_user_id'], 'requested_at' => $r['requested_at'],
                    'policy_mode' => $r['policy_mode'], 'policy_revision' => $r['policy_revision']];
                $reason = $mine ? $p['reason']
                    : 'Applicant request: ' . $r['reason'] . "\nStaff response: " . $p['reason'];
                $decision = $this->ledger->changeReviewState(
                    $a,
                    $after,
                    $this->context->source($a),
                    $operation,
                    $actor,
                    $reason,
                    $fact
                );
                $a = $after;
            }
            $r['status'] = match ($action) {
                'request' => 'pending', 'direct' => 'withdrawn', 'approve' => 'approved',
                'decline' => 'declined', 'cancel' => 'cancelled'
            };
            if ($action !== 'request') {
                $r['resolution'] = ['action' => $action, 'actor' => $actor->value, 'at' => $now,
                    'response' => $p['reason'], 'decision_id' => $decision['decision_id'] ?? null];
            }
            $result = ['request_id' => $r['id'], 'status' => $r['status'], 'version' => $r['version'],
                'application_status' => $a->status->value, 'effective_at' => $now,
                'decision_id' => $decision['decision_id'] ?? null];
            $r['receipts'][$operation->value] = ['actor' => $actor->value, 'mine' => $mine, 'result' => $result];
            $this->requests->save($r, $revision, $new);
            if (!$mine && $this->admissions->person($a->personId)->ownerUserId?->value === $r['owner_user_id']) {
                $this->updates->record($a->id, new EntityId($r['owner_user_id']), $this->clock->now()
                    ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'));
            }
            $this->audit->record($actor, 'admissions.withdrawal_' . $action, $a->id);
            $this->authorize($a, $actor, $mine, true);
            return $result;
        });
    }
    private function action(string $action, bool $mine): void
    {
        if (!in_array($action, $mine ? ['request', 'direct', 'cancel'] : ['approve', 'decline'], true)) {
            throw new ValidationException([
                ['field' => 'action',
                    'message' => 'Choose an available withdrawal action.'],
            ]);
        }
    }
    private function eligible(AdmissionApplication $a, ?array $r, string $action, EntityId $actor): void
    {
        if (in_array($action, ['request', 'direct'], true)) {
            if (
                $r !== null || $this->requests->pending($a->id) !== null
                || !in_array($a->status, [ApplicationStatus::Submitted, ApplicationStatus::Accepted], true)
                || $this->policy->withdrawals()['mode'] !== ($action === 'direct' ? 'direct' : 'request')
            ) {
                throw new ValidationException([
                    ['field' => 'application',
                        'message' => 'This application cannot use that withdrawal action. Reload its '
                        . 'current policy and requests.'],
                ]);
            }
            return;
        }
        if (
            $r === null || $r['application_id'] !== $a->id->value || $r['status'] !== 'pending'
            || ($action === 'cancel' && $r['owner_user_id'] !== $actor->value)
        ) {
            throw new ConcurrentModificationException('This request is no longer awaiting that response.');
        }
        if ($action === 'approve') {
            if ($this->admissions->person($a->personId)->ownerUserId?->value !== $r['owner_user_id']) {
                throw new ValidationException([
                    ['field' => 'request',
                        'message' => 'The applicant account changed. Close this request and obtain a new '
                        . 'request from the current owner.'],
                ]);
            }
            $a->changeReviewState(true);
        }
    }
    private function lock(AdmissionApplication $route, EntityId $actor, bool $mine, bool $write): AdmissionApplication
    {
        if (!$mine && $write) {
            $this->permissions->lock();
        }
        $this->admissions->lockPerson($route->personId);
        $this->admissions->lockApplication($route->id);
        $a = $this->admissions->application($route->id);
        if ($a->personId->value !== $route->personId->value) {
            throw new ConcurrentModificationException('The application changed. Reload it.');
        }
        $this->authorize($a, $actor, $mine, $write);
        if ($write) {
            if ($a->status !== ApplicationStatus::Matriculated) {
                $this->catalogs->lockTerm($a->entryTermId);
                $this->catalogs->lockProgram($a->programId);
            }
            $this->policy->lock();
        }
        $this->ledger->verify($a);
        return $a;
    }
    private function authorize(?AdmissionApplication $a, EntityId $actor, bool $mine, bool $write): void
    {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException('The application account changed.');
        }
        if ($mine) {
            if ($a === null || $this->admissions->person($a->personId)->ownerUserId?->value !== $actor->value) {
                throw new AccessDeniedException('This account does not own the application.');
            }
        } elseif (
            $this->access->requirePermission('sis:admissions:manage')->value !== $actor->value
            || ($write && $this->access->requirePermission('sis:admissions:withdraw')->value !== $actor->value)
        ) {
            throw new AccessDeniedException('Additional admissions withdrawal authority is required.');
        }
    }
    private function fingerprint(AdmissionApplication $a, #[\SensitiveParameter] ?array $r): string
    {
        $person = $this->admissions->person($a->personId);
        return hash('sha256', json_encode([AdmissionDecisionSchema::facts($a), $this->ledger->verify($a),
            $person->ownerUserId?->value, $person->identityVersion, $this->requests->revision($a->id),
            $this->policy->withdrawals(), $r], JSON_THROW_ON_ERROR));
    }
    private function display(#[\SensitiveParameter] array $r): array
    {
        return ['id' => $r['id'], 'status' => $r['status'], 'version' => $r['version'], 'reason' => $r['reason'],
            'requested_at' => $r['requested_at'], 'application_status' => $r['application_status'],
            'response' => $r['resolution']['response'] ?? null, 'resolved_at' => $r['resolution']['at'] ?? null];
    }
}
