<?php

declare(strict_types=1);

namespace Domain\Payments\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\ValueObject\AccountId;
use Domain\Payments\ValueObject\CaptureState;
use Domain\Portal\Repository\CampusRepository;
use Domain\Portal\Repository\PaymentRepository;
use Domain\ServiceFee\Service\ServiceFeeLedger;
use Domain\ServiceFee\Service\ServiceFeeService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class CaptureActivity
{
    public function __construct(
        private PaymentRepository $payments,
        private AccountRepository $accounts,
        private ServiceFeeLedger $feeLedger,
        private ServiceFeeService $fees,
        private CampusRepository $campus,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private \Domain\Portal\Service\PaymentGateway $gateway,
    ) {
    }

    private function authorize(string $kind, EntityId $target, string $mode, ?EntityId $expected = null): EntityId
    {
        if (!in_array($kind, ['account', 'fee'], true) || !in_array($mode, ['owner', 'staff'], true)) {
            throw new \InvalidArgumentException();
        }
        $actor = $this->access->authenticatedUserId();
        if ($kind === 'account') {
            if ($mode === 'owner') {
                $this->campus->requireOwnedAccount($actor, $target);
            } else {
                $this->access->requirePermission('sis:finance:read');
            }
        } else {
            $this->fees->show($target, $mode);
        }
        if ($expected !== null && $expected->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    public function show(string $kind, EntityId $target, string $mode, ?EntityId $after = null): array
    {
        $actor = $this->authorize($kind, $target, $mode);
        $root = $kind === 'account' ? $this->accounts->get(new AccountId($target->value))
        : $this->feeLedger->load($target);
        $states = $root->captures();
        $rows = $this->payments->forTarget($kind === 'account' ? 'account_id' : 'fee_id', $target, $after);
        $items = [];
        $staffCanRetry = false;
        if ($mode === 'staff') {
            try {
                $this->access->requirePermission($kind === 'account' ? 'sis:finance:post' : 'sis:fees:manage');
                $staffCanRetry = true;
            } catch (AccessDeniedException) {
                $staffCanRetry = false;
            }
        }
        foreach (array_slice($rows, 0, 20) as $r) {
            $state = $states[$r['id']] ?? null;
            if ($state === null && $r['status'] === 'paid') {
                // Earlier paid facts predate manual capture. Verify their original receipt,
                // without interpreting the old encrypted setup as a new authorization plan.
                $receipt = $kind === 'account' ? $root->entry(new EntityId($r['id']))
                : ($root->state()['payments'][$r['id']] ?? null);
                if (
                    $receipt === null || $receipt['minor'] !== (int) $r['amount_minor']
                    || $receipt['currency'] !== $r['currency']
                    || ($kind === 'account' && $receipt['type'] !== 'payment')
                    || ($kind === 'fee' && $receipt['session'] !== $r['session_id'])
                ) {
                    throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
                }
            } elseif ($r['sealed_gateway'] !== null) {
                $this->gateway->receipt($r);
            }
            $items[] = ['id' => $r['id'], 'currency' => $r['currency'],
                'authorized_minor' => $state['plan']['authorized_minor'] ?? (int) $r['amount_minor'],
                'capture_minor' => $state['plan']['minor'] ?? null,
                'received_minor' => $state['received_minor'] ?? null,
                'state' => $state['state'] ?? (in_array($r['status'], ['paid', 'cancelled', 'expired'], true)
                    ? $r['status'] : 'open'),
                'can_retry' => ($mode === 'staff' ? $staffCanRetry : $r['owner_user_id'] === $actor->value)
                    && !in_array($state['state'] ?? $r['status'], ['paid', 'cancelled'], true)];
        }
        $this->authorize($kind, $target, $mode, $actor);
        return ['items' => $items, 'held_minor' => CaptureState::held($states),
            'next_after' => count($rows) > 20 ? $rows[19]['id'] : null];
    }

    public function retry(EntityId $payment, string $mode): array
    {
        return $this->transactions->run(function () use ($payment, $mode): array {
            $r = $this->payments->find($payment) ?? throw new AccessDeniedException();
            $kind = $r['purpose'] === 'account' ? 'account' : 'fee';
            $target = new EntityId($r[$kind === 'account' ? 'account_id' : 'fee_id']);
            $actor = $this->authorize($kind, $target, $mode);
            if ($mode === 'owner' && $r['owner_user_id'] !== $actor->value) {
                throw new AccessDeniedException();
            }
            if ($mode === 'staff') {
                $this->access->requirePermission($kind === 'account' ? 'sis:finance:post' : 'sis:fees:manage');
            }
            if ($mode === 'owner') {
                $this->campus->lockPerson($this->campus->personForUser($actor));
            }
            $this->payments->lock($payment);
            if ($this->payments->find($payment) !== $r) {
                throw new \Domain\Shared\Exception\ConcurrentModificationException();
            }
            $this->payments->wake($payment, $this->clock->now()->getTimestamp());
            $this->audit->record($actor, 'payment.verification_requested', $payment);
            $this->authorize($kind, $target, $mode, $actor);
            if ($mode === 'staff') {
                $this->access->requirePermission($kind === 'account' ? 'sis:finance:post' : 'sis:fees:manage');
            }
            return ['status' => 'queued'];
        });
    }
}
