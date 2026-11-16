<?php

declare(strict_types=1);

namespace Domain\Finance\Enum;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Check = 'check';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case WireTransfer = 'wire_transfer';
    case MoneyOrder = 'money_order';
    case PayPal = 'paypal';
    case Loan = 'loan';
    case Scholarship = 'scholarship';
    case Grant = 'grant';
    case Other = 'other';
}
