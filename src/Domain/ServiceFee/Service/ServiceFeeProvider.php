<?php

declare(strict_types=1);

namespace Domain\ServiceFee\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Portal\Repository\PaymentRepository;
use Domain\ServiceFee\Repository\ServiceFeeRepository;
use Domain\ServiceFee\ValueObject\FeeSchema;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Called only inside the authenticated provider confirmation transaction, after locking its checkout. */
final readonly class ServiceFeeProvider
{
    public function __construct(
        private ServiceFeeLedger $ledger,
        private ServiceFeeRepository $records,
        private AdmissionsRepository $admissions,
        private PaymentRepository $payments,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }
    public function accept(array $record, array $confirmation): void
    {
        $fee = new EntityId($record["fee_id"]);
        $route =
        $this->records->find($fee) ?? throw new CorruptHistoryException();
        $this->admissions->lockPerson(new EntityId($route["person_id"]));
        $root = $this->ledger->load($fee);
        $s = $root->state();
        $a = $s["assessment"];
        if (
            $record["account_id"] !== null ||
            $record["purpose"] !== $a["purpose"] ||
            $record["owner_user_id"] !== $a["owner_id"] ||
            $record["currency"] !== $a["policy"]["currency"]
        ) {
            throw new CorruptHistoryException();
        }
        $payment = new EntityId($record["id"]);
        $operation = PublishedGradeId::forVersion(
            $payment,
            "service.fee.payment",
        );
        $details = [
            "payment_id" => $payment->value,
            "minor" => $confirmation["minor"],
            "currency" => $record["currency"],
            "channel" => "provider",
            "method" => "card",
            "reference" => null,
            "session" => $confirmation["session"],
        ];
        $actor = new EntityId($record["owner_user_id"]);
        $reason =
        "Provider-authenticated service fee payment completing the retained payer checkout.";
        if ($root->receipt($operation->value) === null) {
            $capture = $root->captures()[$payment->value] ?? null;
            if (
                $capture === null || $capture['state'] !== 'received'
                || $capture['received_minor'] !== $confirmation['minor']
            ) {
                throw new CorruptHistoryException();
            }
            $this->audit->record($actor, "service_fee.payment_confirmed", $fee);
            $current =
            $this->payments->find($payment) ??
            throw new CorruptHistoryException();
            foreach (
                [
                    "fee_id",
                    "account_id",
                    "purpose",
                    "owner_user_id",
                    "amount_minor",
                    "currency",
                ] as $key
            ) {
                if ($record[$key] !== $current[$key]) {
                    throw new CorruptHistoryException();
                }
            }
            if (
                !in_array(
                    $current["session_id"],
                    ["pending:" . $payment->value, $confirmation["session"]],
                    true,
                )
            ) {
                throw new CorruptHistoryException();
            }
        }
        $this->ledger->record(
            $fee,
            $operation,
            "payment",
            $details,
            $root->playhead(),
            new EventContext(
                $actor,
                $this->clock->now(),
                $operation,
                new EntityId($s["decision_id"]),
                $reason,
            ),
        );
    }
}
