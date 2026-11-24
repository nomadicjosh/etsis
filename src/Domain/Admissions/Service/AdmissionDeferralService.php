<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\Repository\AdmissionWithdrawalRepository;
use Domain\Admissions\Repository\AdmissionUpdatesRepository;
use Domain\Admissions\ValueObject\AdmissionDecisionSchema;
use Domain\Admissions\ValueObject\AdmissionDeferralSchema;
use Domain\Admissions\Repository\ApplicationRevisionRepository;
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

/** Reviewed staff placement; no student/cohort or replacement application is created here. */
final readonly class AdmissionDeferralService
{
    public function __construct(
        private AdmissionsRepository $admissions,
        private AdmissionDecisionLedger $ledger,
        private ApplicationContext $context,
        private AdmissionEntryTerms $terms,
        private AdmissionWithdrawalRepository $requests,
        private AdmissionUpdatesRepository $updates,
        private StudentAccess $access,
        private AccessRepository $permissions,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private ApplicationRevisionRepository $answers,
    ) {
    }
    public function current(EntityId $id): array
    {
        $this->access->requirePermission('sis:admissions:manage');
        $route = $this->admissions->application($id);
        $correction = $route->status === ApplicationStatus::Submitted;
        $actor = $this->actor($correction);
        return $this->transactions->run(function () use ($actor, $route, $correction): array {
            $a = $this->lock($route, $actor, $correction);
            $current = $this->terms->snapshot($a->entryTermId);
            $entry = $this->ledger->entryApproval($a);
            $result = ['person' => $this->person($a), 'current' => $current, 'approved' => $entry['after'] ?? null,
                'application_status' => $a->status->value,
                'answers_pending' => $this->answers->pending($a->id) !== null,
                    'withdrawal_pending' => $this->requests->pending($a->id) !== null,
                'needs_review' => $entry !== null && $current != $entry['after']];
            $this->authorize($actor, $correction);
            return $result;
        });
    }
    public function choices(EntityId $id, string $search, ?EntityId $after = null): array
    {
        $this->access->requirePermission('sis:admissions:manage');
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80
            || preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new ValidationException([['field' => 'search',
                'message' => 'Enter a term code or name up to 80 characters.']]);
        }
        $route = $this->admissions->application($id);
        $correction = $route->status === ApplicationStatus::Submitted;
        $actor = $this->actor($correction);
        return $this->transactions->run(function () use ($route, $actor, $correction, $search, $after): array {
            $a = $this->lock($route, $actor, $correction);
            $this->eligible($a, $correction);
            $current = $this->terms->snapshot($a->entryTermId);
            $rows = $this->admissions->entryTerms(
                $this->clock->now()->format('Y-m-d'),
                trim($search),
                $a->entryTermId,
                $after,
                $correction ? null : $current['starts_on']
            );
            $more = count($rows) > 20;
            $rows = array_slice($rows, 0, 20);
            $this->authorize($actor, $correction);
            return ['items' => $rows, 'next' => $more ? $rows[array_key_last($rows)]['id'] : null];
        });
    }
    public function review(EntityId $id, EntityId $target, string $kind, #[\SensitiveParameter] string $reason): array
    {
        $correction = AdmissionDeferralSchema::correction($kind);
        $actor = $this->actor($correction);
        if (!in_array($kind, ['defer', 'reconfirm', 'correct', 'recheck'], true)) {
            throw new ValidationException([['field' => 'kind', 'message' => 'Choose an intake action.']]);
        }
        if (!AdmissionOfferSchema::text($reason, 2000)) {
            throw new ValidationException([['field' => 'reason',
                'message' => 'Enter an approval reason up to 2,000 characters.']]);
        }
        $route = $this->admissions->application($id);
        return $this->transactions->run(function () use ($route, $actor, $target, $kind, $reason, $correction): array {
            $a = $this->lock($route, $actor, $correction);
            $this->terms->lock($a, $target);
            $plan = $this->plan($a, $target, $kind);
            $after = $a->approveEntryTerm($target);
            $source = $this->context->source($after);
            $token = $this->cipher->seal(json_encode(
                ['purpose' => 'admission-entry-term',
                'application' => $a->id->value,
                'actor' => $actor->value, 'operation' => EntityId::generate()->value, 'target' => $target->value,
                'kind' => $kind, 'reason' => trim($reason), 'context' => $this->fingerprint($a, $plan, $source),
                'expires' => $this->clock->now()->getTimestamp() + 1200],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ));
            $this->authorize($actor, $correction);
            return $plan + ['person' => $this->person($a),
                'source' => AdmissionDecisionSchema::displaySource($source), 'review_token' => $token];
        });
    }
    public function commit(EntityId $id, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission('sis:admissions:manage');
        try {
            if (strlen($token) > 100000) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 10, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) || count($p) !== 9 || ($p['purpose'] ?? null) !== 'admission-entry-term'
                || ($p['application'] ?? null) !== $id->value || ($p['actor'] ?? null) !== $actor->value
                || !in_array($p['kind'] ?? null, ['defer', 'reconfirm', 'correct', 'recheck'], true)
                || !AdmissionOfferSchema::text($p['reason'] ?? null, 2000) || !is_string($p['context'] ?? null)
                || !is_int($p['expires'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $operation = new EntityId($p['operation']);
            $target = new EntityId($p['target']);
        } catch (\Throwable) {
            throw new ValidationException([['field' => 'review_token',
                'message' => 'Review this intake approval again.']]);
        }
        $correction = AdmissionDeferralSchema::correction($p['kind']);
        $this->authorize($actor, $correction);
        $route = $this->admissions->application($id);
        return $this->transactions->run(function () use ($route, $actor, $p, $operation, $target, $correction): array {
            $a = $this->lock($route, $actor, $correction);
            $receipt = $this->ledger->receipt($a, $operation, $actor);
            if ($receipt !== null) {
                $this->authorize($actor, $correction);
                return $receipt;
            }
            $this->terms->lock($a, $target);
            $plan = $this->plan($a, $target, $p['kind']);
            $after = $a->approveEntryTerm($target);
            $source = $this->context->source($after);
            if (
                $p['expires'] <= $this->clock->now()->getTimestamp()
                || !hash_equals($p['context'], $this->fingerprint($a, $plan, $source))
            ) {
                throw new ConcurrentModificationException('This intake review changed or expired. Review it again.');
            }
            $this->admissions->saveEntryTerm($a, $after);
            $result = $this->ledger->approveEntryTerm($a, $after, $plan, $source, $operation, $actor, $p['reason']);
            $owner = $this->admissions->person($a->personId)->ownerUserId;
            if ($owner !== null) {
                $this->updates->record(
                    $a->id,
                    $owner,
                    $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')
                );
            }
            $accepted = $this->fingerprint($after, $plan, $source);
            $this->audit->record($actor, 'admissions.entry_' . $p['kind'], $a->id);
            $this->authorize($actor, $correction);
            if (
                $this->admissions->application($after->id) != $after
                || !hash_equals($accepted, $this->fingerprint($after, $plan, $this->context->source($after)))
            ) {
                throw new ConcurrentModificationException('The intake changed during confirmation. Review it again.');
            }
            return $result;
        });
    }
    private function plan(AdmissionApplication $a, EntityId $target, string $kind): array
    {
        $correction = AdmissionDeferralSchema::correction($kind);
        $this->eligible($a, $correction);
        $entry = $this->ledger->entryApproval($a);
        $before = $entry['after'] ?? $this->terms->snapshot($a->entryTermId);
        $after = $this->terms->snapshot($target);
        if (
            $after['academic_year_id'] === null || $after['starts_on'] < $after['year_starts_on']
            || $after['ends_on'] > $after['year_ends_on']
        ) {
            throw new ValidationException([['field' => 'target_term',
                'message' => 'Link the target term to its correct academic year '
                    . 'and review its calendar dates first.']]);
        }
        if (in_array($kind, ['defer', 'correct'], true)) {
            if (
                $target->value === $a->entryTermId->value
                || (!$correction && $after['starts_on'] <= $before['starts_on'])
                || $after['starts_on'] < $this->clock->now()->format('Y-m-d')
            ) {
                throw new ValidationException([['field' => 'target_term',
                    'message' => $correction ? 'Choose a different intake that has not started.'
                        : 'Choose a later intake that has not started.']]);
            }
        } elseif (
            !in_array($kind, ['reconfirm', 'recheck'], true) || $entry === null
            || $target->value !== $a->entryTermId->value || $before == $after
        ) {
            throw new ValidationException([['field' => 'target_term',
                'message' => 'There is no changed approved intake to review.']]);
        }
        return ['kind' => $kind, 'before' => $before, 'after' => $after];
    }
    private function eligible(AdmissionApplication $a, bool $correction): void
    {
        $expected = $correction ? ApplicationStatus::Submitted : ApplicationStatus::Accepted;
        if ($a->status !== $expected || $this->requests->pending($a->id) !== null) {
            throw new ValidationException([['field' => 'application',
                'message' => 'Review the application status and resolve any withdrawal request first.']]);
        }
        if ($correction && $this->answers->pending($a->id) !== null) {
            throw new ValidationException([['field' => 'answers',
                'message' => 'Resolve the answer correction request before correcting the entry term.']]);
        }
    }
    private function person(AdmissionApplication $a): array
    {
        $p = $this->admissions->person($a->personId);
        return ['given_name' => $p->givenName, 'family_name' => $p->familyName,
            'institutional_id' => $p->institutionalId->value];
    }
    private function actor(bool $correction = false): EntityId
    {
        $actor = $this->access->requirePermission('sis:admissions:manage');
        $permission = $correction ? 'sis:admissions:correct' : 'sis:admissions:defer';
        if ($this->access->requirePermission($permission)->value !== $actor->value) {
            throw new AccessDeniedException('The intake approval actor changed.');
        }
        return $actor;
    }
    private function authorize(EntityId $actor, bool $correction = false): void
    {
        if ($this->actor($correction)->value !== $actor->value) {
            throw new AccessDeniedException('The intake approval actor changed.');
        }
    }
    private function lock(AdmissionApplication $route, EntityId $actor, bool $correction = false): AdmissionApplication
    {
        $this->permissions->lock();
        $this->admissions->lockPerson($route->personId);
        $this->admissions->lockApplication($route->id);
        $this->authorize($actor, $correction);
        $a = $this->admissions->application($route->id);
        if (
            $a->personId->value !== $route->personId->value || $a->programId->value !== $route->programId->value
            || $a->status !== $route->status
        ) {
            throw new ConcurrentModificationException('The application changed. Reload it.');
        }
        $this->ledger->verify($a);
        return $a;
    }
    private function fingerprint(AdmissionApplication $a, array $plan, array $source): string
    {
        $person = $this->admissions->person($a->personId);
        $submission = $this->context->submission($a->id);
        return hash('sha256', json_encode([AdmissionDecisionSchema::facts($a), $this->ledger->verify($a),
            $this->requests->revision($a->id), $this->answers->revision($a->id),
            $person->identityVersion, $person->ownerUserId?->value,
            $person->givenName, $person->familyName, $person->institutionalId->value, $plan, $source,
            $this->terms->snapshot($a->entryTermId), $this->clock->now()->format('Y-m-d'),
            $submission === null ? null : hash('sha256', $submission['encrypted_answers'])], JSON_THROW_ON_ERROR));
    }
}
