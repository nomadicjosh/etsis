<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\BillingRule;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Service\TermBillingService;
use Domain\Finance\Service\TermFeeBilling;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\TermFeeQuote;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class BillingBatchReviewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function rules(SisFixture $s): TermBillingService
    {
        $db = $s->connection->queryBuilder();
        return new TermBillingService(new EventSourcedBillingRuleRepository(
            $s->sql,
            EventStoreFixture::framework($db),
            $s->transactions,
            EventStoreFixture::financialWriter($db)
        ), $s->access, $s->transactions, $s->audit, $s->financialCommands());
    }

    private function fee(
        SisFixture $s,
        int $minor = 2500,
        string $currency = 'USD',
        ?EntityId $program = null,
    ): BillingRule {
        return $this->rules($s)->define(
            EntityId::generate(),
            $s->term,
            $program,
            'Fictional fee',
            'fee',
            new Money($minor, $currency),
            'Fictional approved fee.'
        );
    }

    private function facts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), ['sis_student_accounts', 'sis_account_balances', 'sis_billing_rules',
            'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_billing_batches', 'sis_audit']);
    }

    public function testReviewIsReadOnlyAndConfirmationKeepsAttributedChargesAndAnEncryptedReceipt(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $this->fee($s);
        $this->fee($s, 500, program: $s->program);
        $before = $this->facts($s);
        $service = $s->billingBatches();
        $review = $service->review($s->term, [$student->id], 'Private fictional batch reason.');
        self::assertSame($before, $this->facts($s));
        self::assertSame(2, $review['new_charges']);
        self::assertSame([['currency' => 'USD', 'minor' => 3000]], $review['totals']);
        self::assertSame(0, $review['students'][0]['balance_before_minor']);
        self::assertSame(3000, $review['students'][0]['balance_after_minor']);
        $s->clock->time = $s->clock->time->modify('+1 minute');
        $result = $service->confirm($review['review_token']);
        self::assertSame(2, $result['posted']);
        self::assertSame(1, $result['accounts_created']);
        $accountId = new AccountId($result['students'][0]['account_id']);
        self::assertSame(3000, $s->accounts->get($accountId)->balance()->minor);
        $events = EventStoreFixture::history($s->connection->queryBuilder())->page(
            new StreamReference($accountId, 'student_account')
        )->events;
        self::assertCount(3, $events);
        foreach ($events as $event) {
            self::assertSame($s->access->actor->value, $event->context->actor->value);
            self::assertSame($result['batch_id'], $event->context->correlation->value);
            self::assertSame($s->term->value, $event->context->causation->value);
            self::assertSame('Private fictional batch reason.', $event->context->reason);
            self::assertSame($s->clock->now()->format('U.u'), $event->context->effectiveAt->format('U.u'));
        }
        $receipt = $s->sql->rows('sis_billing_batches')[0];
        self::assertSame(hash('sha256', $review['review_token']), $receipt['request_hash']);
        self::assertSame($result, json_decode($s->dropCipher->open($receipt['encrypted_result']), true));
        self::assertStringNotContainsString((string) $accountId, json_encode($receipt));
        self::assertStringNotContainsString('Private fictional', json_encode($s->sql->rows('sis_history_events')));
    }

    public function testOriginalConfirmationNeverAppliesLaterFeesAndSurvivesLaterActivityAndExpiry(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $rule = $this->fee($s);
        $service = $s->billingBatches();
        $review = $service->review($s->term, [$student->id], 'Fictional initial batch.');
        $result = $service->confirm($review['review_token']);
        $id = new AccountId($result['students'][0]['account_id']);
        $s->finance()->post(
            $id,
            EntityId::generate(),
            EntryType::Payment,
            new Money(1000, 'USD'),
            $s->accounts->get($id)->playhead(),
            'Fictional later payment.'
        );
        $s->finance()->reverse(
            $id,
            new EntityId((string) $rule->aggregateId()),
            $s->accounts->get($id)->playhead(),
            'Fictional approved reversal.'
        );
        $this->fee($s, 500);
        $this->changeProgram($s, $student->id, $this->otherProgram($s));
        $s->clock->time = $s->clock->time->modify('+1 day');
        $before = $this->facts($s);
        self::assertSame($result, $service->confirm($review['review_token']));
        self::assertSame($before, $this->facts($s));
        $next = $service->review($s->term, [$student->id], 'Fictional next batch.');
        self::assertSame(1, $next['students'][0]['previously_reversed']);
        self::assertSame(1, $next['new_charges']);
        self::assertSame(-1000, $next['students'][0]['balance_before_minor']);
        self::assertSame(-500, $next['students'][0]['balance_after_minor']);
        self::assertSame(1, $service->confirm($next['review_token'])['posted']);
        $next = $service->review($s->term, [$student->id], 'Fictional no-op.');
        self::assertSame(0, $next['new_charges']);
        self::assertSame(1, $next['students'][0]['already_posted']);
        self::assertSame(1, $next['students'][0]['previously_reversed']);
    }

    public static function changedSources(): iterable
    {
        foreach (['payment', 'new_fee', 'retired_fee', 'term_dates', 'primary_program'] as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('changedSources')]
    public function testChangedSourcesRequireAnotherReviewWithoutPartialWrites(string $change): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $rule = $this->fee($s);
        $service = $s->billingBatches();
        $review = $service->review($s->term, [$student->id], 'Fictional stale batch.');
        if ($change === 'payment') {
            $account = $s->finance()->open($student->id, $s->term, 'USD');
            $s->finance()->post(
                $account->aggregateId(),
                EntityId::generate(),
                EntryType::Payment,
                new Money(100, 'USD'),
                $account->playhead(),
                'Fictional payment after review.'
            );
        } elseif ($change === 'new_fee') {
            $this->fee($s, 100);
        } elseif ($change === 'retired_fee') {
            $this->rules($s)->retire($rule->aggregateId(), 1, 'Fictional retirement.');
        } elseif ($change === 'term_dates') {
            $s->sql->update('sis_terms', ['ends_on' => '2026-12-19'], ['id' => $s->term->value]);
        } else {
            $this->changeProgram($s, $student->id, $this->otherProgram($s));
        }
        $before = $this->facts($s);
        try {
            $service->confirm($review['review_token']);
            self::fail('A changed billing review was accepted.');
        } catch (ConcurrentModificationException | \Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    private function otherProgram(SisFixture $s): EntityId
    {
        $id = EntityId::generate();
        $s->sql->insert('sis_programs', ['id' => $id->value, 'code' => 'OTHER', 'title' => 'Other fictional program']);
        return $id;
    }

    private function changeProgram(SisFixture $s, EntityId $student, EntityId $program): void
    {
        $r = $s->admissions->student($student);
        $revision = hash('sha256', json_encode([$r->id->value, $r->personId->value,
            $r->programId->value, $r->entryTermId->value, $r->active], JSON_THROW_ON_ERROR));
        $service = $s->programAssignmentService();
        $review = $service->reviewChange($student, $program, $s->term, $revision, 'Fictional program change.');
        $service->changeProgram($student, $review['review_token']);
    }

    public function testUnrelatedProgramFeesAreNotReplayedOrIncludedInTheBatch(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $this->fee($s);
        $other = $this->otherProgram($s);
        $unrelated = $this->fee($s, 9000, program: $other);
        self::assertCount(1, $s->termFees()->rules(
            $s->term,
            new HistoryReadBudget(maximumPages: 1),
            [$s->program]
        ));
        $service = $s->billingBatches();
        $review = $service->review($s->term, [$student->id], 'Fictional scoped batch.');
        $this->rules($s)->retire($unrelated->aggregateId(), 1, 'Fictional other program retirement.');
        self::assertCount(1, $review['rules']);
        self::assertSame(1, $service->confirm($review['review_token'])['posted']);
    }

    public function testNoFeesNeverCreateEmptyAccountsAndCurrencyTotalsStaySeparate(): void
    {
        $s = new SisFixture();
        $first = $s->student();
        $service = $s->billingBatches();
        $review = $service->review($s->term, [$first->id], 'Fictional no fees.');
        self::assertSame([], $review['totals']);
        self::assertNull($review['students'][0]['currency']);
        self::assertSame(0, $service->confirm($review['review_token'])['accounts_created']);
        self::assertSame([], $s->sql->rows('sis_student_accounts'));
        $second = $s->student();
        $other = $this->otherProgram($s);
        $this->changeProgram($s, $second->id, $other);
        $this->fee($s, 100, 'USD', $s->program);
        $this->fee($s, 200, 'EUR', $other);
        $review = $service->review($s->term, [$second->id, $first->id], 'Fictional two currencies.');
        self::assertSame([
            ['currency' => 'EUR', 'minor' => 200], ['currency' => 'USD', 'minor' => 100],
        ], $review['totals']);
        self::assertSame(0, $review['blocked']);
        self::assertSame(2, $service->confirm($review['review_token'])['accounts_created']);
    }

    public static function billingProblems(): iterable
    {
        yield 'currency' => ['currency'];
        yield 'balance limit' => ['balance'];
    }

    #[DataProvider('billingProblems')]
    public function testAnInvalidAccountBlocksTheEntireBatch(string $problem): void
    {
        $s = new SisFixture();
        $first = $s->student();
        $second = $s->student();
        $this->fee($s, 100);
        $account = $s->finance()->open($second->id, $s->term, $problem === 'currency' ? 'EUR' : 'USD');
        if ($problem === 'balance') {
            $s->finance()->post(
                $account->aggregateId(),
                EntityId::generate(),
                EntryType::Charge,
                new Money(Money::MAX_MINOR, 'USD'),
                $account->playhead(),
                'Fictional balance limit.'
            );
        }
        $service = $s->billingBatches();
        $review = $service->review($s->term, [$first->id, $second->id], 'Fictional blocked batch.');
        self::assertSame(1, $review['blocked']);
        $before = $this->facts($s);
        try {
            $service->confirm($review['review_token']);
            self::fail('A blocked batch partially posted.');
        } catch (ValidationException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public static function interruptions(): iterable
    {
        foreach (['audit', 'permission', 'actor', 'expiry', 'second_account'] as $failure) {
            yield $failure => [$failure];
        }
    }

    #[DataProvider('interruptions')]
    public function testLateFailuresRollBackEventsAccountsBalancesReceiptsAndAudit(string $failure): void
    {
        $s = new SisFixture();
        $students = [$s->student()->id, $s->student()->id];
        $this->fee($s);
        $other = $s->user();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s, $other, $failure): void {
            match ($failure) {
                'audit' => throw new \RuntimeException('Fictional audit failure.'),
                'permission' => [$s->access->staff = false, $s->access->grants = []],
                'actor' => $s->access->actor = $other,
                'expiry' => $s->clock->time = $s->clock->time->modify('+21 minutes'),
                default => null,
            };
        });
        $billing = null;
        if ($failure === 'second_account') {
            $inner = $s->termFees();
            $billing = $this->createStub(TermFeeBilling::class);
            $billing->method('rules')->willReturnCallback($inner->rules(...));
            $billing->method('quote')->willReturnCallback($inner->quote(...));
            $applied = 0;
            $billing->method('apply')->willReturnCallback(static function (
                TermFeeQuote $q,
                EventContext $c
            ) use (
                $inner,
                &$applied,
            ): array {
                $result = $inner->apply($q, $c);
                if (++$applied === 2) {
                    throw new \RuntimeException('Fictional second-account failure.');
                }
                return $result;
            });
        }
        $service = $s->billingBatches(audit: $audit, billing: $billing);
        $review = $service->review($s->term, $students, 'Fictional interrupted batch.');
        $before = $this->facts($s);
        try {
            $service->confirm($review['review_token']);
            self::fail('An interrupted batch committed.');
        } catch (\RuntimeException | AccessDeniedException | ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testReviewsAndAcceptedRetriesRequireAllThreeCurrentPermissionsAndTheOriginalActor(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $this->fee($s);
        $service = $s->billingBatches();
        $review = $service->review($s->term, [$student->id], 'Fictional batch.');
        $service->confirm($review['review_token']);
        $before = $this->facts($s);
        $s->access->staff = false;
        $permissions = ['sis:finance:post', 'sis:finance:read', 'sis:student:read'];
        foreach ($permissions as $missing) {
            $s->access->grants = array_values(array_diff($permissions, [$missing]));
            foreach (
                [fn () => $service->review($s->term, [$student->id], 'Fictional batch.'),
                fn () => $service->confirm($review['review_token'])] as $attempt
            ) {
                try {
                    $attempt();
                    self::fail('A billing decision bypassed a required permission.');
                } catch (AccessDeniedException) {
                    self::assertSame($before, $this->facts($s));
                }
            }
        }
        $s->access->grants = $permissions;
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $service->confirm($review['review_token']);
    }

    public function testInvalidSelectionsReasonsAndProofsNeverWrite(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $service = $s->billingBatches();
        $before = $this->facts($s);
        $attempts = [fn () => $service->review($s->term, [], 'Fictional batch.'),
            fn () => $service->review($s->term, [$student->id, $student->id], 'Fictional batch.'),
            fn () => $service->review($s->term, array_fill(0, 26, $student->id), 'Fictional batch.')];
        foreach (['', str_repeat('x', 2001), "\0", "\xFF"] as $reason) {
            $attempts[] = fn () => $service->review($s->term, [$student->id], $reason);
        }
        $review = $service->review($s->term, [$student->id], 'Fictional batch.');
        $proof = json_decode($s->dropCipher->open($review['review_token']), true);
        $proof['purpose'] = 'another.feature';
        foreach (['', 'tampered', str_repeat('x', 600001), $s->dropCipher->seal(json_encode($proof))] as $token) {
            $attempts[] = fn () => $service->confirm($token);
        }
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('An invalid billing instruction was accepted.');
            } catch (ValidationException) {
                self::assertSame($before, $this->facts($s));
            }
        }
        $s->clock->time = $s->clock->time->modify('-1 minute');
        try {
            $service->confirm($review['review_token']);
            self::fail('A future review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
        $s->clock->time = $s->clock->time->modify('+21 minutes');
        $this->expectException(ConcurrentModificationException::class);
        $service->confirm($review['review_token']);
    }

    public function testRuleAndStudentVerificationConsumeTheSameReplayBudget(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $this->fee($s);
        $billing = $s->termFees();
        $budget = new HistoryReadBudget(maximumPages: 1);
        $rules = $billing->rules($s->term, $budget, [$s->program]);
        self::assertCount(1, $rules);
        $before = $this->facts($s);
        try {
            $billing->quote($student->id, $s->term, $rules, $budget);
            self::fail('Student verification restarted the request replay budget.');
        } catch (HistoryReadLimitException) {
            self::assertSame($before, $this->facts($s));
        }
    }
}
