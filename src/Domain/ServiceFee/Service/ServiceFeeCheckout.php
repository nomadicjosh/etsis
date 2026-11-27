<?php

declare(strict_types=1);

namespace Domain\ServiceFee\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\Repository\PaymentRepository;
use Domain\Portal\Service\HostedCheckout;
use Domain\Portal\Service\PaymentGateway;
use Domain\ServiceFee\Repository\ServiceFeeRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

final readonly class ServiceFeeCheckout
{
    public function __construct(
        private ServiceFeeService $fees,
        private ServiceFeeRepository $records,
        private PaymentRepository $payments,
        private PaymentGateway $gateway,
        private HostedCheckout $hosted,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }
    public function checkout(EntityId $fee): array
    {
        $actor = $this->access->authenticatedUserId();
        $data = $this->fees->show($fee, "owner");
        if ($data["cleared"]) {
            return ["url" => null, "cleared" => true];
        }
        if (!$this->gateway->enabled()) {
            throw new ValidationException([
                [
                    "field" => "payment",
                    "message" =>
                        trans("Online payments are not configured. Contact the institution about payment or an " .
                        "authorized waiver."),
                ],
            ]);
        }
        $record = $this->transactions->run(function () use (
            $fee,
            $actor,
        ): array {
            $r =
                $this->records->find($fee) ?? throw new AccessDeniedException();
            $this->admissions->lockPerson(new EntityId($r["person_id"]));
            $current = $this->fees->show($fee, "owner");
            if (
                $this->access->authenticatedUserId()->value !== $actor->value ||
                $current["applied_to"] !== null ||
                $current["cleared"]
            ) {
                throw new ValidationException([
                    [
                        "field" => "payment",
                        "message" =>
                            trans("This fee has already been cleared or used. Refresh its status."),
                    ],
                ]);
            }
            $active = $this->payments->activeFee(
                $fee,
                $this->clock->now()->getTimestamp() + 60,
            );
            if ($active !== null) {
                if (
                    $active["owner_user_id"] !== $actor->value ||
                    $active["purpose"] !== $current["purpose"] ||
                    $active["account_id"] !== null ||
                    $active["fee_id"] !== $fee->value
                ) {
                    throw new AccessDeniedException();
                }
                if (
                    (int) $active["amount_minor"] ===
                        $current["policy"]["minor"] - $current["net_minor"] &&
                    $active["currency"] === $current["policy"]["currency"]
                ) {
                    return $active;
                }
                $this->payments->expire(new EntityId($active["id"]));
            }
            $id = EntityId::generate();
            $row = [
                "account_id" => null,
                "fee_id" => $fee->value,
                "purpose" => $current["purpose"],
                "owner_user_id" => $actor->value,
                "amount_minor" =>
                    $current["policy"]["minor"] - $current["net_minor"],
                "currency" => $current["policy"]["currency"],
                "session_id" => "pending:" . $id->value,
                "status" => "pending",
                "expires_at" => $this->clock->now()->getTimestamp() + 3600,
            ];
            $this->payments->add($id, $row);
            $this->audit->record($actor, "service_fee.checkout_requested", $id);
            if (
                $this->fees->show($fee, "owner")["version"] !==
                $current["version"]
            ) {
                throw new \Domain\Shared\Exception\ConcurrentModificationException(
                    "The fee changed before checkout. Refresh its status.",
                );
            }
            return ["id" => $id->value] + $row;
        });
        $url = $this->hosted->open($record);
        $current = $this->fees->show($fee, "owner");
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        return [
            "url" => $current["cleared"] ? null : $url,
            "cleared" => $current["cleared"],
        ];
    }
}
