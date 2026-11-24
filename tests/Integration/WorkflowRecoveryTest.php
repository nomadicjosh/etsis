<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\Enum\EntryType;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Registration\Waitlist\WaitlistPolicy;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\WorkflowRecoveryFixture;

/** Real process loss against disposable fictional storage; no application database is used. */
final class WorkflowRecoveryTest extends TestCase
{
    private WorkflowRecoveryFixture $fixture;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('The process-loss checks require POSIX signals.');
        }
        require_once dirname(__DIR__) . '/Support/WorkflowRecoveryFixture.php';
        $this->fixture = new WorkflowRecoveryFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->close();
        }
    }

    private function registration(EntityId $section, EntityId ...$students): array
    {
        $selection = new RegistrationBatchSelection($section, ...$students);
        return ['section' => $section->value, 'students' => array_map(
            static fn (EntityId $id): string => $id->value,
            $selection->students
        ), 'review' => $this->fixture->sis->registrationBatches()->review($selection, true)];
    }

    private function confirmRegistration(array $command): array
    {
        return $this->fixture->sis->registrationBatches()->commit(
            new EntityId($command['review']['operation_id']),
            new RegistrationBatchSelection(
                new EntityId($command['section']),
                ...array_map(static fn (string $id): EntityId => new EntityId($id), $command['students'])
            ),
            $command['review']['revision'],
            $command['review']['review_token'],
            true
        );
    }

    private function confirmDrop(array $command): array
    {
        return $this->fixture->sis->drops->commit(
            new EntityId($command['student']),
            new EntityId($command['section']),
            new EntityId($command['review']['enrollment_id']),
            $command['review']['version'],
            new EntityId($command['review']['operation_id']),
            $command['review']['revision'],
            $command['review']['effective_on']
        );
    }

    private function account(EntityId $student): AccountId
    {
        return new AccountId($this->fixture->sis->sql->one('sis_student_accounts', [
            'student_id' => $student->value, 'term_id' => $this->fixture->sis->term->value,
        ])['id']);
    }

    private function fundedDrop(): array
    {
        $s = $this->fixture->sis;
        $student = $s->student();
        $section = $s->section(1, 1250);
        $s->service->register($student->id, $section->id, true);
        $account = $this->account($student->id);
        $payment = EntityId::generate();
        $s->finance()->post(
            $account,
            $payment,
            EntryType::Payment,
            new Money(1250, 'USD'),
            $s->accounts->get($account)->playhead(),
            'Fictional received payment.'
        );
        $this->confirmDrop(['student' => $student->id->value, 'section' => $section->id->value,
            'review' => $s->drops->review($student->id, $section->id)]);
        return [$account, $payment];
    }

    public function testInterruptedWaitlistRegistrationPreservesPriorityAndDeletedUpdatesStayDeletedAfterRetry(): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        $section = $s->section(1, 1250);
        $filler = $s->student($s->user());
        $student = $s->student();
        $s->service->register($filler->id, $section->id, true);
        $waitlists = $s->waitlists();
        $policy = $waitlists->reviewPolicy(
            $section->id,
            new WaitlistPolicy(true, 10)->describe(),
            0,
            'Fictional waitlist policy.'
        );
        $waitlists->confirmPolicy(
            $section->id,
            new EntityId($policy['operation_id']),
            $policy['policy'],
            $policy['version'],
            $policy['reason'],
            $policy['review_token']
        );
        $join = $waitlists->review($student->id, $section->id, 'requested', true, null);
        $waitlists->confirm(
            $student->id,
            $section->id,
            new EntityId($join['operation_id']),
            $join['action'],
            true,
            $join['revision'],
            $join['review_token'],
            $join['reason']
        );
        $this->confirmDrop(['student' => $filler->id->value, 'section' => $section->id->value,
            'review' => $s->drops->review($filler->id, $section->id)]);
        $command = $this->registration($section->id, $student->id);
        $before = $r->facts();
        $worker = $r->start('confirm_registration', $command, 'registration.batch_completed');
        $r->send($worker, 'go');
        $r->await($worker, 'staged');
        self::assertSame($before, $r->facts());
        $r->kill($worker);
        self::assertSame($before, $r->facts());
        self::assertSame(1, $waitlists->view($student->id, $section->id, true)['rank']);
        self::assertSame([], $s->sql->rows('sis_registration_updates'));
        $receipt = $this->confirmRegistration($command);
        self::assertCount(1, $s->sql->rows('sis_registration_updates'));
        $actor = $s->access->actor;
        $s->access->actor = $s->admissions->person($student->personId)->ownerUserId;
        $updates = new \Domain\Registration\Service\RegistrationUpdatesService(
            new \Infrastructure\Persistence\Repository\SqlRegistrationUpdatesRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository(
                $s->sql,
                $s->admissions,
                new \Infrastructure\Identity\RandomInstitutionalIdGenerator()
            ),
            $s->admissions,
            $s->waitlistLedger(),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock
        );
        $notice = $updates->page()['items'][0];
        $updates->delete(new EntityId($notice['id']), $notice['revision']);
        self::assertSame([], $s->sql->rows('sis_registration_updates'));
        $s->access->actor = $actor;
        $afterDeletion = $r->facts();
        self::assertSame($receipt, $this->confirmRegistration($command));
        self::assertSame($afterDeletion, $r->facts());
        self::assertSame([], $s->sql->rows('sis_registration_updates'));
    }

    public function testCompetingRefundSurvivesTheOtherProcessLossWithoutSpendingThePaymentTwice(): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        [$account, $payment] = $this->fundedDrop();
        $reviews = [];
        foreach (['first', 'second'] as $label) {
            $reviews[] = $r->refunds()->reviewRefund(
                $account,
                $payment,
                new Money(1250, 'USD'),
                $s->accounts->get($account)->playhead(),
                'Fictional ' . $label . ' refund.'
            );
        }
        $command = static fn (array $review): array => [
            'account' => (string) $account, 'payment' => $payment->value, 'token' => $review['review_token'],
        ];
        $before = $r->facts();
        $first = $r->start('refund', $command($reviews[0]), 'account.payment_refunded');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        self::assertSame($before, $r->facts());
        $second = $r->start('refund', $command($reviews[1]));
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->kill($first);
        $result = $r->result($second);
        self::assertSame('accepted', $result['state']);
        self::assertSame(0, $s->accounts->get($account)->balance()->minor);
        self::assertSame(0, $s->accounts->get($account)->paymentRefundSummary($payment)['remaining_refundable_minor']);
        $accepted = $r->facts();
        try {
            $r->refunds()->commitRefund($account, $payment, $reviews[0]['review_token']);
            self::fail('An interrupted competing refund spent an already refunded payment.');
        } catch (ConcurrentModificationException | DomainException) {
            self::assertSame($accepted, $r->facts());
        }
        self::assertSame(
            $result['result'],
            $r->refunds()->commitRefund($account, $payment, $reviews[1]['review_token'])
        );
        self::assertSame($accepted, $r->facts());
    }

    public function testTerminatedFinalSeatRegistrationRollsBackBeforeTheCompetingStudentIsAccepted(): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        $one = $s->student();
        $two = $s->student();
        $section = $s->section(1, 1250);
        $publication = $r->publishOffering($section);
        $command = $this->registration($section->id, $one->id);
        $before = $r->facts();
        $first = $r->start('confirm_registration', $command, 'registration.batch_completed');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        self::assertSame($before, $r->facts(), 'An uncommitted registration became visible to another connection.');
        $second = $r->start('register', ['student' => $two->id->value, 'section' => $section->id->value]);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->kill($first);
        self::assertSame('accepted', $r->result($second)['state']);
        self::assertSame(1, $s->registration->sectionCount($section->id));
        self::assertNull($s->registration->enrollment($one->id, $section->id));
        self::assertNotNull($s->registration->enrollment($two->id, $section->id));
        self::assertCount(1, $s->sql->rows('sis_academic_credits'));
        self::assertCount(1, $s->sql->rows('sis_student_accounts'));
        self::assertSame($publication['course'], $s->sql->rows('sis_credit_course_versions')[0]['course_version_id']);
        self::assertSame(
            $publication['section'],
            $s->sql->rows('sis_enrollment_section_versions')[0]['publication_id']
        );
        self::assertSame(1250, $s->accounts->get($this->account($two->id))->balance()->minor);
        $after = $r->facts();
        try {
            $this->confirmRegistration($command);
            self::fail('A killed pending review displaced the accepted final-seat owner.');
        } catch (ConcurrentModificationException | DomainException) {
            self::assertSame($after, $r->facts());
        }
    }

    public function testProcessLossRollsBackAFullTwentyFiveStudentBatchAndItsReceiptBeforeRetry(): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        $students = [];
        for ($index = 0; $index < RegistrationBatchSelection::LIMIT; $index++) {
            $students[] = $s->student()->id;
        }
        $section = $s->section(RegistrationBatchSelection::LIMIT, 1250);
        $r->publishOffering($section);
        $command = $this->registration($section->id, ...$students);
        $before = $r->facts();
        $worker = $r->start('confirm_registration', $command, 'registration.batch_completed');
        $r->send($worker, 'go');
        $r->await($worker, 'staged');
        self::assertSame($before, $r->facts());
        $r->kill($worker);
        self::assertSame($before, $r->facts(), 'Process loss left a partial batch or accepted receipt.');
        $result = $this->confirmRegistration($command);
        self::assertSame(RegistrationBatchSelection::LIMIT, $s->registration->sectionCount($section->id));
        foreach (
            ['sis_academic_credits', 'sis_student_accounts', 'sis_credit_course_versions',
            'sis_enrollment_section_versions'] as $table
        ) {
            self::assertCount(RegistrationBatchSelection::LIMIT, $s->sql->rows($table));
        }
        foreach ($students as $student) {
            self::assertSame(1250, $s->accounts->get($this->account($student))->balance()->minor);
        }
        $accepted = $r->facts();
        self::assertSame($result, $this->confirmRegistration($command));
        self::assertSame($accepted, $r->facts());
    }

    public function testLostRegistrationReplyCannotReplaceLaterDropOrNewRegistration(): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        $student = $s->student();
        $section = $s->section(1, 1250);
        $command = $this->registration($section->id, $student->id);
        $worker = $r->start('confirm_registration', $command, loseReply: true);
        $r->send($worker, 'go');
        $r->await($worker, 'committed');
        $r->kill($worker);
        $original = $s->registration->enrollment($student->id, $section->id);
        self::assertNotNull($original);
        $accepted = $r->facts();
        $receipt = $this->confirmRegistration($command);
        self::assertSame($accepted, $r->facts());
        $drop = ['student' => $student->id->value, 'section' => $section->id->value,
            'review' => $s->drops->review($student->id, $section->id)];
        $this->confirmDrop($drop);
        $removed = $r->facts();
        self::assertSame($receipt, $this->confirmRegistration($command));
        self::assertSame($removed, $r->facts());
        self::assertNull($s->registration->enrollment($student->id, $section->id));
        $new = $this->registration($section->id, $student->id);
        $this->confirmRegistration($new);
        $later = $s->registration->enrollment($student->id, $section->id);
        self::assertNotSame($original->id->value, $later->id->value);
        $beforeRetry = $r->facts();
        self::assertSame($receipt, $this->confirmRegistration($command));
        self::assertSame($beforeRetry, $r->facts());
        self::assertSame($later->id->value, $s->registration->enrollment($student->id, $section->id)->id->value);
        self::assertSame(1250, $s->accounts->get($this->account($student->id))->balance()->minor);
    }

    public function testTerminatedDropDoesNotReleaseItsSeatOrChargeBeforeACompetingRequest(): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        $one = $s->student();
        $two = $s->student();
        $section = $s->section(1, 1250);
        $s->service->register($one->id, $section->id, true);
        $command = ['student' => $one->id->value, 'section' => $section->id->value,
            'review' => $s->drops->review($one->id, $section->id)];
        $before = $r->facts();
        $first = $r->start('drop', $command, 'registration.removed');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        self::assertSame($before, $r->facts());
        $second = $r->start('register', ['student' => $two->id->value, 'section' => $section->id->value]);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->kill($first);
        self::assertSame('rejected', $r->result($second)['state']);
        self::assertSame($before, $r->facts());
        self::assertSame(1, $s->registration->sectionCount($section->id));
        self::assertSame(1250, $s->accounts->get($this->account($one->id))->balance()->minor);
        $receipt = $this->confirmDrop($command);
        self::assertSame(0, $s->registration->sectionCount($section->id));
        self::assertSame(0, $s->accounts->get($this->account($one->id))->balance()->minor);
        $s->service->register($two->id, $section->id, true);
        $after = $r->facts();
        self::assertSame($receipt, $this->confirmDrop($command));
        self::assertSame($after, $r->facts());
        self::assertNotNull($s->registration->enrollment($two->id, $section->id));
    }

    public static function refundPhases(): array
    {
        return ['before_commit' => [false], 'after_commit_before_reply' => [true]];
    }

    #[DataProvider('refundPhases')]
    public function testPaymentDropAndRefundRecoveryPreservesSourcePaymentAllowanceAndLaterPostings(
        bool $committed
    ): void {
        $r = $this->fixture;
        $s = $r->sis;
        [$account, $payment] = $this->fundedDrop();
        self::assertSame(-1250, $s->accounts->get($account)->balance()->minor);
        $review = $r->refunds()->reviewRefund(
            $account,
            $payment,
            new Money(1250, 'USD'),
            $s->accounts->get($account)->playhead(),
            'Fictional issued refund.'
        );
        $competing = $r->refunds()->reviewRefund(
            $account,
            $payment,
            new Money(1250, 'USD'),
            $s->accounts->get($account)->playhead(),
            'Fictional competing refund.'
        );
        $command = ['account' => (string) $account, 'payment' => $payment->value, 'token' => $review['review_token']];
        $before = $r->facts();
        $worker = $r->start('refund', $command, $committed ? null : 'account.payment_refunded', $committed);
        $r->send($worker, 'go');
        $r->await($worker, $committed ? 'committed' : 'staged');
        $r->kill($worker);
        if (!$committed) {
            self::assertSame($before, $r->facts());
            self::assertSame(
                1250,
                $s->accounts->get($account)->paymentRefundSummary($payment)['remaining_refundable_minor']
            );
        }
        $receipt = $r->refunds()->commitRefund($account, $payment, $review['review_token']);
        self::assertSame(0, $s->accounts->get($account)->balance()->minor);
        self::assertSame(0, $s->accounts->get($account)->paymentRefundSummary($payment)['remaining_refundable_minor']);
        $after = $r->facts();
        try {
            $r->refunds()->commitRefund($account, $payment, $competing['review_token']);
            self::fail('A stale competing refund reused the same source payment.');
        } catch (ConcurrentModificationException | ValidationException | DomainException) {
            self::assertSame($after, $r->facts());
        }
        $s->finance()->post(
            $account,
            EntityId::generate(),
            EntryType::Charge,
            new Money(100, 'USD'),
            $s->accounts->get($account)->playhead(),
            'Fictional later charge.'
        );
        $later = $r->facts();
        self::assertSame($receipt, $r->refunds()->commitRefund($account, $payment, $review['review_token']));
        self::assertSame($later, $r->facts());
        self::assertSame(100, $s->accounts->get($account)->balance()->minor);
        self::assertCount(1, array_filter(
            $s->accounts->get($account)->entries(),
            static fn (array $entry): bool => $entry['type'] === 'refund'
        ));
        self::assertCount(0, $s->sql->rows('sis_enrollments'));
        self::assertCount(0, $s->sql->rows('sis_academic_credits'));
    }
}
