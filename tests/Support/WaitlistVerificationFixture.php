<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Registration\Waitlist\WaitlistPolicy;
use Domain\Registration\Waitlist\WaitlistRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Waitlist\SqlWaitlistRepository;
use Infrastructure\Projection\SqlWaitlistProjectionVerifier;

/** Genuine fictional policy/request decisions; no institution records or invented historical events. */
final class WaitlistVerificationFixture
{
    public readonly \SisFixture $sis;
    public readonly EntityId $section;
    public readonly EntityId $student;
    public readonly EntityId $entry;
    private EntityId $filler;

    public function __construct(?\SisFixture $sis = null)
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->sis = $sis ?? new \SisFixture();
        $s = $this->sis;
        $this->section = $s->section(1, 1250)->id;
        $this->filler = $s->student($s->user())->id;
        $s->service->register($this->filler, $this->section, true);
        $service = $s->waitlists();
        $review = $service->reviewPolicy(
            $this->section,
            new WaitlistPolicy(true, 50)->describe(),
            0,
            'Fictional verification policy.'
        );
        $service->confirmPolicy(
            $this->section,
            new EntityId($review['operation_id']),
            $review['policy'],
            $review['version'],
            $review['reason'],
            $review['review_token']
        );
        $this->student = $s->student($s->user())->id;
        $review = $service->review($this->student, $this->section, 'requested', true, null);
        $accepted = $service->confirm(
            $this->student,
            $this->section,
            new EntityId($review['operation_id']),
            $review['action'],
            true,
            $review['revision'],
            $review['review_token'],
            $review['reason']
        );
        $this->entry = new EntityId($accepted['entry_id']);
    }

    public function close(string $action): void
    {
        $s = $this->sis;
        if ($action === 'registered') {
            $review = $s->drops->review($this->filler, $this->section);
            $s->drops->commit(
                $this->filler,
                $this->section,
                new EntityId($review['enrollment_id']),
                $review['version'],
                new EntityId($review['operation_id']),
                $review['revision'],
                $review['effective_on']
            );
            $s->service->register($this->student, $this->section, true);
            return;
        }
        $service = $s->waitlists();
        $review = $service->review(
            $this->student,
            $this->section,
            $action,
            true,
            $this->entry,
            $action === 'removed' ? 'Fictional removal reason.' : null
        );
        $service->confirm(
            $this->student,
            $this->section,
            new EntityId($review['operation_id']),
            $review['action'],
            true,
            $review['revision'],
            $review['review_token'],
            $review['reason']
        );
    }

    public function records(): SqlWaitlistRepository
    {
        return new SqlWaitlistRepository($this->sis->sql, $this->sis->dropCipher);
    }

    public function verifier(?WaitlistRepository $records = null): SqlWaitlistProjectionVerifier
    {
        return new SqlWaitlistProjectionVerifier(
            $this->sis->sql,
            $records ?? $this->records(),
            $this->sis->waitlistLedger()
        );
    }

    public function facts(): array
    {
        return ['history' => \EventStoreFixture::historyDigest($this->sis->sql)] + array_combine(
            $tables = ['sis_waitlist_state', 'sis_waitlist_entries', 'sis_registration_updates', 'sis_enrollments',
                'sis_academic_credits', 'sis_student_accounts', 'sis_account_entries', 'sis_account_balances',
                'sis_persons', 'sis_students'],
            array_map($this->sis->sql->rows(...), $tables),
        );
    }
}
