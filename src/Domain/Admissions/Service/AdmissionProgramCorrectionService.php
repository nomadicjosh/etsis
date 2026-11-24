<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\Repository\AdmissionUpdatesRepository;
use Domain\Admissions\Repository\AdmissionWithdrawalRepository;
use Domain\Admissions\Repository\ApplicationFormRepository;
use Domain\Admissions\Repository\ApplicationRevisionRepository;
use Domain\Admissions\ValueObject\AdmissionDecisionSchema;
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

/** Reviewed current application placement, independent of its immutable original submission. */
final readonly class AdmissionProgramCorrectionService
{
    public function __construct(
        private AdmissionsRepository $admissions,
        private AdmissionDecisionLedger $ledger,
        private ApplicationContext $context,
        private AdmissionEntryTerms $terms,
        private AdmissionWithdrawalRepository $withdrawals,
        private ApplicationRevisionRepository $answers,
        private ApplicationFormRepository $forms,
        private AdmissionUpdatesRepository $updates,
        private AccessRepository $permissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    public function current(EntityId $id): array
    {
        $actor = $this->actor();
        $route = $this->admissions->application($id);
        return $this->transactions->run(function () use ($actor, $route): array {
            $a = $this->lock($route, $actor);
            $person = $this->admissions->person($a->personId);
            $result = ['current' => $this->admissions->program($a->programId), 'status' => $a->status->value,
                'person' => ['given_name' => $person->givenName, 'family_name' => $person->familyName,
                    'institutional_id' => $person->institutionalId->value],
                'program_form' => $this->programForm($a),
                'answers_pending' => $this->answers->pending($a->id) !== null,
                'withdrawal_pending' => $this->withdrawals->pending($a->id) !== null];
            $this->authorize($actor);
            return $result;
        });
    }

    public function choices(EntityId $id, string $search, ?EntityId $after = null): array
    {
        $actor = $this->actor();
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80
            || preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new ValidationException([['field' => 'search',
                'message' => 'Enter a program code or title up to 80 characters.']]);
        }
        $route = $this->admissions->application($id);
        return $this->transactions->run(function () use ($actor, $route, $search, $after): array {
            $a = $this->lock($route, $actor);
            $this->eligible($a);
            $submission = $this->context->submission($a->id);
            $rows = $this->admissions->programChoices(
                trim($search),
                $a->programId,
                $after,
                sharedForm: $submission === null ? null : new EntityId($submission['form_id'])
            );
            $more = count($rows) > 20;
            $rows = array_slice($rows, 0, 20);
            $this->authorize($actor);
            return ['items' => $rows, 'next' => $more ? $rows[array_key_last($rows)]['id'] : null];
        });
    }

    public function review(EntityId $id, EntityId $target, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->actor();
        if (!AdmissionOfferSchema::text($reason, 2000)) {
            throw new ValidationException([['field' => 'reason',
                'message' => 'Enter a correction reason up to 2,000 characters.']]);
        }
        $route = $this->admissions->application($id);
        return $this->transactions->run(function () use ($route, $actor, $target, $reason): array {
            $a = $this->lock($route, $actor);
            $this->terms->lock($a, program: $target);
            $plan = $this->plan($a, $target);
            $source = $this->context->source($a->correctProgram($target));
            $token = $this->cipher->seal(json_encode(['purpose' => 'admission-program-correction',
                'application' => $a->id->value, 'actor' => $actor->value,
                'operation' => EntityId::generate()->value, 'target' => $target->value, 'reason' => trim($reason),
                'context' => $this->fingerprint($a, $plan, $source),
                'expires' => $this->clock->now()->getTimestamp() + 1200], JSON_THROW_ON_ERROR));
            $person = $this->admissions->person($a->personId);
            $this->authorize($actor);
            return $plan + ['person' => ['given_name' => $person->givenName, 'family_name' => $person->familyName,
                'institutional_id' => $person->institutionalId->value], 'review_token' => $token];
        });
    }

    public function commit(EntityId $id, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->actor();
        try {
            if (strlen($token) > 100000) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) || count($p) !== 8 || ($p['purpose'] ?? null) !== 'admission-program-correction'
                || ($p['application'] ?? null) !== $id->value || ($p['actor'] ?? null) !== $actor->value
                || !AdmissionOfferSchema::text($p['reason'] ?? null, 2000)
                || !is_string($p['context'] ?? null) || !is_int($p['expires'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $target = new EntityId($p['target']);
            $operation = new EntityId($p['operation']);
        } catch (\Throwable) {
            throw new ValidationException([['field' => 'review_token',
                'message' => 'Review this program correction again.']]);
        }
        $route = $this->admissions->application($id);
        return $this->transactions->run(function () use ($route, $actor, $p, $target, $operation): array {
            $a = $this->lock($route, $actor);
            $receipt = $this->ledger->receipt($a, $operation, $actor);
            if ($receipt !== null) {
                $this->authorize($actor);
                return $receipt;
            }
            $this->terms->lock($a, program: $target);
            $plan = $this->plan($a, $target);
            $after = $a->correctProgram($target);
            $source = $this->context->source($after);
            $this->fresh($p, $this->fingerprint($a, $plan, $source));
            $this->admissions->saveProgram($a, $after);
            $result = $this->ledger->correctProgram($a, $after, $plan, $source, $operation, $actor, $p['reason']);
            $owner = $this->admissions->person($a->personId)->ownerUserId;
            if ($owner !== null) {
                $this->updates->record(
                    $a->id,
                    $owner,
                    $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')
                );
            }
            $accepted = $this->fingerprint($after, $plan, $source);
            $this->audit->record($actor, 'admissions.program_corrected', $a->id);
            $this->authorize($actor);
            $this->eligible($after);
            if ($this->admissions->application($after->id) != $after) {
                throw new ConcurrentModificationException('The application changed during program confirmation.');
            }
            $this->fresh($p, $p['context']);
            if (
                !hash_equals($accepted, $this->fingerprint(
                    $after,
                    $this->planSnapshots($a, $target),
                    $this->context->source($after)
                ))
            ) {
                throw new ConcurrentModificationException('The program changed during confirmation. Review it again.');
            }
            return $result;
        });
    }

    private function fresh(array $proof, string $context): void
    {
        if ($proof['expires'] <= $this->clock->now()->getTimestamp() || !hash_equals($proof['context'], $context)) {
            throw new ConcurrentModificationException('This program review changed or expired. Review it again.');
        }
    }

    private function plan(AdmissionApplication $a, EntityId $target): array
    {
        $this->eligible($a);
        $a->correctProgram($target);
        $this->terms->verify($a, $this->ledger->entryApproval($a));
        $term = $this->terms->snapshot($a->entryTermId);
        if ($term['starts_on'] < $this->clock->now()->format('Y-m-d')) {
            throw new ValidationException([['field' => 'entry_term',
                'message' => 'Program corrections require an intake that has not started.']]);
        }
        return $this->planSnapshots($a, $target);
    }

    private function planSnapshots(AdmissionApplication $a, EntityId $target): array
    {
        $submission = $this->context->submission($a->id);
        $formReference = null;
        if ($submission !== null) {
            $form = $this->forms->get(new EntityId($submission['form_id']));
            if ($form->academicLevelId !== null) {
                $this->forms->lockLevel($form->academicLevelId);
            }
            try {
                $selected = $this->forms->active($target);
            } catch (\Domain\Shared\Exception\RecordNotFoundException) {
                throw new ValidationException([['field' => 'target_program',
                    'message' => 'Choose a program using the same active shared application form.']]);
            }
            if ($selected->id->value !== $form->id->value) {
                throw new ValidationException([['field' => 'target_program',
                    'message' => 'Choose a program using the same active shared application form.']]);
            }
            $formReference = ['id' => $selected->id->value, 'academic_level_id' => $selected->academicLevelId?->value,
                'definition' => \Domain\Admissions\ValueObject\SubmissionIntegrity::formHash($selected)];
        }
        return ['before' => $this->admissions->program($a->programId),
            'after' => $this->admissions->program($target), 'target_form' => $formReference];
    }

    private function eligible(AdmissionApplication $a): void
    {
        if (
            $a->status !== ApplicationStatus::Submitted || $this->withdrawals->pending($a->id) !== null
            || $this->answers->pending($a->id) !== null
        ) {
            throw new ValidationException([['field' => 'application',
                'message' => 'Resolve pending requests on an application awaiting a decision.']]);
        }
        if ($this->programForm($a)) {
            throw new ValidationException([['field' => 'form',
                'message' => 'A program-specific form requires a new application for the target program.']]);
        }
    }

    private function programForm(AdmissionApplication $a): bool
    {
        $submission = $this->context->submission($a->id);
        return $submission !== null && $this->forms->get(new EntityId($submission['form_id']))->programId !== null;
    }

    private function actor(): EntityId
    {
        $actor = $this->access->requirePermission('sis:admissions:manage');
        if ($this->access->requirePermission('sis:admissions:correct')->value !== $actor->value) {
            throw new AccessDeniedException('The program correction actor changed.');
        }
        return $actor;
    }

    private function authorize(EntityId $actor): void
    {
        if ($this->actor()->value !== $actor->value) {
            throw new AccessDeniedException('The program correction actor changed.');
        }
    }

    private function lock(AdmissionApplication $route, EntityId $actor): AdmissionApplication
    {
        $this->permissions->lock();
        $this->admissions->lockPerson($route->personId);
        $this->admissions->lockApplication($route->id);
        $this->authorize($actor);
        $a = $this->admissions->application($route->id);
        if ($a != $route) {
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
            $person->identityVersion, $person->ownerUserId?->value, $person->givenName, $person->familyName,
            $person->institutionalId->value, $plan, $source, $this->terms->snapshot($a->entryTermId),
            $this->withdrawals->revision($a->id), $this->answers->revision($a->id),
            $this->clock->now()->format('Y-m-d'),
            $submission === null ? null : hash('sha256', $submission['encrypted_answers'])], JSON_THROW_ON_ERROR));
    }
}
