<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Repository\AdmissionOfferPolicyRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\ValueObject\AdmissionOfferSchema;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** One review/confirmation path for staff letters and owner responses; explicit entry-point ownership. */
final readonly class AdmissionOfferService
{
    public const int MAX_REVIEW_BYTES = 600000;
    public function __construct(
        private AdmissionsRepository $admissions,
        private AdmissionDecisionLedger $ledger,
        private AdmissionOfferPolicyRepository $policy,
        private StudentAccess $access,
        private AccessRepository $permissions,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private \Domain\Admissions\Repository\AdmissionUpdatesRepository $updates,
    ) {
    }
    public function policy(): array
    {
        $actor = $this->access->requirePermission('sis:admissions:manage');
        $result = $this->policy->get();
        $this->authorize(null, $actor, false, false);
        return $result;
    }
    public function savePolicy(bool $required, int $revision): array
    {
        $actor = $this->access->requirePermission('sis:admissions:policy');
        return $this->transactions->run(function () use ($required, $revision, $actor): array {
            $this->permissions->lock();
            $this->policy->lock();
            $this->policyActor($actor);
            $this->policy->save($required, $revision);
            $this->audit->record(
                $actor,
                'admissions.offer_policy_changed',
                new EntityId(AdmissionOfferPolicyRepository::ID)
            );
            $this->policyActor($actor);
            return $this->policy->get();
        });
    }
    private function policyActor(EntityId $actor): void
    {
        if (
            $this->access->requirePermission('sis:admissions:policy')->value !== $actor->value
            || $this->access->requirePermission('sis:admissions:manage')->value !== $actor->value
        ) {
            throw new AccessDeniedException('The policy actor changed.');
        }
    }
    public function current(EntityId $id, bool $mine): array
    {
        $actor = $this->access->authenticatedUserId();
        $route = $this->admissions->application($id);
        return $this->transactions->run(function () use ($route, $actor, $mine): array {
            $a = $this->lock($route, $actor, $mine, false);
            $record = $this->ledger->offer($a);
            $offer = $record['offer'] ?? null;
            // A person/account relink does not transfer a previously addressed private letter.
            if ($mine && $offer !== null && $offer['owner_id'] !== $actor->value) {
                $offer = null;
            }
            $result = ['offer' => $offer, 'application_status' => $a->status->value,
                'policy' => $this->policy->get(), 'response_open' => $offer !== null
                    && $a->status === ApplicationStatus::Accepted && $this->open($offer)];
            $this->authorize($a, $actor, $mine, false);
            return $result;
        });
    }
    public function review(EntityId $id, #[\SensitiveParameter] array $input, bool $mine): array
    {
        $actor = $this->access->authenticatedUserId();
        $route = $this->admissions->application($id);
        return $this->transactions->run(function () use ($route, $input, $actor, $mine): array {
            $a = $this->lock($route, $actor, $mine, true);
            $action = $input['action'] ?? null;
            if (!in_array($action, $mine ? ['accept', 'decline'] : ['issue', 'accept', 'decline', 'cancel'], true)) {
                throw new ValidationException([
                    ['field' => 'action', 'message' => 'Choose an available offer action.'],
                ]);
            }
            $reason = $mine ? 'Applicant confirmed their admission offer response.' : ($input['reason'] ?? null);
            if (!AdmissionOfferSchema::text($reason, 10000)) {
                throw new ValidationException([
                    ['field' => 'reason', 'message' => 'Enter a reason up to 10,000 characters.'],
                ]);
            }
            $operation = EntityId::generate();
            $offer = $this->prepare($a, $action, $input, $operation, $actor, $mine);
            $token = $this->cipher->seal(json_encode(['purpose' => 'admission-offer', 'application' => $a->id->value,
                'actor' => $actor->value, 'mine' => $mine, 'operation' => $operation->value, 'action' => $action,
                'offer' => $offer, 'reason' => $reason, 'context' => $this->fingerprint($a),
                'expires' => $this->clock->now()->getTimestamp() + 1200], JSON_THROW_ON_ERROR));
            $this->authorize($a, $actor, $mine, true);
            return ['offer' => $offer, 'action' => $action, 'review_token' => $token];
        });
    }
    public function commit(EntityId $id, #[\SensitiveParameter] string $token, bool $mine): array
    {
        $actor = $this->access->authenticatedUserId();
        try {
            if (strlen($token) > self::MAX_REVIEW_BYTES) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 12, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) || count($p) !== 10 || ($p['purpose'] ?? null) !== 'admission-offer'
                || ($p['actor'] ?? null) !== $actor->value || ($p['application'] ?? null) !== $id->value
                || ($p['mine'] ?? null) !== $mine || !is_string($p['operation'] ?? null)
                || !is_array($p['offer'] ?? null) || !is_string($p['context'] ?? null)
                || !is_int($p['expires'] ?? null) || !AdmissionOfferSchema::text($p['reason'] ?? null, 10000)
                || !in_array(
                    $p['action'] ?? null,
                    $mine ? ['accept', 'decline'] : ['issue', 'accept', 'decline', 'cancel'],
                    true
                )
            ) {
                throw new \InvalidArgumentException();
            }
            $operation = new EntityId($p['operation']);
        } catch (\Throwable) {
            throw new ValidationException([
                    ['field' => 'review_token', 'message' => 'Review this admission offer again.'],
                ]);
        }
        $route = $this->admissions->application($id);
        return $this->transactions->run(function () use ($route, $actor, $mine, $p, $operation): array {
            $a = $this->lock($route, $actor, $mine, true);
            $receipt = $this->ledger->receipt($a, $operation, $actor);
            if ($receipt !== null) {
                $this->authorize($a, $actor, $mine, true);
                return $receipt;
            }
            if (
                $p['expires'] <= $this->clock->now()->getTimestamp()
                || !hash_equals($p['context'], $this->fingerprint($a))
            ) {
                throw new ConcurrentModificationException('This offer review changed or expired. Review it again.');
            }
            $after = $a->recordOffer();
            // Recheck response availability/deadline at confirmation, even if the review has not expired.
            if ($p['action'] !== 'issue') {
                $offer = $this->prepare($a, $p['action'], [], $operation, $actor, $mine);
                if ($offer !== $p['offer']) {
                    throw new ConcurrentModificationException('This admission offer changed. Review it again.');
                }
            }
            $this->admissions->saveOffer($a, $after);
            $result = $this->ledger->recordOffer($a, $after, ['action' => $p['action'],
                'channel' => $mine ? 'applicant' : 'staff', 'offer' => $p['offer']], $operation, $actor, $p['reason']);
            if (!$mine) {
                $this->updates->record(
                    $a->id,
                    new EntityId($p['offer']['owner_id']),
                    $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')
                );
            }
            $this->audit->record($actor, 'admissions.offer_' . $p['action'], $a->id);
            $this->authorize($a, $actor, $mine, true);
            return $result;
        });
    }
    private function prepare(
        AdmissionApplication $a,
        string $action,
        #[\SensitiveParameter] array $input,
        EntityId $operation,
        EntityId $actor,
        bool $mine
    ): array {
        $a->recordOffer();
        $owner = $this->admissions->person($a->personId)->ownerUserId;
        if ($owner === null) {
            throw new ValidationException([
                    ['field' => 'person', 'message' => 'Link this person to their sign-in account first.'],
                ]);
        }
        if ($action === 'issue') {
            foreach (['title' => 120, 'message' => 10000] as $key => $max) {
                if (!AdmissionOfferSchema::text($input[$key] ?? null, $max)) {
                    throw new ValidationException([['field' => $key, 'message' => 'Enter the offer ' . $key . '.']]);
                }
            }
            $deadline = $input['respond_by'] ?? '';
            if (!is_string($deadline) || strlen($deadline) > 10) {
                throw new ValidationException([
                    ['field' => 'respond_by', 'message' => 'Choose a valid response date.'],
                ]);
            }
            $date = $deadline === '' ? null : \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $deadline,
                $this->clock->now()->getTimezone()
            );
            if (
                $deadline !== '' && (!$date || $date->format('Y-m-d') !== $deadline
                || $date->format('Y-m-d') < $this->clock->now()->format('Y-m-d'))
            ) {
                throw new ValidationException([
                    ['field' => 'respond_by', 'message' => 'Choose today or a later response date.'],
                ]);
            }
            $policy = $this->policy->get();
            return ['id' => $operation->value, 'owner_id' => $owner->value,
                'title' => trim($input['title']), 'message' => trim($input['message']),
                'respond_by' => $date?->setTime(23, 59, 59)->format(DATE_ATOM),
                'require_acceptance' => $policy['require_acceptance'],
                'policy_revision' => $policy['revision'], 'status' => 'pending'];
        }
        $offer = $this->ledger->offer($a)['offer'] ?? null;
        if (
            $offer === null || $offer['owner_id'] !== $owner->value
            || ($mine && $offer['owner_id'] !== $actor->value)
            || ($action === 'cancel'
                ? !in_array($offer['status'], ['pending', 'accepted'], true) : !$this->open($offer))
        ) {
            throw new ValidationException([
                    ['field' => 'offer',
                        'message' => 'This offer cannot receive that action. Reload its current status.'],
                ]);
        }
        $offer['status'] = match ($action) {
            'accept' => 'accepted', 'decline' => 'declined', 'cancel' => 'cancelled'
        };
        return $offer;
    }
    private function open(#[\SensitiveParameter] array $offer): bool
    {
        return $offer['status'] === 'pending' && ($offer['respond_by'] === null
            || new \DateTimeImmutable($offer['respond_by']) >= $this->clock->now());
    }
    private function lock(AdmissionApplication $route, EntityId $actor, bool $mine, bool $write): AdmissionApplication
    {
        if ($write && !$mine) {
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
            $this->policy->lock();
        }
        $this->ledger->verify($a);
        return $a;
    }
    private function authorize(?AdmissionApplication $a, EntityId $actor, bool $mine, bool $write): void
    {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException('The admission offer actor changed.');
        }
        if ($mine) {
            if ($a === null || $this->admissions->person($a->personId)->ownerUserId?->value !== $actor->value) {
                throw new AccessDeniedException('This application belongs to another person.');
            }
        } elseif (
            $this->access->requirePermission('sis:admissions:manage')->value !== $actor->value
            || ($write && $this->access->requirePermission('sis:admissions:offers')->value !== $actor->value)
        ) {
            throw new AccessDeniedException('Additional admissions authority is required.');
        }
    }
    private function fingerprint(AdmissionApplication $a): string
    {
        $p = $this->admissions->person($a->personId);
        return hash('sha256', json_encode([$a, $this->ledger->verify($a), $p->ownerUserId?->value,
            $p->identityVersion, $this->policy->get(), $this->clock->now()->format('Y-m-d')], JSON_THROW_ON_ERROR));
    }
}
