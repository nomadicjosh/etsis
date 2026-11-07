<?php

declare(strict_types=1);

namespace Domain\Finance\Enum;

enum EntryType: string
{
    case Charge = 'charge';
    case Payment = 'payment';
    case Refund = 'refund';
}
