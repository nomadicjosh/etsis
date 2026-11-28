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
    public function __construct(private ?\Stripe\HttpClient\ClientInterface $transport = null)
    {
    }

    private function client(#[\SensitiveParameter] string $secret): StripeClient
    {
        $http = new CurlClient();
        $http->setTimeout(12);
        $http->setConnectTimeout(4);
        \Stripe\ApiRequestor::setHttpClient($this->transport ?? $http);
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
                'payment_intent_data' => ['capture_method' => 'manual', 'metadata' => [
                    'sis_payment_id' => $payment->value, 'sis_connection_id' => $c['id'],
                    'sis_connection_version' => (string) $c['version']]],
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

    public function inspect(#[\SensitiveParameter] array $binding, string $session): array
    {
        if (preg_match('/^cs_[A-Za-z0-9_]{1,180}$/D', $session) !== 1) {
            throw new DomainException('The hosted session has not been retained yet.');
        }
        try {
            $d = $this->client($binding['connection']['secret'])->checkout->sessions->retrieve(
                $session,
                ['expand' => ['payment_intent']]
            )->toArray();
        } catch (ApiErrorException) {
            throw new DomainException('Payment verification is temporarily unavailable.');
        }
        $p = $binding['payment'];
        $c = $binding['connection'];
        $metadata = ['sis_payment_id' => $p['id'], 'sis_connection_id' => $c['id'],
            'sis_connection_version' => (string) $c['version']];
        if (
            ($d['id'] ?? null) !== $session || ($d['client_reference_id'] ?? null) !== $p['id']
            || ($d['mode'] ?? null) !== 'payment' || ($d['livemode'] ?? null) !== $c['live']
            || ($d['amount_total'] ?? null) !== $p['amount_minor']
            || strtoupper($d['currency'] ?? '') !== $p['currency']
            || !in_array($d['status'] ?? null, ['open', 'complete', 'expired'], true)
        ) {
            throw new DomainException('The provider session does not match its retained checkout.');
        }
        foreach ($metadata as $key => $value) {
            if (($d['metadata'][$key] ?? null) !== $value) {
                throw new DomainException('The provider reference does not match.');
            }
        }
        $pi = $d['payment_intent'] ?? null;
        $result = ['session' => $session, 'intent' => null, 'authorized_minor' => $p['amount_minor'],
            'currency' => $p['currency'], 'received_minor' => 0,
            'state' => $d['status'] === 'expired' ? 'cancelled' : 'open'];
        if ($pi === null) {
            return $result;
        }
        if (
            !is_array($pi) || !is_string($pi['id'] ?? null)
            || preg_match('/^pi_[A-Za-z0-9_]{1,180}$/D', $pi['id']) !== 1
            || ($pi['capture_method'] ?? null) !== 'manual' || ($pi['livemode'] ?? null) !== $c['live']
            || ($pi['amount'] ?? null) !== $p['amount_minor'] || strtoupper($pi['currency'] ?? '') !== $p['currency']
            || !is_int($pi['amount_received'] ?? null) || $pi['amount_received'] < 0
            || $pi['amount_received'] > Money::MAX_MINOR || !is_int($pi['amount_capturable'] ?? null)
        ) {
            throw new DomainException('The payment authorization does not match its retained checkout.');
        }
        foreach ($metadata as $key => $value) {
            if (($pi['metadata'][$key] ?? null) !== $value) {
                throw new DomainException('The authorization reference does not match.');
            }
        }
        $result['intent'] = $pi['id'];
        $result['received_minor'] = $pi['amount_received'];
        $result['state'] = match ($pi['status'] ?? '') {
            'succeeded' => 'paid',
            'canceled' => $pi['amount_received'] === 0 ? 'cancelled' : 'review',
            'requires_capture' => $pi['amount_received'] === 0 && $pi['amount_capturable'] === $p['amount_minor']
            ? 'authorized' : 'review',
            default => $d['status'] === 'open' ? 'open' : 'processing',
        };
        return $result;
    }

    public function perform(#[\SensitiveParameter] array $binding, array $plan): void
    {
        if (
            $plan['payment_id'] !== $binding['payment']['id']
            || $plan['authorized_minor'] !== $binding['payment']['amount_minor']
            || $plan['currency'] !== $binding['payment']['currency']
        ) {
            throw new DomainException('Capture command does not match its checkout.');
        }
        \Domain\Payments\ValueObject\CaptureState::plan($plan);
        try {
            $client = $this->client($binding['connection']['secret']);
            if ($plan['minor'] === 0) {
                $client->paymentIntents->cancel(
                    $plan['intent'],
                    ['cancellation_reason' => 'abandoned'],
                    ['idempotency_key' => 'sis-cancel-' . $plan['payment_id']]
                );
            } else {
                $client->paymentIntents->capture($plan['intent'], ['amount_to_capture' => $plan['minor'],
                    'final_capture' => true], ['idempotency_key' => 'sis-capture-' . $plan['payment_id']]);
            }
        } catch (ApiErrorException) {
            throw new DomainException('Payment completion is uncertain. Verify its provider state before retrying.');
        }
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
                ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.expired'],
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
        if (
            !in_array($details['payment_status'] ?? null, ['paid', 'unpaid'], true)
            || ($details['mode'] ?? null) !== 'payment'
        ) {
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
