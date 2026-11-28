<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Communications\Service\MessageCipher;
use Domain\Finance\ValueObject\Money;
use Domain\Portal\Repository\PaymentRepository;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

/** Caller authorizes before and after. Provider calls never hold a database transaction. */
final readonly class HostedCheckout
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGateway $gateway,
        private MessageCipher $cipher,
        private UnitOfWork $transactions,
        private Clock $clock,
    ) {
    }
    public function open(array $record): string
    {
        $id = new EntityId($record["id"]);
        if (!empty($record["encrypted_url"])) {
            $this->transactions->run(fn() => $this->payments->wake($id, $this->clock->now()->getTimestamp()));
            return $this->cipher->open($record["encrypted_url"]);
        }
        if (
            (int) $record["expires_at"] <=
            $this->clock->now()->getTimestamp() + 1800
        ) {
            $this->transactions->run(fn() => $this->payments->expire($id));
            throw new ValidationException([
                [
                    "field" => "payment",
                    "message" =>
                        trans("This checkout attempt expired. Refresh this payment to start again."),
                ],
            ]);
        }
        $session = $this->gateway->checkout(
            $id,
            new Money((int) $record["amount_minor"], $record["currency"]),
            (int) $record["expires_at"],
            $record["purpose"],
        );
        $checkpoint = $this->gateway->checkpoint($record);
        $this->transactions->run(function () use ($id, $session, $checkpoint): void {
            $this->payments->lock($id);
            $this->payments->attach(
                $id,
                $session["session"],
                $this->cipher->seal($session["url"]),
                $checkpoint,
            );
            $this->payments->wake($id, $this->clock->now()->getTimestamp());
        });
        return $session["url"];
    }
}
