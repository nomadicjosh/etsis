<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** One issued refund, allocated explicitly across at most ten recorded payment receipts. */
final readonly class RefundAllocation
{
    public const int MAX_PAYMENTS = 10;

    /** @var list<array{payment_operation_id:string,amount_minor:int}> */
    private array $payments;

    /** @param array<mixed> $payments */
    public function __construct(array $payments)
    {
        if (!array_is_list($payments) || $payments === [] || count($payments) > self::MAX_PAYMENTS) {
            throw self::invalid();
        }
        $normalized = $seen = [];
        $total = 0;
        foreach ($payments as $payment) {
            if (
                !is_array($payment) ||
                count($payment) !== 2 ||
                !is_string($payment["payment_operation_id"] ?? null) ||
                !is_int($payment["amount_minor"] ?? null) ||
                $payment["amount_minor"] < 1 ||
                $payment["amount_minor"] > Money::MAX_MINOR ||
                isset($seen[$payment["payment_operation_id"]])
            ) {
                throw self::invalid();
            }
            new EntityId($payment["payment_operation_id"]);
            $total += $payment["amount_minor"];
            if ($total > Money::MAX_MINOR) {
                throw self::invalid();
            }
            $seen[$payment["payment_operation_id"]] = true;
            $normalized[] = [
                "payment_operation_id" => $payment["payment_operation_id"],
                "amount_minor" => $payment["amount_minor"],
            ];
        }
        usort(
            $normalized,
            static fn(array $a, array $b): int => strcmp($a["payment_operation_id"], $b["payment_operation_id"]),
        );
        $this->payments = $normalized;
    }

    public static function forPayment(EntityId $payment, int $minor): self
    {
        return new self([["payment_operation_id" => $payment->value, "amount_minor" => $minor]]);
    }

    /** @return list<array{payment_operation_id:string,amount_minor:int}> */
    public function values(): array
    {
        return $this->payments;
    }

    public function totalMinor(): int
    {
        return array_sum(array_column($this->payments, "amount_minor"));
    }

    private static function invalid(): ValidationException
    {
        return new ValidationException([
            [
                "field" => "refund_allocations",
                "message" => "Choose one to ten distinct payments and a positive amount for each allocation.",
            ],
        ]);
    }
}
