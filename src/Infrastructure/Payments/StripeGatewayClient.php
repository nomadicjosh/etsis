<?php

declare(strict_types=1);

namespace Infrastructure\Payments;

use Domain\Finance\ValueObject\Money;
use Domain\Payments\Service\GatewayClient;
use Domain\Payments\Enum\GatewaySetupIssue;
use Domain\Payments\Exception\GatewaySetupException;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use InvalidArgumentException;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Webhook;

use function Codefy\Framework\Helpers\trans;

final readonly class StripeGatewayClient implements GatewayClient
{
    private function client(#[\SensitiveParameter] string $secret): StripeClient
    {
        $http = new CurlClient();
        $http->setTimeout(12);
        $http->setConnectTimeout(4);
        \Stripe\ApiRequestor::setHttpClient($http);
        Stripe::setEnableTelemetry(false);
        Stripe::setMaxNetworkRetries(0);
        return new StripeClient($secret);
    }

    public function merchant(#[\SensitiveParameter] string $secret): string
    {
        try {
            $id = $this->client($secret)->accounts->retrieve()->id;
        } catch (ApiErrorException) {
            throw new GatewaySetupException(GatewaySetupIssue::Merchant);
        }
        if (!is_string($id) || preg_match('/^acct_[a-zA-Z0-9]+$/D', $id) !== 1) {
            throw new GatewaySetupException(GatewaySetupIssue::Merchant);
        }
        return $id;
    }

    public function checkout(#[\SensitiveParameter] array $binding, string $baseUrl): array
    {
        $p = $binding['payment'];
        $c = $binding['connection'];
        $payment = new EntityId($p['id']);
        $amount = new Money($p['amount_minor'], $p['currency']);
        $expiresAt = $p['expires_at'];
        $purpose = $p['purpose'];
        [$label, $return] = match ($purpose) {
            'account' => [trans('Student account payment'), '/portal/student/accounts/'],
            'application' => [trans('Application fee'), '/portal/apply/'],
            'transcript' => [trans('Official transcript fee'), '/portal/student/transcripts/'],
            default => throw new InvalidArgumentException('Invalid payment purpose.'),
        };
        $client = $this->client($c['secret']);
        try {
            $session = $client->checkout->sessions->create([
                'mode' => 'payment', 'payment_method_types' => ['card'],
                'client_reference_id' => $payment->value,
                'metadata' => ['sis_payment_id' => $payment->value, 'sis_connection_id' => $c['id'],
                    'sis_connection_version' => (string) $c['version']],
                'expires_at' => $expiresAt,
                'line_items' => [['price_data' => ['currency' => strtolower($amount->currency),
                    'unit_amount' => $amount->minor,
                    'product_data' => ['name' => $label]], 'quantity' => 1]],
                'success_url' => $baseUrl . $return . '?checkout=returned',
                'cancel_url' => $baseUrl . $return . '?checkout=cancelled',
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

    public function verify(
        #[\SensitiveParameter] string $body,
        #[\SensitiveParameter] string $signature,
        #[\SensitiveParameter] string $secret,
        bool $live,
    ): ?array {
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
        if (($notification['livemode'] ?? null) !== $live) {
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
            'minor' => $details['amount_total'], 'currency' => strtoupper($details['currency']),
            'connection_id' => $metadata['sis_connection_id'] ?? null,
            'connection_version' => $metadata['sis_connection_version'] ?? null];
    }
}
