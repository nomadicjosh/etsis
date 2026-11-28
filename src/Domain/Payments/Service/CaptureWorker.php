<?php

declare(strict_types=1);

namespace Domain\Payments\Service;

use Domain\Portal\Repository\PaymentRepository;
use Domain\Portal\Service\PaymentGateway;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Bounded server reconciliation. An uncertain response never releases the protected budget. */
final readonly class CaptureWorker
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGateway $gateway,
        private CaptureLedger $ledger,
        private UnitOfWork $transactions,
        private Clock $clock,
    ) {
    }

    public function run(int $limit = 1): array
    {
        if ($limit < 1 || $limit > 3) {
            throw new \InvalidArgumentException();
        }
        $result = ['examined' => 0, 'done' => 0, 'waiting' => 0, 'review' => 0];
        $until = microtime(true) + 40;
        while ($result['examined'] < $limit && microtime(true) < $until) {
            $job = $this->transactions->run(fn() => $this->payments->claim($this->clock->now()->getTimestamp()));
            if ($job === null) {
                break;
            }
            $id = new EntityId($job['id']);
            $state = 'queued';
            try {
                $record = $this->payments->find($id) ?? throw new \DomainException('Missing checkout.');
                $state = $this->process($record);
            } catch (\Throwable $failure) {
                // No response, signature, credentials, financial values or exception is logged/queued.
                $state = 'queued';
            }
            $this->transactions->run(fn() => $this->payments->finish(
                $id,
                (int) $job['version'],
                $state,
                $this->clock->now()->getTimestamp() + 60
            ));
            $result['examined']++;
            $result[match ($state) {
                'done' => 'done', 'review' => 'review', default => 'waiting'
            }]++;
        }
        return $result;
    }

    private function process(array $r): string
    {
        $state = $this->ledger->states($r)[$r['id']] ?? null;
        if ($state !== null && $state['state'] === 'received') {
            $this->ledger->complete($r);
            return 'done';
        }
        if ($state !== null && $state['state'] === 'paid') {
            $this->ledger->complete($r);
            return 'done';
        }
        if ($state !== null && $state['state'] === 'cancelled') {
            $this->ledger->progress($r, 'cancelled', 0);
            return 'done';
        }
        if (str_starts_with($r['session_id'], 'pending:')) {
            return 'queued';
        }
        $observation = $this->gateway->inspect($r);
        $this->validate($r, $observation, $state);
        if ($observation['state'] === 'open' || $observation['state'] === 'processing') {
            return 'queued';
        }
        if ($state === null && $observation['state'] === 'cancelled' && $observation['intent'] === null) {
            $this->transactions->run(fn() => $this->payments->cancelled(
                new EntityId($r['id']),
                $this->gateway->checkpoint(array_replace($r, ['status' => 'cancelled']))
            ));
            return 'done';
        }
        $state = $this->ledger->reserve($r, $observation);
        if (in_array($state['state'], ['paid', 'cancelled'], true)) {
            return 'done';
        }
        if ($state['state'] === 'received') {
            $this->ledger->complete($r);
            return 'done';
        }
        if ($observation['state'] === 'authorized' && $state['state'] === 'reserved') {
            $this->gateway->perform($r, $state['plan']);
            $observation = $this->gateway->inspect($r);
            $this->validate($r, $observation, $state);
        }
        if ($observation['state'] === 'cancelled' && $observation['received_minor'] === 0) {
            $this->ledger->progress($r, 'cancelled', 0);
            return 'done';
        }
        if (
            $observation['state'] === 'paid' && $observation['received_minor'] > 0
            && $observation['received_minor'] <= $state['plan']['minor']
        ) {
            $this->ledger->progress($r, 'received', $observation['received_minor']);
            $this->ledger->complete($r);
            return 'done';
        }
        if (in_array($observation['state'], ['paid', 'review'], true)) {
            $this->ledger->progress($r, 'review', $observation['received_minor']);
            return 'review';
        }
        return $state['state'] === 'review' ? 'review' : 'queued';
    }

    private function validate(array $r, array $o, ?array $state): void
    {
        if (
            $o['session'] !== $r['session_id'] || $o['currency'] !== $r['currency']
            || $o['authorized_minor'] !== (int) $r['amount_minor']
            || !is_int($o['received_minor']) || $o['received_minor'] < 0
            || !in_array($o['state'], ['open', 'processing', 'authorized', 'paid', 'cancelled', 'review'], true)
            || ($state !== null && $o['intent'] !== $state['plan']['intent'])
        ) {
            throw new \DomainException('Payment verification does not match the retained source.');
        }
    }
}
