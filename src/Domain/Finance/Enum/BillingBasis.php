<?php

declare(strict_types=1);

namespace Domain\Finance\Enum;

enum BillingBasis: string
{
    case Term = "term";
    case RegisteredCredit = "registered_credit";
}
