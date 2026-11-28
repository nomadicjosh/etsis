<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    'payment_activity' => trans('Payment activity'),
    'capture_reserve' => trans('Online capture reserved'),
    'capture_progress' => trans('Online capture verification'),
    "title" => trans("Application and transcript fees"),
    "policies" => trans("Service fee policies"),
    "application" => trans("Application fee"),
    "transcript" => trans("Official transcript fee"),
    "policy_help" => trans(
        "Fees are optional. Enter zero to disable a fee for new assessments. Existing quotes " .
        "retain their amount, currency and waiver policy. Unofficial transcripts remain free.",
    ),
    "amount" => trans("Amount"),
    "currency" => trans("Currency"),
    "waivers" => trans("Allow reasoned staff waivers"),
    "save" => trans("Save policy"),
    "back" => trans("Back to record"),
    "back_fees" => trans("Back to fees"),
    "prepare" => trans("Prepare a fee"),
    "program" => trans("Academic program"),
    "term" => trans("Entry term"),
    "student" => trans("Student record"),
    "person_help" => trans(
        "Prepare a quote for this person, then record an actual payment or authorized waiver. " .
        "Application fees apply to one program and entry term; transcript fees apply to one " .
        "official request.",
    ),
    "empty" => trans("No service fees found."),
    "free" => trans("No fee is required for this selection."),
    "more" => trans("Load more fees"),
    "open" => trans("View fee"),
    "quoted" => trans("Quoted fee"),
    "paid" => trans("Net payments"),
    "remaining" => trans("Remaining payment"),
    "cleared" => trans("Fee cleared"),
    "due" => trans("Payment required"),
    "waived" => trans("Authorized waiver"),
    "used" => trans("Applied to the accepted request"),
    "pending" => trans(
        "Ready for the selected application or transcript request",
    ),
    "pay" => trans("Pay securely"),
    "refresh" => trans("Refresh payment status"),
    "checkout_help" => trans(
        "Payment is confirmed by the server. Returning from checkout alone does not clear this " .
        "fee. Refresh its status if confirmation is still pending.",
    ),
    "contact" => trans(
        "Contact the institution if you need help with payment or a fee waiver.",
    ),
    "resume" => trans("Resume application"),
    "history" => trans("Fee decision history"),
    "more_history" => trans("Earlier decisions"),
    "payment" => trans("Record received payment"),
    "waive" => trans("Review fee waiver"),
    "refund" => trans("Record completed refund"),
    "method" => trans("Payment method"),
    "reference" => trans("Receipt reference (optional)"),
    "refund_reference" => trans("Completed refund reference"),
    "refund_help" => trans(
        "Record a refund already completed through the institution's payment process. This does " .
        "not send money or cancel an accepted application or transcript request.",
    ),
    "receipt_help" => trans(
        "Record money actually received. Use a receipt reference only; never enter card numbers, " .
        "bank details or credentials.",
    ),
    "reason" => trans("Reason"),
    "review" => trans("Review decision"),
    "confirm" => trans("Confirm decision"),
    "edit" => trans("Back to fee"),
    "review_help" => trans(
        "Review this decision before confirming. The amount, policy and recorded balance must still match.",
    ),
    "recorded" => trans("Decision recorded."),
    "payments" => trans("Received payments"),
    "refunds" => trans("Completed refunds"),
    "receipt" => trans("Original payment"),
    "cash" => trans("Cash"),
    "check" => trans("Check"),
    "card" => trans("Card"),
    "bank_transfer" => trans("Bank transfer"),
    "wire_transfer" => trans("Wire transfer"),
    "money_order" => trans("Money order"),
    "paypal" => trans("PayPal"),
    "other" => trans("Other"),
    "assess" => trans("Fee assessed"),
    "apply" => trans("Fee applied"),
    "continue" => trans("Continue to application"),
    "transcript_help" => trans(
        "Any required fee must be cleared before requesting an official transcript. The registrar" .
        " then reviews your request and arranges delivery.",
    ),
    "minor_error" => trans("Enter a valid amount in this currency."),
    "overpaid" => trans(
        "Payments exceed the quoted fee. Contact the institution to review the excess.",
    ),
];
