<?php

declare(strict_types=1);

namespace Domain\Finance\Enum;

enum LedgerAccountKind: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
}
