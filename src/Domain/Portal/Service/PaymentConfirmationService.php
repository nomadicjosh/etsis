<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Portal\Repository\PaymentRepository;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DomainException;

/** A signed notification wakes server reconciliation. Redirects and webhook bodies never post money. */
final readonly class PaymentConfirmationService
{
    public function __construct(
        private PaymentGateway $gateway,
        private PaymentRepository $payments,
        private UnitOfWork $transactions,
        private Clock $clock,
    ) {
    }

    public function receive(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $signature): void
    {
        $confirmation = $this->gateway->verify($body, $signature);
        if ($confirmation === null) {
            return;
        }
        $this->transactions->run(function () use ($confirmation): void {
            $id = new EntityId($confirmation['payment']);
            $this->payments->lock($id);
            $record = $this->payments->find($id) ?? throw new DomainException('Unknown payment.');
            if (
                ($record['session_id'] !== 'pending:' . $id->value
                    && $record['session_id'] !== $confirmation['session'])
                || (int) $record['amount_minor'] !== $confirmation['minor']
                || $record['currency'] !== $confirmation['currency']
            ) {
                throw new DomainException('Payment does not match its checkout.');
            }
            if (in_array($record['status'], ['paid', 'cancelled'], true)) {
                return;
            }
            if ($record['session_id'] === 'pending:' . $id->value) {
                $this->payments->attach($id, $confirmation['session'], $record['encrypted_url'] ?? '');
            }
            $this->payments->wake($id, $this->clock->now()->getTimestamp());
        });
    }
}
