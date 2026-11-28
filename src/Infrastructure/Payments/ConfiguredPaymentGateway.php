<?php

declare(strict_types=1);

namespace Infrastructure\Payments;

use Domain\Finance\ValueObject\GatewayReceipt;
use Domain\Finance\ValueObject\Money;
use Domain\Payments\Service\GatewayClient;
use Domain\Payments\Service\GatewayConfiguration;
use Domain\Payments\Service\GatewayPolicy;
use Domain\Portal\Repository\PaymentRepository;
use Domain\Portal\Service\PaymentGateway;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use InvalidArgumentException;
use Throwable;

final readonly class ConfiguredPaymentGateway implements PaymentGateway
{
    public function __construct(
        private GatewayConfiguration $configuration,
        private GatewayClient $client,
        private GatewayPolicy $policy,
        private PaymentRepository $payments,
    ) {
    }

    public function enabled(string $purpose = 'account', ?string $currency = null): bool
    {
        if (!$this->policy->enabled()) {
            return false;
        }
        try {
            if ($currency !== null) {
                $c = $this->configuration->select($purpose, $currency);
                return $c['live'] || $this->policy->allowTest();
            }
            // Status is informational; actual checkout always resolves its exact purpose/currency route.
            foreach ($this->configuration->records->routes() as $row) {
                if ($row['purpose'] === $purpose && $this->enabled($purpose, $row['currency'])) {
                    return true;
                }
            }
            return false;
        } catch (DomainException | InvalidArgumentException) {
            return false;
        }
    }

    public function prepare(EntityId $payment, array $record): array
    {
        if (!$this->enabled($record['purpose'], $record['currency'])) {
            throw new DomainException('Online payments are not configured for this purpose and currency.');
        }
        $row = $this->configuration->bind($payment, $record, $this->policy->baseUrl());
        $this->admit($this->configuration->binding(['id' => $payment->value] + $row));
        return $row;
    }

    public function checkout(EntityId $payment, Money $amount, int $expiresAt, string $purpose = 'account'): array
    {
        $row = $this->payments->find($payment) ?? throw new DomainException('Unknown checkout.');
        $binding = $this->configuration->binding($row);
        if (
            $binding['payment']['amount_minor'] !== $amount->minor
            || $binding['payment']['currency'] !== $amount->currency
            || $binding['payment']['expires_at'] !== $expiresAt || $binding['payment']['purpose'] !== $purpose
        ) {
            throw new DomainException('Checkout does not match its retained payment.');
        }
        $this->admit($binding);
        if ($binding['connection']['secret'] === null) {
            throw new DomainException('Reuse the retained hosted session instead of creating it again.');
        }
        return $this->client->checkout($binding, $binding['base_url']);
    }

    private function admit(#[\SensitiveParameter] array $binding): void
    {
        if (!$this->policy->enabled() || (!$binding['connection']['live'] && !$this->policy->allowTest())) {
            throw new DomainException('Online payments are unavailable in this installation.');
        }
    }

    public function checkpoint(array $record): ?string
    {
        return $this->configuration->checkpoint($record);
    }

    public function receipt(array $record): ?GatewayReceipt
    {
        return GatewayReceipt::fromConnection($this->configuration->binding($record)['connection']);
    }

    public function verify(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $signature): ?array
    {
        // This is an untrusted routing hint only. No financial effect precedes signature verification.
        try {
            if (strlen($body) > 262144 || strlen($signature) > 1024) {
                throw new InvalidArgumentException();
            }
            $hint = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
            if (
                !in_array(
                    $hint['type'] ?? null,
                    ['checkout.session.completed', 'checkout.session.async_payment_succeeded'],
                    true
                )
            ) {
                return null;
            }
            $id = new EntityId($hint['data']['object']['client_reference_id'] ?? '');
            $row = $this->payments->find($id) ?? throw new InvalidArgumentException();
            $binding = $this->configuration->binding($row);
        } catch (Throwable) {
            throw new InvalidArgumentException('Invalid payment notification.');
        }
        $c = $binding['connection'];
        if (!$c['live'] && !$this->policy->allowTest()) {
            throw new InvalidArgumentException('Test payments cannot post in this installation.');
        }
        $secrets = [$c['webhook']];
        $current = $this->configuration->records->connection(new EntityId($c['id']));
        if ($current !== null) {
            $now = $this->configuration->connection($current);
            if (
                $now['merchant'] !== $c['merchant'] || $now['live'] !== $c['live']
                || $now['currency'] !== $c['currency']
            ) {
                throw new InvalidArgumentException('Payment connection identity changed.');
            }
            // Stripe signs old sessions with the endpoint's current key after a signing-secret rotation.
            $secrets[] = $now['webhook'];
        }
        $verified = false;
        $confirmation = null;
        foreach (array_unique($secrets) as $secret) {
            try {
                $confirmation = $this->client->verify($body, $signature, $secret, $c['live']);
                $verified = true;
                break;
            } catch (InvalidArgumentException) {
                // Try only the authenticated current key for the very same retained merchant connection.
            }
        }
        if (!$verified) {
            throw new InvalidArgumentException('Invalid payment notification.');
        }
        if ($confirmation === null) {
            return null;
        }
        if (
            $confirmation['payment'] !== $id->value || $confirmation['connection_id'] !== $c['id']
            || $confirmation['connection_version'] !== (string) $c['version']
        ) {
            throw new InvalidArgumentException('Invalid payment notification.');
        }
        unset($confirmation['connection_id'], $confirmation['connection_version']);
        return $confirmation;
    }
}
