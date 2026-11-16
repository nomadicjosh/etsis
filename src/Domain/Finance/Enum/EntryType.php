<?php

declare(strict_types=1);

namespace Domain\Finance\Enum;

enum EntryType: string
{
    case Charge = "charge";
    case Payment = "payment";
    case Refund = "refund";
    case ChargeCredit = "charge_credit";

    public function balanceSign(): int
    {
        return match ($this) {
            self::Payment, self::ChargeCredit => -1,
            self::Charge, self::Refund => 1,
        };
    }
}
