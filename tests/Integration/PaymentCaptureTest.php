<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\Enum\EntryType;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\PaymentGatewayFixture;

final class PaymentCaptureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/PaymentGatewayFixture.php';
    }

    public function testAuthorizationIsCapturedAndPostedByTheServerOnly(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout, $confirmation] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $confirmation->receive(...$f->notification($id));
        self::assertSame(12345, $f->sis->accounts->get(new AccountId($account->value))->balance()->minor);
        self::assertSame(
            ['examined' => 1, 'done' => 1, 'waiting' => 0, 'review' => 0],
            $f->campus->worker($f->gateway)->run()
        );
        $root = $f->sis->accounts->get(new AccountId($account->value));
        self::assertSame(0, $root->balance()->minor);
        self::assertSame('paid', $root->captures()[$id->value]['state']);
        self::assertSame('paid', $f->campus->payments->find($id)['status']);
        self::assertNull($f->configuration->binding($f->campus->payments->find($id))['connection']['secret']);
        self::assertSame('verified', new \Infrastructure\Projection\SqlAccountProjectionVerifier(
            $f->sis->sql,
            \EventStoreFixture::history($f->sis->connection->queryBuilder())
        )->verify($root->aggregateId())->status);
    }
    public function testBalanceReductionBeforeAuthorizationCausesOnlyAPartialCapture(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $root = $f->sis->accounts->get(new AccountId($account->value));
        $charge = $root->entries()[0]['operation_id'];
        $root->creditCharge(
            new EntityId($charge),
            EntityId::generate(),
            new Money(1000, 'USD'),
            $f->sis->access->actor,
            hash('sha256', 'fictional-price-credit')
        );
        $f->sis->saveAccount($root);
        self::assertSame(1, $f->campus->worker($f->gateway)->run()['done']);
        $root = $f->sis->accounts->get(new AccountId($account->value));
        self::assertSame(11345, $root->captures()[$id->value]['plan']['minor']);
        self::assertSame(11345, $root->captures()[$id->value]['received_minor']);
        self::assertSame(0, $root->balance()->minor);
    }

    public function testUncertainProviderReplyKeepsTheBudgetAndLaterVerificationDoesNotCaptureTwice(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $f->client->onPerform = static function ($binding, $plan) use ($f): void {
            $f->client->states[$plan['payment_id']] = ['state' => 'paid', 'session' => $plan['session'],
                'intent' => $plan['intent'], 'authorized_minor' => $plan['authorized_minor'],
                'currency' => $plan['currency'], 'received_minor' => $plan['minor']];
            throw new \DomainException('Fictional lost provider reply.');
        };
        self::assertSame(1, $f->campus->worker($f->gateway)->run()['waiting']);
        $root = $f->sis->accounts->get(new AccountId($account->value));
        self::assertSame('reserved', $root->captures()[$id->value]['state']);
        self::assertSame('verified', new \Infrastructure\Projection\SqlAccountProjectionVerifier(
            $f->sis->sql,
            \EventStoreFixture::history($f->sis->connection->queryBuilder())
        )->verify($root->aggregateId())->status);
        try {
            $f->sis->finance()->post(
                new AccountId($account->value),
                EntityId::generate(),
                EntryType::Payment,
                new Money(1000, 'USD'),
                $root->playhead(),
                'Fictional other receipt during capture.'
            );
            self::fail('Another payment consumed a reserved capture budget.');
        } catch (\Domain\Payments\Exception\CapturePendingException) {
            self::assertSame(12345, $f->sis->accounts->get(new AccountId($account->value))->balance()->minor);
        }
        $f->sis->clock->time = $f->sis->clock->time->modify('+61 seconds');
        self::assertSame(1, $f->campus->worker($f->gateway)->run()['done']);
        self::assertCount(1, array_filter($f->client->calls, static fn(array $c): bool => $c[0] === 'capture'));
        self::assertSame(0, $f->sis->accounts->get(new AccountId($account->value))->balance()->minor);
    }

    public function testChargeCreditsAndReversalsCannotConsumeAReservedBudget(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $checkout->checkout(EntityId::generate(), $account);
        $f->client->onPerform = static function (): never {
            throw new \DomainException('Fictional transport unavailable.');
        };
        $f->campus->worker($f->gateway)->run();
        foreach (['credit', 'reverse'] as $action) {
            $root = $f->sis->accounts->get(new AccountId($account->value));
            $source = new EntityId($root->entries()[0]['operation_id']);
            try {
                if ($action === 'credit') {
                    $root->creditCharge(
                        $source,
                        EntityId::generate(),
                        new Money(1000, 'USD'),
                        $f->sis->access->actor,
                        hash('sha256', 'fictional-credit')
                    );
                } else {
                    $root->reverse($source, $f->sis->access->actor);
                }
                $f->sis->saveAccount($root);
                self::fail('A balance reduction bypassed the capture budget.');
            } catch (\Domain\Payments\Exception\CapturePendingException) {
                self::assertSame(12345, $f->sis->accounts->get(new AccountId($account->value))->balance()->minor);
            }
        }
    }

    public function testAStaleWriterCannotOmitANewProtectedReservation(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $checkout->checkout(EntityId::generate(), $account);
        $stale = $f->sis->accounts->get(new AccountId($account->value));
        $f->client->onPerform = static function (): never {
            throw new \DomainException('Fictional transport unavailable.');
        };
        $f->campus->worker($f->gateway)->run();
        $stale->post(EntityId::generate(), EntryType::Payment, new Money(1000, 'USD'), $f->sis->access->actor);
        $this->expectException(\Domain\Shared\Exception\ConcurrentModificationException::class);
        $f->sis->saveAccount($stale);
    }

    public function testObservedMoneySurvivesAMissingAccountingPeriodAndPostsAfterItOpens(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $f->client->onPerform = static function () use ($f): void {
            $f->sis->clock->time = $f->sis->clock->time->modify('+1 month');
        };
        self::assertSame(1, $f->campus->worker($f->gateway)->run()['waiting']);
        $root = $f->sis->accounts->get(new AccountId($account->value));
        self::assertSame('received', $root->captures()[$id->value]['state']);
        self::assertSame(12345, $root->captures()[$id->value]['received_minor']);
        self::assertSame(12345, $root->balance()->minor);
        $service = $f->sis->studentLedger();
        $review = $service->reviewPeriod(
            'USD',
            'Fictional September',
            '2026-09-01',
            '2026-09-30',
            'Open the next fictional accounting period.'
        );
        $service->confirm($review['review_token']);
        $f->sis->clock->time = $f->sis->clock->time->modify('+61 seconds');
        self::assertSame(1, $f->campus->worker($f->gateway)->run()['done']);
        self::assertSame(0, $f->sis->accounts->get(new AccountId($account->value))->balance()->minor);
    }

    public function testUnexpectedExternalCaptureIsRetainedForReviewWithoutInventingASisCapture(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $f->client->states[$id->value] = ['state' => 'paid', 'session' => 'cs_test_' . $id->value,
            'intent' => 'pi_test_' . $id->value, 'authorized_minor' => 12345,
            'currency' => 'USD', 'received_minor' => 12345];
        self::assertSame(1, $f->campus->worker($f->gateway)->run()['review']);
        $root = $f->sis->accounts->get(new AccountId($account->value));
        self::assertSame('review', $root->captures()[$id->value]['state']);
        self::assertSame(12345, $root->captures()[$id->value]['received_minor']);
        self::assertSame(12345, $root->balance()->minor);
        self::assertCount(0, array_filter($f->client->calls, static fn(array $c): bool => $c[0] === 'capture'));
    }

    public function testRequiredPostingAuditFailureRetainsObservedMoneyAndRetriesWithoutCollectingAgain(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $journals = $f->sis->sql->rows('sis_journals');
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function ($actor, string $action, $subject) use ($f): void {
            if ($action === 'payment.confirmed') {
                throw new \RuntimeException('Fictional required audit failure.');
            }
            $f->sis->audit->record($actor, $action, $subject);
        });
        self::assertSame(1, $f->campus->worker($f->gateway, $audit)->run()['waiting']);
        $root = $f->sis->accounts->get(new AccountId($account->value));
        self::assertSame('received', $root->captures()[$id->value]['state']);
        self::assertSame(12345, $root->balance()->minor);
        self::assertSame($journals, $f->sis->sql->rows('sis_journals'));
        self::assertSame('pending', $f->campus->payments->find($id)['status']);
        $f->sis->clock->time = $f->sis->clock->time->modify('+61 seconds');
        self::assertSame(1, $f->campus->worker($f->gateway)->run()['done']);
        self::assertCount(1, array_filter($f->client->calls, static fn(array $c): bool => $c[0] === 'capture'));
    }

    public function testADeletedJobCanBeRequeuedWithoutLosingItsBudgetOrChangingTheCommand(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $f->client->onPerform = static function (): never {
            throw new \DomainException('Fictional transport unavailable.');
        };
        $f->campus->worker($f->gateway)->run();
        $plan = $f->sis->accounts->get(new AccountId($account->value))->captures()[$id->value]['plan'];
        $f->sis->sql->execute('DELETE FROM sis_payment_work WHERE id = ?', [$id->value]);
        $f->sis->transactions->run(fn() => $f->campus->payments->wake($id, $f->sis->clock->now()->getTimestamp()));
        $f->client->onPerform = null;
        self::assertSame(1, $f->campus->worker($f->gateway)->run()['done']);
        self::assertSame($plan, $f->sis->accounts->get(new AccountId($account->value))->captures()[$id->value]['plan']);
    }

    public function testLaterExternalCollectionPreservesEachVerifiedReviewObservationWithoutCapturing(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $observation = ['state' => 'review', 'session' => 'cs_test_' . $id->value,
            'intent' => 'pi_test_' . $id->value, 'authorized_minor' => 12345,
            'currency' => 'USD', 'received_minor' => 0];
        foreach ([0, 1000, 12345] as $received) {
            $f->client->states[$id->value] = array_replace($observation, ['received_minor' => $received]);
            $f->sis->transactions->run(fn() => $f->campus->payments->wake(
                $id,
                $f->sis->clock->now()->getTimestamp()
            ));
            self::assertSame(1, $f->campus->worker($f->gateway)->run()['review']);
            $root = $f->sis->accounts->get(new AccountId($account->value));
            self::assertSame($received, $root->captures()[$id->value]['received_minor']);
            self::assertSame(12345, $root->balance()->minor);
        }
        self::assertCount(0, array_filter($f->client->calls, static fn(array $c): bool => $c[0] === 'capture'));
    }

    private function activity(PaymentGatewayFixture $f): \Domain\Payments\Service\CaptureActivity
    {
        $s = $f->sis;
        return new \Domain\Payments\Service\CaptureActivity(
            $f->campus->payments,
            $s->accounts,
            $s->feeLedger(),
            $s->fees(),
            $f->campus->repository,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock,
            $f->gateway
        );
    }

    public function testActivityNeverExposesCredentialsAndStaffRolesDoNotBypassPortalOwnership(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $activity = $this->activity($f);
        $view = $activity->show('account', $account, 'owner');
        self::assertSame('open', $view['items'][0]['state']);
        self::assertStringNotContainsString('sk_test_', json_encode($view));
        self::assertStringNotContainsString('sealed_gateway', json_encode($view));
        self::assertSame(['status' => 'queued'], $activity->retry($id, 'owner'));
        $f->sis->access->actor = $f->sis->user();
        $f->sis->student();
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $activity->show('account', $account, 'owner');
    }

    public function testEarlierPaidReceiptRemainsReadableWithoutInventingCaptureHistory(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $row = $f->campus->payments->find($id);
        $root = $f->sis->accounts->get(new AccountId($account->value));
        $root->post(
            $id,
            EntryType::Payment,
            new Money(12345, 'USD'),
            $f->sis->access->actor,
            gatewayReceipt: $f->gateway->receipt($row)
        );
        $f->sis->saveAccount($root);
        $binding = $f->configuration->binding($row);
        unset($binding['capture']);
        $binding['connection']['secret'] = null;
        $f->sis->sql->update('sis_portal_payments', ['status' => 'paid',
            'sealed_gateway' => $f->configuration->seal($binding)], ['id' => $id->value]);
        $view = $this->activity($f)->show('account', $account, 'owner');
        self::assertSame('paid', $view['items'][0]['state']);
        self::assertNull($view['items'][0]['capture_minor']);
        self::assertNull($view['items'][0]['received_minor']);
        self::assertFalse($view['items'][0]['can_retry']);
        self::assertSame([], $f->sis->accounts->get(new AccountId($account->value))->captures());
    }

    public function testReadDutyCannotRequestStaffFinancialProcessing(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $f->sis->access->staff = false;
        $f->sis->access->grants = ['sis:finance:read'];
        self::assertCount(1, $this->activity($f)->show('account', $account, 'staff')['items']);
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $this->activity($f)->retry($id, 'staff');
    }

    public function testFeeWaiverCannotConsumeAnUncertainCaptureBudget(): void
    {
        $f = new PaymentGatewayFixture();
        $s = $f->sis;
        $student = $s->student();
        $fees = $s->fees();
        $fees->savePolicy('transcript', 0, 2500, 'USD', true);
        $fee = new EntityId($fees->prepare(
            $student->personId,
            'transcript',
            ['student_id' => $student->id->value],
            'staff'
        )['fee_id']);
        $hosted = new \Domain\Portal\Service\HostedCheckout(
            $f->campus->payments,
            $f->gateway,
            $f->campus->cipher,
            $s->transactions,
            $s->clock
        );
        $checkout = new \Domain\ServiceFee\Service\ServiceFeeCheckout(
            $fees,
            new \Infrastructure\Persistence\Repository\SqlServiceFeeRepository($s->sql),
            $f->campus->payments,
            $f->gateway,
            $hosted,
            $s->admissions,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock
        );
        $checkout->checkout($fee);
        $f->client->onPerform = static function (): never {
            throw new \DomainException('Fictional transport unavailable.');
        };
        self::assertSame(1, $f->campus->worker($f->gateway)->run()['waiting']);
        try {
            $review = $fees->review($fee, 'waive', [], 'Fictional waiver while card capture is uncertain.');
            $fees->confirm(
                $fee,
                new EntityId($review['operation_id']),
                $review['action'],
                $review['details'],
                $review['reason'],
                $review['revision'],
                $review['review_token']
            );
            self::fail('A waiver consumed a capture reservation.');
        } catch (\Domain\Payments\Exception\CapturePendingException) {
            self::assertFalse($fees->show($fee, 'staff')['waived']);
        }
        $f->client->onPerform = null;
        $s->clock->time = $s->clock->time->modify('+61 seconds');
        self::assertSame(1, $f->campus->worker($f->gateway)->run()['done']);
        self::assertSame(2500, $fees->show($fee, 'staff')['net_minor']);
    }

    public function testAnExpiredWorkerLeaseCannotDuplicateAnAlreadyCapturedPayment(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $f->client->onPerform = static function ($binding, $plan) use ($f): void {
            $f->client->states[$plan['payment_id']] = ['state' => 'paid', 'session' => $plan['session'],
                'intent' => $plan['intent'], 'authorized_minor' => $plan['authorized_minor'],
                'currency' => $plan['currency'], 'received_minor' => $plan['minor']];
            $f->sis->clock->time = $f->sis->clock->time->modify('+91 seconds');
            $f->campus->worker($f->gateway)->run();
        };
        self::assertSame(1, $f->campus->worker($f->gateway)->run()['done']);
        self::assertSame(0, $f->sis->accounts->get(new AccountId($account->value))->balance()->minor);
        self::assertSame('done', $f->campus->payments->work($id)['state']);
        self::assertCount(2, $f->sis->sql->rows('sis_journals'));
    }
}
