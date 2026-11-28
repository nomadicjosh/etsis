<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\ValueObject\JournalId;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\PaymentGatewayFixture;

final class ServiceFeeAccountingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/PaymentGatewayFixture.php';
    }

    private function configured(string $recognition = 'acceptance'): array
    {
        $g = new PaymentGatewayFixture();
        $liability = $g->ledger->addAccount('UNEARN', 'Fictional unearned fees', 'USD', 'liability');
        $expense = $g->ledger->addAccount('COST', 'Fictional processing cost', 'USD', 'expense');
        $input = ['cash' => $g->bank->value, 'liability' => $liability->value, 'expense' => $expense->value,
            'application' => $g->income->value, 'transcript' => $g->income->value, 'recognition' => $recognition];
        $r = $g->sis->studentLedger()->reviewServiceFees('USD', $input, 'Approve fictional fee recognition.');
        $g->sis->studentLedger()->confirm($r['review_token']);
        return [$g, $liability, $expense, $input];
    }

    private function assess(PaymentGatewayFixture $g): array
    {
        $s = $g->sis->student();
        $g->sis->fees()->savePolicy('transcript', 0, 2500, 'USD', true);
        $fee = $g->sis->fees()->prepare($s->personId, 'transcript', ['student_id' => $s->id->value], 'staff');
        return [$s, new EntityId($fee['fee_id'])];
    }

    private function confirm(PaymentGatewayFixture $g, array $r): array
    {
        return $g->sis->fees()->confirm(
            new EntityId($r['fee_id']),
            new EntityId($r['operation_id']),
            $r['action'],
            $r['details'],
            $r['reason'],
            $r['revision'],
            $r['review_token']
        );
    }

    private function pay(PaymentGatewayFixture $g, EntityId $fee, int $minor = 2500): array
    {
        $r = $g->sis->fees()->review($fee, 'payment', ['minor' => $minor, 'method' => 'cash',
            'reference' => 'Fictional receipt'], 'Fictional received fee.');
        $this->confirm($g, $r);
        return $r;
    }

    private function last(PaymentGatewayFixture $g): array
    {
        $rows = $g->sis->sql->rows('sis_journals');
        $journal = $g->sis->journalRepository()->get(new JournalId($rows[array_key_last($rows)]['id']));
        $facts = [];
        foreach ($journal->lines() as $line) {
            $facts[$line->accountId->value] = $line->amount->minor;
        }
        ksort($facts);
        return $facts;
    }

    private function expected(array $facts): array
    {
        ksort($facts);
        return $facts;
    }

    public function testDeferredReceiptAcceptanceAndRefundAreBalancedAndPeriodVerified(): void
    {
        [$g, $liability] = $this->configured();
        [$s, $fee] = $this->assess($g);
        self::assertSame([], $g->sis->sql->rows('sis_journals'));
        $payment = $this->pay($g, $fee);
        self::assertSame($this->expected([$g->bank->value => 2500, $liability->value => -2500]), $this->last($g));
        $g->campus->service->requestTranscript($fee, $s->id, 'pickup', '');
        self::assertSame($this->expected([$liability->value => 2500, $g->income->value => -2500]), $this->last($g));
        $refund = $g->sis->fees()->review($fee, 'refund', ['payment_id' => $payment['operation_id'],
            'minor' => 1000, 'reference' => 'Fictional completed refund'], 'Fictional refunded fee.');
        $this->confirm($g, $refund);
        self::assertSame($this->expected([$g->income->value => 1000, $g->bank->value => -1000]), $this->last($g));
        $period = $g->sis->studentLedger()->overview('USD')['periods'][0];
        $scan = $g->sis->studentLedger()->reconcile(new EntityId($period['id']));
        self::assertTrue($scan['done']);
        self::assertSame(3, $scan['checked']);
        self::assertSame('0', $scan['ar_net_minor']);
    }

    public function testReceiptRecognitionKeepsExcessAsLiabilityAndRefundsTheProperSide(): void
    {
        [$g, $liability] = $this->configured('receipt');
        [, $fee] = $this->assess($g);
        $payment = $this->pay($g, $fee, 3000);
        self::assertSame($this->expected([$g->bank->value => 3000, $liability->value => -500,
            $g->income->value => -2500]), $this->last($g));
        $refund = $g->sis->fees()->review($fee, 'refund', ['payment_id' => $payment['operation_id'],
            'minor' => 1000, 'reference' => 'Fictional completed refund'], 'Fictional refunded excess and fee.');
        $this->confirm($g, $refund);
        self::assertSame($this->expected([$g->bank->value => -1000, $liability->value => 500,
            $g->income->value => 500]), $this->last($g));
    }

    public function testMappedProcessingExpenseIsReviewedIdempotentAndDoesNotTouchStudentMoney(): void
    {
        [$g, , $expense] = $this->configured();
        $service = $g->sis->studentLedger();
        $r = $service->reviewExpense(
            'USD',
            150,
            $g->bank,
            'COST.FICTIONAL',
            '2026-08-10',
            'Record the actual fictional processor cost.'
        );
        self::assertSame([], $g->sis->sql->rows('sis_journals'));
        $result = $service->confirm($r['review_token']);
        self::assertSame($this->expected([$expense->value => 150, $g->bank->value => -150]), $this->last($g));
        $g->sis->clock->time = $g->sis->clock->time->modify('+21 minutes');
        self::assertSame($result, $service->confirm($r['review_token']));
        self::assertCount(1, $g->sis->sql->rows('sis_journals'));
        self::assertSame([], $g->sis->sql->rows('sis_service_fees'));
        self::assertSame([], $g->sis->sql->rows('sis_account_entries'));
    }

    public function testPartialWaiverRecognizesOnlyActualMoneyWhenTheRequestIsAccepted(): void
    {
        [$g, $liability] = $this->configured();
        [$s, $fee] = $this->assess($g);
        $this->pay($g, $fee, 1000);
        $this->confirm($g, $g->sis->fees()->review($fee, 'waive', [], 'Fictional partial hardship waiver.'));
        $g->campus->service->requestTranscript($fee, $s->id, 'pickup', '');
        self::assertSame($this->expected([$liability->value => 1000, $g->income->value => -1000]), $this->last($g));
        self::assertCount(2, $g->sis->sql->rows('sis_journals'));
    }

    public function testLaterMappingChangesCannotReassignAnExistingQuoteAndStudentMappingKeepsFeeSetup(): void
    {
        [$g, $liability, , $input] = $this->configured();
        [, $fee] = $this->assess($g);
        $input['cash'] = $g->other->value;
        $input['recognition'] = 'receipt';
        $service = $g->sis->studentLedger();
        $changed = $service->reviewServiceFees('USD', $input, 'Change future fictional fee setup.');
        $service->confirm($changed['review_token']);
        $this->pay($g, $fee);
        self::assertSame($this->expected([$g->bank->value => 2500, $liability->value => -2500]), $this->last($g));
        $book = $service->overview('USD')['book']['definition'];
        $r = $service->reviewMapping(
            'USD',
            'America/Los_Angeles',
            $book['receivable']['code'],
            $book['receivable']['title'],
            $g->income,
            $g->bank,
            $g->bank,
            [],
            [],
            'Keep future fictional mapping.'
        );
        $service->confirm($r['review_token']);
        self::assertSame($book['service_fees'], $service->overview('USD')['book']['definition']['service_fees']);
    }

    public function testEarlierUnmappedQuotesDoNotAcquireGuessedAccounting(): void
    {
        $g = new PaymentGatewayFixture();
        [, $fee] = $this->assess($g);
        $before = $g->sis->feeLedger()->load($fee)->state()['assessment'];
        $liability = $g->ledger->addAccount('UNEARN', 'Fictional unearned fees', 'USD', 'liability');
        $expense = $g->ledger->addAccount('COST', 'Fictional processing cost', 'USD', 'expense');
        $r = $g->sis->studentLedger()->reviewServiceFees(
            'USD',
            ['cash' => $g->bank->value,
            'liability' => $liability->value, 'expense' => $expense->value, 'application' => $g->income->value,
            'transcript' => $g->income->value, 'recognition' => 'acceptance'],
            'Activate future fictional fee journals.'
        );
        $g->sis->studentLedger()->confirm($r['review_token']);
        $this->pay($g, $fee);
        self::assertSame([], $g->sis->sql->rows('sis_journals'));
        self::assertSame($before, $g->sis->feeLedger()->load($fee)->state()['assessment']);
    }

    public function testOnlineReceiptUsesItsFrozenGatewayClearingAsset(): void
    {
        [$g, $liability] = $this->configured();
        [, $fee] = $this->assess($g);
        $s = $g->sis;
        $checkout = new \Domain\ServiceFee\Service\ServiceFeeCheckout(
            $s->fees(),
            new \Infrastructure\Persistence\Repository\SqlServiceFeeRepository($s->sql),
            $g->campus->payments,
            $g->gateway,
            new \Domain\Portal\Service\HostedCheckout(
                $g->campus->payments,
                $g->gateway,
                $g->campus->cipher,
                $s->transactions,
                $s->clock
            ),
            $s->admissions,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock
        );
        $checkout->checkout($fee);
        $g->save('connection', array_replace($g->input, ['id' => $g->connection->value, 'version' => 1,
            'asset_id' => $g->other->value, 'secret' => '', 'webhook' => '']));
        self::assertSame(1, $g->campus->worker($g->gateway)->run()['done']);
        self::assertSame($this->expected([$g->bank->value => 2500, $liability->value => -2500]), $this->last($g));
        self::assertSame(2500, $s->fees()->show($fee, 'owner')['net_minor']);
    }

    public function testExpiredOrStaleExpenseReviewsCannotPostAndRequiredAuditFailureRollsBack(): void
    {
        [$g] = $this->configured();
        $service = $g->sis->studentLedger();
        $r = $service->reviewExpense('USD', 150, $g->bank, 'COST.FICTIONAL', '2026-08-10', 'Actual fictional cost.');
        $g->sis->clock->time = $g->sis->clock->time->modify('+20 minutes');
        try {
            $service->confirm($r['review_token']);
            self::fail('An expired expense posted.');
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame([], $g->sis->sql->rows('sis_journals'));
        }
        $r = $service->reviewExpense('USD', 150, $g->bank, 'COST.FICTIONAL', '2026-08-10', 'Actual fictional cost.');
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Fictional required audit failure.'));
        try {
            $g->sis->studentLedger($audit)->confirm($r['review_token']);
            self::fail('An unaudited expense posted.');
        } catch (\RuntimeException) {
            self::assertSame([], $g->sis->sql->rows('sis_journals'));
        }
        self::assertSame('expense', $service->confirm($r['review_token'])['action']);
    }

    public function testWrongClassificationAndReceivableControlCannotBeFeeOrExpenseAccounts(): void
    {
        [$g, , , $input] = $this->configured();
        $input['expense'] = $g->income->value;
        try {
            $g->sis->studentLedger()->reviewServiceFees('USD', $input, 'Fictional wrong expense classification.');
            self::fail('An income account was used as an expense.');
        } catch (\DomainException) {
            self::assertCount(0, $g->sis->sql->rows('sis_journals'));
        }
        $ar = $g->sis->studentLedger()->overview('USD')['book']['definition']['receivable']['id'];
        $this->expectException(\DomainException::class);
        $g->sis->studentLedger()->reviewExpense(
            'USD',
            150,
            new EntityId($ar),
            'COST.FICTIONAL',
            '2026-08-10',
            'Fictional expense cannot consume student receivables.'
        );
    }

    public function testProspectApplicationAcceptanceRecognizesIncomeWithoutAStudentAccount(): void
    {
        [$g, $liability] = $this->configured();
        $f = $g->sis;
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Prospect')->id;
        $forms = new \Domain\Admissions\Service\ApplicationFormService(
            new \Infrastructure\Persistence\Repository\SqlApplicationFormRepository($f->sql),
            $f->admissionsService(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->submissionService()
        );
        $form = $forms->save(null, $f->program, 'Fictional fee form', [
            ['key' => 'statement', 'label' => 'Fictional statement', 'type' => 'textarea', 'required' => true],
        ], null);
        $forms->publish($form->id, 0);
        $f->fees()->savePolicy('application', 0, 2500, 'USD', true);
        $quote = $f->fees()->prepare($person, 'application', ['program_id' => $f->program->value,
            'term_id' => $f->term->value], 'owner');
        $fee = new EntityId($quote['fee_id']);
        $this->pay($g, $fee);
        $app = $forms->submit($fee, $form->id, $person, $f->term, ['statement' => 'Fictional application answer.']);
        self::assertSame($this->expected([$liability->value => 2500, $g->income->value => -2500]), $this->last($g));
        self::assertSame($app->value, $f->fees()->show($fee, 'owner')['applied_to']);
        self::assertSame([], $f->sql->rows('sis_student_accounts'));
        self::assertSame([], $f->sql->rows('sis_students'));
    }

    public function testFeeConfigurationDutyIsRecheckedAtConfirmationAndAcceptedRetry(): void
    {
        [$g, , , $input] = $this->configured();
        $service = $g->sis->studentLedger();
        $r = $service->reviewServiceFees('USD', $input, 'Fictional reviewed fee setup.');
        $service->confirm($r['review_token']);
        $g->sis->access->staff = false;
        $g->sis->access->grants = ['sis:finance:ledger'];
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $service->confirm($r['review_token']);
    }

    public function testCapturedFeeWaitsForAnAccountingPeriodWithoutLosingMoneyOrCollectingAgain(): void
    {
        [$g] = $this->configured();
        [, $fee] = $this->assess($g);
        $s = $g->sis;
        $checkout = new \Domain\ServiceFee\Service\ServiceFeeCheckout(
            $s->fees(),
            new \Infrastructure\Persistence\Repository\SqlServiceFeeRepository($s->sql),
            $g->campus->payments,
            $g->gateway,
            new \Domain\Portal\Service\HostedCheckout(
                $g->campus->payments,
                $g->gateway,
                $g->campus->cipher,
                $s->transactions,
                $s->clock
            ),
            $s->admissions,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock
        );
        $checkout->checkout($fee);
        $g->client->onPerform = static function () use ($s): void {
            $s->clock->time = $s->clock->time->modify('+1 month');
        };
        self::assertSame(1, $g->campus->worker($g->gateway)->run()['waiting']);
        $state = $s->feeLedger()->load($fee)->state();
        self::assertSame(0, $state['net_minor']);
        self::assertSame('received', array_values($state['captures'])[0]['state']);
        self::assertSame([], $s->sql->rows('sis_journals'));
        $r = $s->studentLedger()->reviewPeriod(
            'USD',
            'Fictional September',
            '2026-09-01',
            '2026-09-30',
            'Open the next fictional fee accounting period.'
        );
        $s->studentLedger()->confirm($r['review_token']);
        $s->clock->time = $s->clock->time->modify('+61 seconds');
        self::assertSame(1, $g->campus->worker($g->gateway)->run()['done']);
        self::assertSame(2500, $s->fees()->show($fee, 'owner')['net_minor']);
        self::assertCount(1, array_filter($g->client->calls, static fn(array $c): bool => $c[0] === 'capture'));
    }
}
