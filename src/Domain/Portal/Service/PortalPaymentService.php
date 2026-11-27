<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\ValueObject\AccountId;
use Domain\Portal\Repository\CampusRepository;
use Domain\Portal\Repository\PaymentRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DomainException;

final readonly class PortalPaymentService
{
    public function __construct(
        private PaymentRepository $payments,
        private CampusRepository $campus,
        private AccountRepository $accounts,
        private StudentAccess $access,
        private PaymentGateway $gateway,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private HostedCheckout $hosted,
    ) {
    }

    public function enabled(): bool
    {
        $this->access->authenticatedUserId();
        return $this->gateway->enabled();
    }

    public function checkout(EntityId $id, EntityId $account): string
    {
        $user = $this->access->authenticatedUserId();
        $this->campus->requireOwnedAccount($user, $account);
        if (!$this->gateway->enabled()) {
            throw new DomainException('Online payments are not configured. Contact student accounts.');
        }
        $record = $this->transactions->run(function () use ($id, $account, $user): array {
            // Serialize checkout creation for an owner, including separate browser tabs.
            $this->campus->lockPerson($this->campus->personForUser($user));
            $existing = $this->payments->find($id);
            if (
                $existing !== null && ($existing['account_id'] !== $account->value
                || $existing['owner_user_id'] !== $user->value
                || $existing['purpose'] !== 'account' || $existing['fee_id'] !== null)
            ) {
                throw new AccessDeniedException('Access denied.');
            }
            $now = $this->clock->now()->getTimestamp();
            if ($existing !== null) {
                if ($existing['status'] !== 'pending' || (int) $existing['expires_at'] <= $now + 60) {
                    throw new DomainException('This checkout has completed or expired. Reload your account.');
                }
                return $existing;
            }
            $active = $this->payments->active($account, $now + 60);
            if ($active !== null) {
                if (
                    $active['owner_user_id'] !== $user->value || $active['purpose'] !== 'account'
                    || $active['fee_id'] !== null
                ) {
                    throw new AccessDeniedException('Access denied.');
                }
                return $active;
            }
            $balance = $this->accounts->get(new AccountId($account->value))->balance();
            if ($balance->minor <= 0) {
                throw new DomainException('There is no outstanding balance to pay.');
            }
            $record = ['purpose' => 'account', 'fee_id' => null,
                'account_id' => $account->value, 'owner_user_id' => $user->value,
                'amount_minor' => $balance->minor, 'currency' => $balance->currency,
                // A unique non-null placeholder also works with SQL Server's nullable unique semantics.
                'session_id' => 'pending:' . $id->value, 'status' => 'pending', 'expires_at' => $now + 3600];
            $this->payments->add($id, $record);
            $this->audit->record($user, 'payment.checkout_requested', $id);
            return ['id' => $id->value] + $record;
        });
        $url = $this->hosted->open($record);
        $this->campus->requireOwnedAccount($user, $account);
        if ($this->access->authenticatedUserId()->value !== $user->value) {
            throw new AccessDeniedException();
        }
        return $url;
    }
}
