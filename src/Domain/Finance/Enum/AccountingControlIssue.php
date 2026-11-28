<?php

declare(strict_types=1);

namespace Domain\Finance\Enum;

enum AccountingControlIssue: string
{
    case MissingPeriod = 'missing_period';
    case ClosedPeriod = 'closed_period';
    case OpeningPosition = 'opening_position';
    case ManualReceivable = 'manual_receivable';
}
