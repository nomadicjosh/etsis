<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Payments\StripeGatewayClient;
use PHPUnit\Framework\TestCase;

final class StripeManualCaptureTest extends TestCase
{
    public function testOfficialSdkSendsManualSingleCaptureAndVerifiesTheBoundAuthorization(): void
    {
        $p = EntityId::generate()->value;
        $c = EntityId::generate()->value;
        $metadata = ['sis_payment_id' => $p, 'sis_connection_id' => $c, 'sis_connection_version' => '1'];
        $pi = ['id' => 'pi_fictional', 'object' => 'payment_intent', 'capture_method' => 'manual', 'amount' => 1000,
            'amount_capturable' => 1000, 'amount_received' => 0, 'currency' => 'usd', 'livemode' => false,
            'metadata' => $metadata, 'status' => 'requires_capture'];
        $session = ['id' => 'cs_test_fictional', 'object' => 'checkout.session',
            'mode' => 'payment', 'livemode' => false,
            'url' => 'https://checkout.stripe.com/c/pay/fictional',
            'client_reference_id' => $p, 'metadata' => $metadata,
            'amount_total' => 1000, 'currency' => 'usd', 'status' => 'complete', 'payment_intent' => $pi];
        $requests = [];
        $http = $this->createStub(\Stripe\HttpClient\ClientInterface::class);
        $http->method('request')->willReturnCallback(static function (
            $method,
            $url,
            $headers,
            $params
        ) use (
            &$requests,
            &$session
        ): array {
            $idempotency = [];
            foreach ($headers as $key => $header) {
                if ($key === 'Idempotency-Key' || str_starts_with((string) $header, 'Idempotency-Key:')) {
                    $idempotency[] = $header;
                }
            }
            $requests[] = ['method' => $method, 'path' => parse_url($url, PHP_URL_PATH),
                'params' => $params, 'idempotency' => $idempotency];
            return [json_encode($session, JSON_THROW_ON_ERROR), 200, []];
        });
        $client = new StripeGatewayClient($http);
        $asset = ['id' => EntityId::generate()->value, 'code' => 'CLEAR', 'title' => 'Fictional clearing',
            'kind' => 'asset', 'currency' => 'USD'];
        $connection = ['id' => $c, 'version' => 1, 'provider' => 'stripe',
            'merchant' => 'acct_fictional', 'live' => false,
            'asset' => $asset, 'secret' => 'sk_test_fictional'];
        $binding = ['payment' => ['id' => $p, 'purpose' => 'account', 'amount_minor' => 1000,
            'currency' => 'USD', 'expires_at' => time() + 3600], 'connection' => $connection];
        $client->checkout($binding, 'https://campus.example.invalid');
        self::assertSame('manual', $requests[0]['params']['payment_intent_data']['capture_method']);
        self::assertSame($metadata, $requests[0]['params']['payment_intent_data']['metadata']);
        self::assertSame(['card'], $requests[0]['params']['payment_method_types']);
        self::assertSame('authorized', $client->inspect($binding, $session['id'])['state']);
        $plan = ['payment_id' => $p, 'session' => $session['id'], 'intent' => $pi['id'], 'authorized_minor' => 1000,
            'minor' => 750, 'currency' => 'USD', 'owner_id' => EntityId::generate()->value,
            'receipt' => \Domain\Finance\ValueObject\GatewayReceipt::fromConnection($connection)->values()];
        $client->perform($binding, $plan);
        self::assertSame(['amount_to_capture' => 750, 'final_capture' => 'true'], $requests[2]['params']);
        self::assertStringContainsString('sis-capture-' . $p, implode(' ', $requests[2]['idempotency']));
        $client->perform($binding, array_replace($plan, ['minor' => 0]));
        self::assertSame('/v1/payment_intents/pi_fictional/cancel', $requests[3]['path']);
        $session['payment_intent']['metadata']['sis_payment_id'] = EntityId::generate()->value;
        $this->expectException(\DomainException::class);
        $client->inspect($binding, $session['id']);
    }
}
