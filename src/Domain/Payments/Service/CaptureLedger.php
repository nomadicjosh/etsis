<?php

declare(strict_types=1);

namespace Domain\Payments\Service;

use Closure;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\Repository\LedgerControlRepository;
use Domain\Finance\ValueObject\AccountId;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Payments\ValueObject\CaptureState;
use Domain\Portal\Repository\PaymentRepository;
use Domain\Portal\Service\PaymentGateway;
use Domain\ServiceFee\Repository\ServiceFeeRepository;
use Domain\ServiceFee\Service\ServiceFeeLedger;
use Domain\ServiceFee\Service\ServiceFeeProvider;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DomainException;

/** Protected target budgets serialize with every account/fee writer. Network I/O never runs here. */
final readonly class CaptureLedger
{
    public function __construct(
        private AccountRepository $accounts,
        private ServiceFeeLedger $fees,
        private ServiceFeeRepository $feeRecords,
        private ServiceFeeProvider $feeProvider,
        private AdmissionsRepository $admissions,
        private LedgerControlRepository $controls,
        private PaymentRepository $payments,
        private PaymentGateway $gateway,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    public function states(array $record): array
    {
        $this->gateway->receipt($record) ?? throw new DomainException('A retained gateway receipt is required.');
        return $record['purpose'] === 'account'
        ? $this->accounts->get(new AccountId($record['account_id']))->captures()
        : $this->fees->load(new EntityId($record['fee_id']))->captures();
    }

    private function withTarget(array $record, Closure $action): mixed
    {
        return $this->transactions->run(function () use ($record, $action): mixed {
            if ($record['purpose'] !== 'account') {
                $route = $this->feeRecords->find(new EntityId($record['fee_id']))
                    ?? throw new DomainException('Unknown service fee.');
                $this->admissions->lockPerson(new EntityId($route['person_id']));
            }
            $this->controls->lock();
            $id = new EntityId($record['id']);
            $this->payments->lock($id);
            $current = $this->payments->find($id) ?? throw new DomainException('Unknown payment.');
            $this->gateway->receipt($current) ?? throw new DomainException('Missing retained gateway assignment.');
            foreach (
                ['account_id', 'fee_id', 'purpose', 'owner_user_id', 'amount_minor', 'currency', 'session_id'] as $key
            ) {
                if ($record[$key] !== $current[$key]) {
                    throw new DomainException('The checkout changed during reconciliation.');
                }
            }
            $root = $current['purpose'] === 'account'
                ? $this->accounts->get(new AccountId($current['account_id']))
                : $this->fees->load(new EntityId($current['fee_id']));
            return $action($root, $current);
        });
    }

    public function reserve(array $record, array $observation): array
    {
        return $this->withTarget($record, function ($root, array $r) use ($observation): array {
            $old = $root->captures()[$r['id']] ?? null;
            if ($old !== null) {
                if (
                    $old['plan']['intent'] !== $observation['intent']
                    || $old['plan']['session'] !== $observation['session']
                ) {
                    throw new DomainException('The authorization changed after capture was reserved.');
                }
                return $old;
            }
            $balance = $r['purpose'] === 'account' ? $root->balance()->minor : $root->due();
            $available = max(0, $balance - CaptureState::held($root->captures()));
            $amount = $observation['state'] === 'authorized' && $r['status'] === 'pending'
                && ($r['purpose'] === 'account' || $root->state()['applied_to'] === null)
                ? min($available, (int) $r['amount_minor']) : 0;
            $plan = ['payment_id' => $r['id'], 'session' => $r['session_id'], 'intent' => $observation['intent'],
                'authorized_minor' => (int) $r['amount_minor'], 'minor' => $amount, 'currency' => $r['currency'],
                'owner_id' => $r['owner_user_id'], 'receipt' => $this->gateway->receipt($r)->values()];
            if ($r['purpose'] === 'account') {
                $root->reserveCapture($plan);
                $this->accounts->save($root, $this->context($r, 'reserve'));
            } else {
                $root = $this->fees->record(
                    new EntityId($r['fee_id']),
                    $this->operation($r, 'reserve'),
                    'capture_reserve',
                    $plan,
                    $root->playhead(),
                    $this->context($r, 'reserve', $root->state()['decision_id'])
                );
            }
            $this->audit->record(new EntityId($r['owner_user_id']), 'payment.capture_reserved', new EntityId($r['id']));
            $this->checkRouting($r);
            return $root->captures()[$r['id']];
        });
    }

    public function progress(array $record, string $state, int $received): array
    {
        return $this->withTarget($record, function ($root, array $r) use ($state, $received): array {
            $old = $root->captures()[$r['id']] ?? throw new DomainException('Missing capture plan.');
            if ($old['state'] === 'cancelled') {
                $this->payments->cancelled(new EntityId($r['id']), $this->gateway->checkpoint(
                    array_replace($r, ['status' => 'cancelled'])
                ));
                return $old;
            }
            if ($old['state'] === 'paid' || ($old['state'] === $state && $old['received_minor'] === $received)) {
                return $old;
            }
            $progress = ['payment_id' => $r['id'], 'state' => $state, 'received_minor' => $received];
            if ($r['purpose'] === 'account') {
                $root->captureProgress($progress);
                $this->accounts->save($root, $this->context($r, $state, observed: $received));
            } else {
                $root = $this->fees->record(
                    new EntityId($r['fee_id']),
                    $this->operation($r, $state, $received),
                    'capture_progress',
                    $progress,
                    $root->playhead(),
                    $this->context($r, $state, $root->state()['decision_id'], $received)
                );
            }
            $this->audit->record(
                new EntityId($r['owner_user_id']),
                'payment.capture_' . $state,
                new EntityId($r['id'])
            );
            if ($state === 'cancelled') {
                $closed = array_replace($r, ['status' => 'cancelled']);
                $this->payments->cancelled(new EntityId($r['id']), $this->gateway->checkpoint($closed));
            }
            $this->checkRouting($r);
            return $root->captures()[$r['id']];
        });
    }

    public function complete(array $record): void
    {
        $this->withTarget($record, function ($root, array $r): void {
            $capture = $root->captures()[$r['id']] ?? throw new DomainException('Missing capture.');
            if ($capture['state'] !== 'paid') {
                if ($capture['state'] !== 'received') {
                    throw new DomainException('Only verified received funds can be posted.');
                }
                if ($r['purpose'] === 'account') {
                    $root->captureProgress(['payment_id' => $r['id'], 'state' => 'paid',
                        'received_minor' => $capture['received_minor']]);
                    $this->accounts->save($root, $this->context($r, 'paid'));
                } else {
                    $this->feeProvider->accept(
                        $r,
                        ['minor' => $capture['received_minor'], 'session' => $r['session_id']]
                    );
                }
                $this->audit->record(new EntityId($r['owner_user_id']), 'payment.confirmed', new EntityId($r['id']));
            }
            $this->payments->paid(
                new EntityId($r['id']),
                $this->gateway->checkpoint(array_replace($r, ['status' => 'paid']))
            );
            $this->checkRouting($r);
        });
    }

    private function operation(array $r, string $phase, ?int $observed = null): EntityId
    {
        return PublishedGradeId::forVersion(
            new EntityId($r['id']),
            'payment-capture:' . $phase . ($observed === null ? '' : ':' . $observed)
        );
    }

    private function context(
        array $r,
        string $phase,
        ?string $previous = null,
        ?int $observed = null,
    ): EventContext {
        return new EventContext(
            new EntityId($r['owner_user_id']),
            $this->clock->now(),
            $this->operation($r, $phase, $observed),
            new EntityId($previous ?? $r['id']),
            reason: 'Provider-authenticated payment capture ' . $phase . ' for the retained owner checkout.'
        );
    }

    private function checkRouting(array $record): void
    {
        $now = $this->payments->find(new EntityId($record['id'])) ?? throw new DomainException('Unknown payment.');
        $this->gateway->receipt($now);
        foreach (
            ['account_id', 'fee_id', 'purpose', 'owner_user_id', 'amount_minor', 'currency', 'session_id'] as $key
        ) {
            if ($record[$key] !== $now[$key]) {
                throw new DomainException('The payment routing changed.');
            }
        }
    }
}
