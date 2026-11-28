<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\PaymentGatewayFixture;

final class RetainedGatewayPaymentsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/PaymentGatewayFixture.php';
    }

    public function testKeyRotationDisablingAndNewAssetsCannotRedirectAnEarlierSignedReceipt(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout, $confirmation] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $row = $f->campus->payments->find($id);
        self::assertStringNotContainsString('sk_test_', $row['sealed_gateway']);
        self::assertSame('sk_test_fictional', $f->configuration->binding($row)['connection']['secret']);
        $f->save('connection', array_replace($f->input, ['id' => $f->connection->value, 'version' => 1,
            'secret' => 'sk_test_rotated', 'webhook' => 'whsec_rotated',
            'asset_id' => $f->other->value, 'enabled' => false]));
        $f->policy->active = false;
        self::assertFalse($f->gateway->enabled('account', 'USD'));
        [$body, $signature] = $f->notification($id);
        $confirmation->receive($body, $signature);
        $f->campus->worker($f->gateway)->run();
        $events = $f->sis->sql->rows('sis_history_events');
        $confirmation->receive($body, $signature);
        $f->campus->worker($f->gateway)->run();
        self::assertSame($events, $f->sis->sql->rows('sis_history_events'));
        self::assertSame(0, $f->sis->accounts->get(new AccountId($account->value))->balance()->minor);
        $binding = $f->configuration->binding($row);
        self::assertSame($f->bank->value, $binding['connection']['asset']['id']);
        self::assertSame('paid', $f->campus->payments->find($id)['status']);
        $journals = $f->sis->sql->rows('sis_journals');
        self::assertCount(2, $journals);
        $journal = $f->sis->journalRepository()->get(new \Domain\Finance\ValueObject\JournalId($journals[1]['id']));
        $byAccount = [];
        foreach ($journal->lines() as $line) {
            $byAccount[$line->accountId->value] = $line->amount->minor;
        }
        self::assertSame(12345, (int) $byAccount[$f->bank->value]);
        self::assertArrayNotHasKey($f->other->value, $byAccount);
        $history = \EventStoreFixture::history($f->sis->connection->queryBuilder())->page(
            new \Domain\Shared\EventSourcing\StreamReference(new AccountId($account->value), 'student_account')
        )->events;
        $metadata = $history[array_key_last($history)]->event->metadata();
        self::assertSame($f->bank->value, $metadata['gateway_receipt']['asset']['id']);
        self::assertStringNotContainsString('sk_test_', json_encode($metadata));
    }

    public function testWrongSignatureOrConnectionMetadataCannotPostMoney(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout, $confirmation] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $before = $f->sis->sql->rows('sis_history_events');
        foreach (
            [$f->notification($id, 'whsec_wrong'), $f->notification($id, changes: ['metadata' => [
            'sis_payment_id' => $id->value, 'sis_connection_id' => EntityId::generate()->value,
            'sis_connection_version' => '1']])] as [$body, $signature]
        ) {
            try {
                $confirmation->receive($body, $signature);
                $f->campus->worker($f->gateway)->run();
                self::fail('Unauthenticated or redirected confirmation accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, $f->sis->sql->rows('sis_history_events'));
                self::assertSame('pending', $f->campus->payments->find($id)['status']);
            }
        }
    }

    public function testCopyingAnotherPaymentsSealedConfigurationCannotRedirectOwnership(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout, $confirmation] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $f->sis->sql->update('sis_portal_payments', ['owner_user_id' => $f->sis->user()->value], ['id' => $id->value]);
        $this->expectException(\DomainException::class);
        $f->gateway->receipt($f->campus->payments->find($id));
    }

    public function testAuthorizationCapturesOnlyTheBalanceRemainingAfterAChargeCredit(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout, $confirmation] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $root = $f->sis->accounts->get(new AccountId($account->value));
        $charge = $root->entries()[0];
        $root->creditCharge(
            new EntityId($charge['operation_id']),
            EntityId::generate(),
            new \Domain\Finance\ValueObject\Money(1000, 'USD'),
            $f->sis->access->actor,
            hash('sha256', 'fictional-credit')
        );
        $f->sis->saveAccount($root);
        $confirmation->receive(...$f->notification($id));
        $f->campus->worker($f->gateway)->run();
        self::assertSame(0, $f->sis->accounts->get(new AccountId($account->value))->balance()->minor);
        self::assertSame('paid', $f->campus->payments->find($id)['status']);
    }

    public function testNewCheckoutsUseTheReviewedNewAssignmentAndMalformedBindingFailsClosed(): void
    {
        $f = new PaymentGatewayFixture();
        $f->save('connection', array_replace($f->input, ['id' => $f->connection->value, 'version' => 1,
            'asset_id' => $f->other->value, 'secret' => '', 'webhook' => '']));
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $binding = $f->configuration->binding($f->campus->payments->find($id));
        self::assertSame(2, $binding['connection']['version']);
        self::assertSame($f->other->value, $binding['connection']['asset']['id']);
        $f->sis->sql->update('sis_portal_payments', ['sealed_gateway' => 'invalid'], ['id' => $id->value]);
        $this->expectException(\DomainException::class);
        $f->gateway->receipt($f->campus->payments->find($id));
    }
    public function testCurrentRotatedSigningKeyAuthenticatesAnOlderSessionsOriginalAssignment(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout, $confirmation] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $f->save('connection', array_replace($f->input, ['id' => $f->connection->value, 'version' => 1,
            'webhook' => 'whsec_rotated', 'asset_id' => $f->other->value]));
        $confirmation->receive(...$f->notification($id, 'whsec_rotated'));
        $f->campus->worker($f->gateway)->run();
        self::assertSame('paid', $f->campus->payments->find($id)['status']);
        self::assertSame(
            $f->bank->value,
            $f->gateway->receipt($f->campus->payments->find($id))->values()['asset']['id']
        );
    }

    public function testApiCredentialIsRemovedEvenWhenTheWebhookArrivesBeforeTheCheckoutReply(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout, $confirmation] = $f->charged();
        $id = EntityId::generate();
        $f->client->onCheckout = static function () use ($f, $confirmation, $id): void {
            $confirmation->receive(...$f->notification($id));
            $f->campus->worker($f->gateway)->run();
        };
        $checkout->checkout($id, $account);
        $row = $f->campus->payments->find($id);
        self::assertSame('paid', $row['status']);
        self::assertNull($f->configuration->binding($row)['connection']['secret']);
        self::assertCount(2, $f->sis->sql->rows('sis_journals'));
    }

    public function testUncertainCreationRetriesTheOriginalReturnUrlAndApiCredential(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout] = $f->charged();
        $id = EntityId::generate();
        $f->client->onCheckout = static function () use ($f): void {
            $f->client->onCheckout = null;
            throw new \DomainException('Fictional uncertain provider reply.');
        };
        try {
            $checkout->checkout($id, $account);
            self::fail('Fictional provider did not interrupt creation.');
        } catch (\DomainException) {
            self::assertSame(
                'sk_test_fictional',
                $f->configuration->binding($f->campus->payments->find($id))['connection']['secret']
            );
        }
        $f->policy->base = 'https://changed.example.invalid';
        $checkout->checkout($id, $account);
        $calls = array_values(array_filter($f->client->calls, static fn(array $c): bool => $c[0] === 'checkout'));
        self::assertSame('https://campus.example.invalid', $calls[1][2]);
        $f->campus->worker($f->gateway)->run();
        self::assertNull($f->configuration->binding($f->campus->payments->find($id))['connection']['secret']);
    }

    public function testAReversalKeepsTheGatewayClearingCounterpart(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout, $confirmation] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $confirmation->receive(...$f->notification($id));
        $f->campus->worker($f->gateway)->run();
        $root = $f->sis->accounts->get(new AccountId($account->value));
        $f->sis->finance()->reverse(
            new AccountId($account->value),
            $id,
            $root->playhead(),
            'Reverse fictional payment after external reconciliation.'
        );
        $journals = $f->sis->sql->rows('sis_journals');
        $journal = $f->sis->journalRepository()->get(new \Domain\Finance\ValueObject\JournalId(
            $journals[array_key_last($journals)]['id']
        ));
        $lines = [];
        foreach ($journal->lines() as $line) {
            $lines[$line->accountId->value] = $line->amount->minor;
        }
        self::assertSame(-12345, $lines[$f->bank->value]);
        self::assertArrayNotHasKey($f->other->value, $lines);
    }

    public function testProductionCutoverCannotTurnAnEarlierTestSessionIntoRealCredit(): void
    {
        $f = new PaymentGatewayFixture();
        [$account, $checkout, $confirmation] = $f->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $f->policy->test = false;
        $this->expectException(\InvalidArgumentException::class);
        $confirmation->receive(...$f->notification($id));
        $f->campus->worker($f->gateway)->run();
    }

    public function testFeePurposeUsesItsOwnRouteAndRetainsTheProviderReceiptWithoutAStudentJournal(): void
    {
        $f = new PaymentGatewayFixture();
        $s = $f->sis;
        $student = $s->student();
        $fees = $s->fees();
        $fees->savePolicy('transcript', 0, 2500, 'USD', true);
        $data = $fees->prepare($student->personId, 'transcript', ['student_id' => $student->id->value], 'staff');
        $fee = new EntityId($data['fee_id']);
        $f->save('route', ['purpose' => 'application', 'currency' => 'USD', 'version' => 1, 'connection_id' => null]);
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
        $result = $checkout->checkout($fee);
        self::assertFalse($result['cleared']);
        $row = $f->campus->payments->activeFee($fee, $s->clock->now()->getTimestamp());
        self::assertSame('transcript', $row['purpose']);
        self::assertSame('sk_test_fictional', $f->configuration->binding($row)['connection']['secret']);
        $confirmation = new \Domain\Portal\Service\PaymentConfirmationService(
            $f->gateway,
            $f->campus->payments,
            $s->transactions,
            $s->clock,
        );
        [$body, $signature] = $f->notification(new EntityId($row['id']), changes: ['amount_total' => 2500]);
        $confirmation->receive($body, $signature);
        $f->campus->worker($f->gateway)->run();
        $events = $s->sql->rows('sis_history_events');
        $confirmation->receive($body, $signature);
        $f->campus->worker($f->gateway)->run();
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertTrue($fees->show($fee, 'owner')['cleared']);
        self::assertSame([], $s->sql->rows('sis_journals'));
    }
}
