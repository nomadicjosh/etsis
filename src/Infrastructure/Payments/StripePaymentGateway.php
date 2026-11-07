<?php

declare(strict_types=1);

namespace Infrastructure\Payments;

use Domain\Finance\ValueObject\Money;
use Domain\Portal\Service\PaymentGateway;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use InvalidArgumentException;
use Qubus\Config\ConfigContainer;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Webhook;

final readonly class StripePaymentGateway implements PaymentGateway
{
    public function __construct(private ConfigContainer $config)
    {
    }

    public function enabled(): bool
    {
        $secret = $this->config->string('portal.payments.secret', '');
        $mode = $this->config->boolean('portal.payments.live', false) ? 'live_' : 'test_';
        return $this->config->boolean('portal.payments.enabled', false)
        && (str_starts_with($secret, 'sk_' . $mode) || str_starts_with($secret, 'rk_' . $mode))
        && $this->config->string('portal.payments.webhook_secret', '') !== ''
        && $this->baseUrl() !== null;
    }

    public function checkout(EntityId $payment, Money $amount, int $expiresAt): array
    {
        if (!$this->enabled()) {
            throw new DomainException('Online payments are not configured.');
        }
        $http = new CurlClient();
        $http->setTimeout(12);
        $http->setConnectTimeout(4);
        \Stripe\ApiRequestor::setHttpClient($http);
        Stripe::setEnableTelemetry(false);
        Stripe::setMaxNetworkRetries(1);
        $client = new StripeClient($this->config->string('portal.payments.secret'));
        try {
            $session = $client->checkout->sessions->create([
                'mode' => 'payment', 'payment_method_types' => ['card'],
                'client_reference_id' => $payment->value,
                'metadata' => ['sis_payment_id' => $payment->value],
                'expires_at' => $expiresAt,
                'line_items' => [['price_data' => ['currency' => strtolower($amount->currency),
                    'unit_amount' => $amount->minor,
                    'product_data' => ['name' => 'Student account payment']], 'quantity' => 1]],
                'success_url' => $this->baseUrl() . '/portal/student/accounts/?checkout=returned',
                'cancel_url' => $this->baseUrl() . '/portal/student/accounts/?checkout=cancelled',
            ], ['idempotency_key' => 'sis-' . $payment->value]);
        } catch (ApiErrorException) {
            throw new DomainException('Checkout is temporarily unavailable. Please try again.');
        }
        $details = $session->toArray();
        $url = $details['url'] ?? null;
        if (
            !is_string($details['id'] ?? null) || !is_string($url)
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_HOST) !== 'checkout.stripe.com'
            || parse_url($url, PHP_URL_USER) !== null
        ) {
            throw new DomainException('Checkout is temporarily unavailable. Please try again.');
        }
        return ['session' => $details['id'], 'url' => $url];
    }

    public function verify(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $signature): ?array
    {
        // Turning off new checkouts must not discard confirmations for an already paid session.
        $secret = $this->config->string('portal.payments.webhook_secret', '');
        if ($secret === '') {
            throw new DomainException('Payment notifications are not configured.');
        }
        try {
            $event = Webhook::constructEvent(
                $body,
                $signature,
                $secret,
                300
            );
        } catch (\UnexpectedValueException | \Stripe\Exception\SignatureVerificationException) {
            throw new InvalidArgumentException('Invalid payment notification.');
        }
        $notification = $event->toArray();
        if (($notification['livemode'] ?? null) !== $this->config->boolean('portal.payments.live', false)) {
            throw new InvalidArgumentException('Invalid payment mode.');
        }
        if (
            !in_array(
                $notification['type'] ?? null,
                ['checkout.session.completed', 'checkout.session.async_payment_succeeded'],
                true
            )
        ) {
            return null;
        }
        $data = $notification['data'] ?? null;
        $details = is_array($data) ? ($data['object'] ?? null) : null;
        if (!is_array($details) || ($details['object'] ?? null) !== 'checkout.session') {
            throw new InvalidArgumentException('Invalid checkout notification.');
        }
        if (($details['payment_status'] ?? null) !== 'paid' || ($details['mode'] ?? null) !== 'payment') {
            return null;
        }
        $metadata = $details['metadata'] ?? null;
        if (
            !is_string($details['client_reference_id'] ?? null) || !is_string($details['id'] ?? null)
            || !is_int($details['amount_total'] ?? null) || !is_string($details['currency'] ?? null)
            || !is_array($metadata) || ($metadata['sis_payment_id'] ?? null) !== $details['client_reference_id']
        ) {
            throw new InvalidArgumentException('Invalid payment notification.');
        }
        return ['payment' => $details['client_reference_id'], 'session' => $details['id'],
            'minor' => $details['amount_total'], 'currency' => strtoupper($details['currency'])];
    }

    private function baseUrl(): ?string
    {
        $url = rtrim($this->config->string('app.url'), '/');
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && isset($parts['host'])
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
        ? $url : null;
    }
}
