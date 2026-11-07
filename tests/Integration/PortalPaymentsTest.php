<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CampusFixture.php';

use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Payments\StripePaymentGateway;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class PortalPaymentsTest extends TestCase
{
    private function charged(): array
    {
        $c = new CampusFixture();
        $student = $c->sis->student();
        $c->sis->service->register($student->id, $c->sis->section(fee: 12345)->id);
        return [$c, new EntityId($c->service->me()['accounts'][0]['id'])];
    }

    public function testCheckoutUsesServerBalanceAndReusesOneEncryptedSessionAcrossRetriesAndTabs(): void
    {
        [$c, $account] = $this->charged();
        $id = EntityId::generate();
        $url = $c->checkout->checkout($id, $account);
        self::assertSame($url, $c->checkout->checkout($id, $account));
        self::assertSame($url, $c->checkout->checkout(EntityId::generate(), $account));
        self::assertCount(1, $c->gateway->calls);
        self::assertSame(12345, $c->gateway->calls[0][1]);
        $record = $c->payments->find($id);
        self::assertSame('pending', $record['status']);
        self::assertStringNotContainsString('checkout.stripe.com', $record['encrypted_url']);
        self::assertSame(12345, $c->service->statement(new AccountId($account->value))['balance_minor']);
    }

    public function testProviderFailureRetriesTheSamePaymentIdentifierWithoutPostingMoney(): void
    {
        [$c, $account] = $this->charged();
        $id = EntityId::generate();
        $c->gateway->failOnce = true;
        try { $c->checkout->checkout($id, $account); self::fail('Fictional provider did not fail.'); }
        catch (DomainException) { self::assertSame('pending', $c->payments->find($id)['status']); }
        $c->checkout->checkout($id, $account);
        self::assertCount(2, $c->gateway->calls);
        self::assertSame($c->gateway->calls[0], $c->gateway->calls[1]);
        self::assertSame(12345, $c->service->statement(new AccountId($account->value))['balance_minor']);
    }

    public function testPaymentsRequireStrictAccountOwnershipAndCanBeDisabled(): void
    {
        [$c, $account] = $this->charged();
        $c->gateway->available = false;
        self::assertFalse($c->checkout->enabled());
        try { $c->checkout->checkout(EntityId::generate(), $account); self::fail('A disabled provider started checkout.'); }
        catch (DomainException) { self::assertSame([], $c->gateway->calls); }
        $c->gateway->available = true;
        $c->sis->access->actor = $c->sis->user();
        $c->sis->student();
        $this->expectException(AccessDeniedException::class);
        $c->checkout->checkout(EntityId::generate(), $account);
    }

    public function testAnUncreatedProviderSessionCanBeReplacedAfterItsCreationWindowCloses(): void
    {
        [$c, $account] = $this->charged();
        $id = EntityId::generate();
        $c->gateway->failOnce = true;
        try { $c->checkout->checkout($id, $account); }
        catch (DomainException) { self::assertTrue(true); }
        $c->sis->clock->time = $c->sis->clock->time->modify('+31 minutes');
        try { $c->checkout->checkout($id, $account); self::fail('An expired creation window was reused.'); }
        catch (\Domain\Shared\Exception\ValidationException) {
            self::assertSame('expired', $c->payments->find($id)['status']);
        }
        $fresh = EntityId::generate();
        $c->checkout->checkout($fresh, $account);
        self::assertSame('pending', $c->payments->find($fresh)['status']);
        self::assertSame(12345, $c->service->statement(new AccountId($account->value))['balance_minor']);
    }

    public function testVerifiedNotificationsPostExactlyOneEventSourcedPaymentAndRejectMismatches(): void
    {
        [$c, $account] = $this->charged();
        $id = EntityId::generate();
        $c->checkout->checkout($id, $account);
        $c->gateway->confirmation = ['payment' => $id->value, 'session' => 'cs_test_' . $id->value,
            'minor' => 12344, 'currency' => 'USD'];
        try { $c->confirmation->receive('{}', 'fictional-valid-signature'); self::fail('A mismatched amount was posted.'); }
        catch (DomainException) { self::assertSame('pending', $c->payments->find($id)['status']); }
        $c->gateway->confirmation['minor'] = 12345;
        $c->gateway->confirmation['currency'] = 'EUR';
        try { $c->confirmation->receive('{}', 'fictional-valid-signature'); self::fail('A mismatched currency was posted.'); }
        catch (DomainException) { self::assertSame('pending', $c->payments->find($id)['status']); }
        $c->gateway->confirmation['currency'] = 'USD';
        $c->confirmation->receive('{}', 'fictional-valid-signature');
        $c->confirmation->receive('{}', 'fictional-valid-signature');
        self::assertSame('paid', $c->payments->find($id)['status']);
        $statement = $c->service->statement(new AccountId($account->value));
        self::assertSame(0, $statement['balance_minor']);
        self::assertCount(2, $statement['entries']);
        self::assertSame($id->value, $statement['entries'][1]['operation_id']);
        self::assertNull($c->payments->find($id)['encrypted_url']);
    }

    public function testStripeVerifiesRawSignedBodyModeAndPaymentStateWithoutNetworkCalls(): void
    {
        $config = Collection::factory([]);
        $config->setConfigKey('app', ['url' => 'https://campus.example.invalid']);
        $config->setConfigKey('portal', ['payments' => ['enabled' => true, 'live' => false,
            'secret' => 'sk_test_fictional', 'webhook_secret' => 'whsec_fictional']]);
        $gateway = new StripePaymentGateway($config);
        self::assertTrue($gateway->enabled());
        $id = EntityId::generate()->value;
        $event = ['id' => 'evt_fictional', 'object' => 'event', 'type' => 'checkout.session.completed',
            'livemode' => false, 'data' => ['object' => ['id' => 'cs_test_fictional', 'object' => 'checkout.session',
                'client_reference_id' => $id, 'metadata' => ['sis_payment_id' => $id],
                'mode' => 'payment', 'payment_status' => 'paid', 'amount_total' => 12345, 'currency' => 'usd']]];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $time = time();
        $signature = 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, 'whsec_fictional');
        self::assertSame(['payment' => $id, 'session' => 'cs_test_fictional', 'minor' => 12345, 'currency' => 'USD'],
            $gateway->verify($body, $signature));
        $config->setConfigKey('portal', ['payments' => ['enabled' => false, 'live' => false,
            'secret' => '', 'webhook_secret' => 'whsec_fictional']]);
        self::assertFalse($gateway->enabled());
        self::assertSame($id, $gateway->verify($body, $signature)['payment']);
        $this->expectException(InvalidArgumentException::class);
        $gateway->verify($body . ' ', $signature);
    }
}
