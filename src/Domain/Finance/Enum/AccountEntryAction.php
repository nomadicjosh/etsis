<?php

declare(strict_types=1);

namespace Domain\Finance\Enum;

/** Distinct instructions share one review boundary, without interchangeable confirmations. */
enum AccountEntryAction: string
{
    case Refund = "finance.payment-refund";
    case Posting = "finance.account-posting";
    case Correction = "finance.account-correction";
    case PaymentDetails = "finance.payment-details";
    case ChargeCredit = "finance.charge-credit";
    case Reversal = "finance.account-reversal";

    public function reviewAudit(): string
    {
        return match ($this) {
            self::Posting => "account.posting_reviewed",
            self::Refund => "account.payment_refund_reviewed",
            self::ChargeCredit => "account.charge_credit_reviewed",
            self::Correction => "account.correction_reviewed",
            self::PaymentDetails => "account.receipt_reviewed",
            self::Reversal => "account.reversal_reviewed",
        };
    }

    public function commitAudit(): string
    {
        return match ($this) {
            self::Posting => "account.entry_posted",
            self::Refund => "account.payment_refunded",
            self::ChargeCredit => "account.charge_credited",
            self::Correction => "account.entry_corrected",
            self::PaymentDetails => "account.receipt_corrected",
            self::Reversal => "account.entry_reversed",
        };
    }
}
