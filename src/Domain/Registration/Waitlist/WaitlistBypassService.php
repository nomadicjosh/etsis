<?php

declare(strict_types=1);

namespace Domain\Registration\Waitlist;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\ValueObject\RegistrationChargeQuote;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Service\RegistrationBatchService;
use Domain\Registration\Service\RegistrationService;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

/** An explicit exception for one active later request, never a general registration bypass. */
final readonly class WaitlistBypassService
{
    public function __construct(
        private WaitlistLedger $ledger,
        private RegistrationRepository $records,
        private AdmissionsRepository $people,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private ReviewProof $proofs,
        private RegistrationBatchService $assessments,
        private RegistrationService $registration,
    ) {
    }

    public function review(
        EntityId $student,
        EntityId $section,
        EntityId $entry,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        return $this->transactions->run(function () use ($student, $section, $entry, $reason, $actor): array {
            $person = $this->lock($student, $section);
            $this->authorize($actor);
            $budget = WaitlistLedger::budget();
            $root = $this->ledger->load($section, $budget);
            $this->selected($root, $student, $person, $entry);
            $quote = null;
            $context = $this->assessments->assessWaitlistBypass($student, $section, $budget, $quote);
            $bypass = new WaitlistBypass(
                $section,
                $student,
                $person,
                $entry,
                EntityId::generate(),
                $root->playhead(),
                WaitlistPolicy::hash($context),
                $reason,
                '',
            );
            $this->authorize($actor);
            return [
                'operation_id' => $bypass->operation->value,
                'student_id' => $student->value,
                'person_id' => $person->value,
                'entry_id' => $entry->value,
                'version' => $bypass->version,
                'assessment_revision' => $bypass->assessmentRevision,
                'reason' => $reason,
                'review_token' => $this->proofs->issue(
                    'waitlist',
                    'priority_bypass',
                    $section,
                    $actor,
                    $bypass->operation,
                    $bypass->revision(),
                ),
                'section' => $context['section'],
                'student' => $context['students'][0],
                'earlier_requests' => array_search($entry->value, array_column($root->active(), 'id'), true),
                'can_confirm' => $this->ready($context, $quote),
            ];
        });
    }

    public function history(EntityId $section, ?int $before = null, ?int $through = null): array
    {
        $actor = $this->authorize();
        $offering = $this->records->section($section);
        $root = $this->ledger->load($section, WaitlistLedger::budget());
        $through ??= $root->playhead();
        if (
            $through < 0 || $through > $root->playhead() ||
            ($before !== null && ($before < 1 || $before > $through + 1))
        ) {
            throw new ValidationException([['field' => 'before', 'message' => 'Use a valid history page.']]);
        }
        $rows = array_values(array_filter(array_reverse($root->bypasses()), static fn(array $row): bool =>
            $row['accepted_position'] <= $through && ($before === null || $row['accepted_position'] < $before)));
        $items = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $student = $this->people->student(new EntityId($row['student_id']));
            $person = $this->people->person(new EntityId($row['person_id']));
            if ($student->personId->value !== $person->id->value) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            unset($row['person_id']);
            $items[] = $row + ['current_name' => $person->givenName . ' ' . $person->familyName,
                'current_institutional_id' => $person->institutionalId->value];
        }
        $this->audit->record($actor, 'waitlist.bypass_history_read', $section);
        $this->authorize($actor);
        return ['section_code' => $offering->code, 'items' => $items, 'through' => $through,
            'next_before' => count($rows) > 20 ? $items[19]['accepted_position'] : null];
    }

    public function confirm(#[\SensitiveParameter] WaitlistBypass $bypass): array
    {
        $actor = $this->authorize();
        if ($this->reason($bypass->reason) !== $bypass->reason) {
            throw new ValidationException([['field' => 'reason', 'message' => 'Review the exact reason again.']]);
        }
        return $this->transactions->run(function () use ($bypass, $actor): array {
            $person = $this->lock($bypass->student, $bypass->section);
            if ($person->value !== $bypass->person->value) {
                throw new ConcurrentModificationException('The selected student identity changed. Review again.');
            }
            $this->authorize($actor);
            $budget = WaitlistLedger::budget();
            $root = $this->ledger->load($bypass->section, $budget);
            $accepted = $root->receipt($bypass->operation);
            $this->proofs->validate(
                $bypass->token,
                'waitlist',
                'priority_bypass',
                $bypass->section,
                $actor,
                $bypass->operation,
                $bypass->revision(),
                $accepted !== null,
            );
            if ($accepted !== null) {
                if (
                    ($accepted['bypass_revision'] ?? null) !== $bypass->revision() ||
                    $accepted['actor_id'] !== $actor->value || $accepted['entry_id'] !== $bypass->entry->value ||
                    $accepted['student_id'] !== $bypass->student->value ||
                    $accepted['person_id'] !== $bypass->person->value
                ) {
                    throw new ConcurrentModificationException('This bypass operation has another accepted outcome.');
                }
                $this->authorize($actor);
                return $this->result($bypass, $accepted);
            }
            if ($root->playhead() !== $bypass->version) {
                throw new ConcurrentModificationException('The waitlist changed. Review the bypass again.');
            }
            $this->selected($root, $bypass->student, $person, $bypass->entry);
            $quote = null;
            $context = $this->assessments->assessWaitlistBypass(
                $bypass->student,
                $bypass->section,
                $budget,
                $quote,
            );
            if (!hash_equals($bypass->assessmentRevision, WaitlistPolicy::hash($context))) {
                throw new ConcurrentModificationException('Registration facts or prices changed. Review again.');
            }
            if (!$this->ready($context, $quote)) {
                throw new ValidationException([[
                    'field' => 'registration',
                    'message' => 'Resolve registration eligibility, capacity and pricing issues before confirming.',
                ]]);
            }
            $this->audit->record($actor, 'registration.waitlist_bypassed', $bypass->entry);
            $this->authorize($actor);
            $context = $this->assessments->assessWaitlistBypass(
                $bypass->student,
                $bypass->section,
                $budget,
                $quote,
            );
            if (!hash_equals($bypass->assessmentRevision, WaitlistPolicy::hash($context))) {
                throw new ConcurrentModificationException(
                    'Registration facts changed before completion. Review again.'
                );
            }
            $enrollment = $this->registration->register(
                $bypass->student,
                $bypass->section,
                true,
                assignment: isset($context['students'][0]['assignment_id'])
                    ? new EntityId($context['students'][0]['assignment_id']) : null,
                budget: $budget,
                chargeQuote: $quote,
                waitlistBypass: $bypass,
            );
            $this->authorize($actor);
            $this->proofs->validate(
                $bypass->token,
                'waitlist',
                'priority_bypass',
                $bypass->section,
                $actor,
                $bypass->operation,
                $bypass->revision(),
            );
            $this->assessments->verifyReviewedSection(
                $bypass->student,
                $bypass->section,
                $context['section'],
                $quote,
                $budget,
            );
            $fresh = $this->ledger->load($bypass->section, $budget);
            $receipt = $fresh->receipt($bypass->operation);
            if (
                $fresh->playhead() !== $root->playhead() + 1 ||
                ($receipt['enrollment_id'] ?? null) !== $enrollment->id->value ||
                $this->people->student($bypass->student)->personId->value !== $person->value
            ) {
                throw new ConcurrentModificationException('The bypass source changed before completion. Review again.');
            }
            $this->authorize($actor);
            return $this->result($bypass, $receipt);
        });
    }

    private function selected(SectionWaitlist $root, EntityId $student, EntityId $person, EntityId $entry): void
    {
        $current = $root->forStudent($student->value);
        if (
            ($current['id'] ?? null) !== $entry->value || ($current['person_id'] ?? null) !== $person->value ||
            ($root->active()[0]['id'] ?? null) === $entry->value
        ) {
            throw new ConcurrentModificationException('Choose a current later waitlist request. Refresh the queue.');
        }
    }

    private function ready(array $context, ?RegistrationChargeQuote $quote): bool
    {
        $student = $context['students'][0];
        return $context['section']['active'] && $context['section']['seats_remaining'] > 0 &&
        $student['enrollment_id'] === null && $student['issues'] === [] && $quote !== null &&
        ($student['charges']['problems'] ?? []) === [];
    }

    private function lock(EntityId $student, EntityId $section): EntityId
    {
        $this->roles->lock();
        $this->records->lockStudent($student);
        $person = $this->people->student($student)->personId;
        $this->records->lockPerson($person);
        $this->records->lockSection($section);
        $this->records->lockCourse($this->records->section($section)->courseId);
        return $person;
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission(WaitlistBypass::DUTY);
        foreach ([WaitlistService::DUTY, 'sis:registration:manage'] as $duty) {
            if ($this->access->requirePermission($duty)->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        }
        if ($expected !== null && $expected->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if (
            $reason === '' || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason)
        ) {
            throw new ValidationException([
                ['field' => 'reason', 'message' => 'Provide a reason of 1–1,000 characters.'],
            ]);
        }
        return $reason;
    }

    private function result(WaitlistBypass $bypass, array $receipt): array
    {
        return ['operation_id' => $bypass->operation->value, 'entry_id' => $bypass->entry->value,
            'enrollment_id' => $receipt['enrollment_id'], 'accepted_position' => $receipt['version']];
    }
}
