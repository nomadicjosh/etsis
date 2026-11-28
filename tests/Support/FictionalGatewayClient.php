<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Payments\Service\GatewayClient;

final class FictionalGatewayClient implements GatewayClient
{
    public array $calls = [];
    public array $states = [];
    public ?\Closure $onPerform = null;
    public string $merchantId = 'acct_fictional';
    public ?\Closure $onCheckout = null;
    public function merchant(string $secret): string
    {
        $this->calls[] = ['probe', $secret];
        return $this->merchantId;
    }
    public function checkout(array $binding, string $baseUrl): array
    {
        $this->calls[] = ['checkout', $binding, $baseUrl];
        ($this->onCheckout)?->__invoke($binding);
        return ['session' => 'cs_test_' . $binding['payment']['id'],
            'url' => 'https://checkout.stripe.com/c/pay/fictional'];
    }
    public function inspect(array $binding, string $session): array
    {
        $p = $binding['payment'];
        return $this->states[$p['id']] ?? ['state' => 'authorized', 'session' => $session,
            'intent' => 'pi_test_' . $p['id'], 'authorized_minor' => $p['amount_minor'],
            'currency' => $p['currency'], 'received_minor' => 0];
    }

    public function perform(array $binding, array $plan): void
    {
        $this->calls[] = ['capture', $plan];
        ($this->onPerform)?->__invoke($binding, $plan);
        $this->states[$plan['payment_id']] = ['state' => $plan['minor'] === 0 ? 'cancelled' : 'paid',
            'session' => $plan['session'], 'intent' => $plan['intent'], 'authorized_minor' => $plan['authorized_minor'],
            'currency' => $plan['currency'], 'received_minor' => $plan['minor']];
    }

    public function verify(string $body, string $signature, string $secret, bool $live): ?array
    {
        return (new \Infrastructure\Payments\StripeGatewayClient())->verify($body, $signature, $secret, $live);
    }
}
