<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Portal\Repository\CampusRepository;
use Domain\Portal\Repository\PaymentRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
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
        private MessageCipher $cipher,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
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
                || $existing['owner_user_id'] !== $user->value)
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
                if ($active['owner_user_id'] !== $user->value) {
                    throw new AccessDeniedException('Access denied.');
                }
                return $active;
            }
            $balance = $this->accounts->get(new AccountId($account->value))->balance();
            if ($balance->minor <= 0) {
                throw new DomainException('There is no outstanding balance to pay.');
            }
            $record = ['account_id' => $account->value, 'owner_user_id' => $user->value,
                'amount_minor' => $balance->minor, 'currency' => $balance->currency,
                // A unique non-null placeholder also works with SQL Server's nullable unique semantics.
                'session_id' => 'pending:' . $id->value, 'status' => 'pending', 'expires_at' => $now + 3600];
            $this->payments->add($id, $record);
            $this->audit->record($user, 'payment.checkout_requested', $id);
            return ['id' => $id->value] + $record;
        });
        $payment = new EntityId($record['id']);
        if (!empty($record['encrypted_url'])) {
            return $this->cipher->open($record['encrypted_url']);
        }
        if ((int) $record['expires_at'] <= $this->clock->now()->getTimestamp() + 1800) {
            // Stripe cannot create a new session with less than 30 minutes remaining.
            // Commit expiration before raising a validation error so a fresh attempt can replace it.
            $this->transactions->run(fn () => $this->payments->expire($payment));
            throw new ValidationException([['field' => 'payment',
                'message' => 'This checkout attempt expired. Reload student accounts to start again.']]);
        }
        // Network calls run outside database transactions; provider idempotency survives retries.
        $session = $this->gateway->checkout(
            $payment,
            new Money((int) $record['amount_minor'], $record['currency']),
            (int) $record['expires_at']
        );
        $this->transactions->run(function () use ($payment, $session): void {
            $this->payments->lock($payment);
            $this->payments->attach($payment, $session['session'], $this->cipher->seal($session['url']));
        });
        return $session['url'];
    }
}
