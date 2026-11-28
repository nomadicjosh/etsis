<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Payments\Service\GatewayClient;

final class FictionalGatewayClient implements GatewayClient
{
    public array $calls = [];
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
    public function verify(string $body, string $signature, string $secret, bool $live): ?array
    {
        return (new \Infrastructure\Payments\StripeGatewayClient())->verify($body, $signature, $secret, $live);
    }
}
