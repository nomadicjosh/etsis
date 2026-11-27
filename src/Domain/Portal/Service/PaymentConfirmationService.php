<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Portal\Repository\PaymentRepository;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DomainException;

/** Provider-authenticated notifications only. Browser return URLs never post financial entries. */
final readonly class PaymentConfirmationService
{
    public function __construct(
        private PaymentGateway $gateway,
        private PaymentRepository $payments,
        private AccountRepository $accounts,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private \Domain\ServiceFee\Service\ServiceFeeProvider $fees,
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
            $record = $this->payments->find($id) ?? throw new DomainException('Unknown payment.');
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
            if ($record['purpose'] !== 'account') {
                if (!in_array($record['purpose'], ['application', 'transcript'], true) || $record['fee_id'] === null) {
                    throw new DomainException('Invalid payment purpose.');
                }
                $this->fees->accept($record, $confirmation);
                $this->payments->attach($id, $confirmation['session'], '');
                $this->payments->paid($id);
                return;
            }
            if ($record['account_id'] === null || $record['fee_id'] !== null) {
                throw new DomainException('Invalid account payment.');
            }
            if ($record['status'] === 'paid') {
                return;
            }
            // The signed provider reference can arrive before the checkout HTTP call returns.
            $account = $this->accounts->get(new AccountId($record['account_id']));
            $actor = new EntityId($record['owner_user_id']);
            $account->post(
                $id,
                EntryType::Payment,
                new Money($confirmation['minor'], $confirmation['currency']),
                $actor
            );
            // The actor is the retained checkout initiator; provider verification authorizes completion.
            $this->accounts->save($account, new EventContext(
                $actor,
                $this->clock->now(),
                $id,
                $id,
                reason: 'Provider-authenticated payment completing the account holder\'s retained checkout.'
            ));
            $this->payments->attach($id, $confirmation['session'], '');
            $this->payments->paid($id);
            $this->audit->record($actor, 'payment.confirmed', $id);
        });
    }
}
