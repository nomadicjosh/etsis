<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    'fees' => trans('Service-fee accounting'),
    'fee_configure' => trans('Review service-fee accounting'),
    'fee_help' => trans(
        'New quotes retain these accounts and the recognition policy. '
        . 'Earlier quotes keep their original accounting status. '
        . 'Configure classified accounts before selecting them here.'
    ),
    'fee_inactive' => trans('Service-fee journals are not enabled for new quotes in this currency.'),
    'cash' => trans('Staff receipt and refund asset'),
    'liability' => trans('Unearned fees liability'),
    'expense' => trans('Processing expense account'),
    'application' => trans('Application fee income'),
    'transcript' => trans('Transcript fee income'),
    'recognition' => trans('Recognize fee income'),
    'recognition_acceptance' => trans('When the application or transcript request is accepted'),
    'recognition_receipt' => trans('When money is received'),
    'expense_title' => trans('Record processing expense'),
    'expense_help' => trans(
        'Record an actual processor charge from a statement. Debit the configured expense account and credit '
        . 'the asset actually charged. Include the statement reference in the reason. '
        . 'This does not collect a fee from the student or record a bank payout.'
    ),
    'expense_amount' => trans('Actual expense amount'),
    'asset' => trans('Asset charged by the processor'),
    'reference' => trans('Unique accounting reference (uppercase)'),
    'posted_on' => trans('Accounting date'),
    'fee_source' => trans('Open service fee'),
    'fee_source_help' => trans(
        'This journal retains an original service-fee decision. '
        . 'Open the fee to review its receipts and completed refunds.'
    ),
    'current_labels' => trans(
        'Account names are current chart labels. Original journal identities, amounts '
        . 'and mapping references remain retained.'
    ),
    "title" => trans("Accounting controls"),
    "kicker" => trans("STUDENT FINANCE"),
    "intro" => trans(
        "Publish student-account mappings, open accounting periods, and verify retained activity before closing.",
    ),
    "inactive" => trans(
        "Automatic mapping is not enabled for this currency. "
        . "Existing student accounts or journals require a separate reviewed opening-position conversion.",
    ),
    "mapping" => trans("Student-account mapping"),
    "configure" => trans("Review mapping setup"),
    "mapping_help" => trans(
        "Accounts receivable is created on activation and stays connected. "
        . "Mapping changes apply to new entries; "
        . "credits, reversals and amount corrections retain their original counterpart.",
    ),
    "periods" => trans("Accounting periods"),
    "open_period" => trans("Open an accounting period"),
    "period_help" => trans(
        "Periods use inclusive accounting dates in the configured timezone. "
        . "A closed or missing period blocks new student postings for that date.",
    ),
    "currency" => trans("Currency"),
    "timezone" => trans("Accounting timezone"),
    "ar_code" => trans("Accounts-receivable code"),
    "ar_title" => trans("Accounts-receivable title"),
    "ar_default" => trans("Student accounts receivable"),
    "receivable" => trans("Accounts receivable"),
    "charge" => trans("Default charge counterpart"),
    "payment" => trans("Default payment counterpart"),
    "refund" => trans("Refund disbursement counterpart"),
    "overrides" => trans("Optional source and payment-method mappings"),
    "overrides_help" => trans(
        "Leave an override blank to use the default counterpart. "
        . "A receipt-method correction alone never moves an earlier ledger posting.",
    ),
    "source_manual_charge" => trans("Staff-recorded charges"),
    "source_section_fee" => trans("Section fees"),
    "source_term_tuition" => trans("Term tuition"),
    "source_term_fee" => trans("Term fees"),
    "source_credit_tuition" => trans("Per-credit tuition"),
    "source_credit_fee" => trans("Per-credit fees"),
    "method_cash" => trans("Cash payments"),
    "method_check" => trans("Check payments"),
    "method_card" => trans("Card payments"),
    "method_bank_transfer" => trans("Bank-transfer payments"),
    "method_wire_transfer" => trans("Wire-transfer payments"),
    "method_money_order" => trans("Money-order payments"),
    "method_paypal" => trans("PayPal payments"),
    "method_loan" => trans("Loan payments"),
    "method_scholarship" => trans("Scholarship payments"),
    "method_grant" => trans("Grant payments"),
    "method_other" => trans("Other payments"),
    "reason" => trans("Reason for this accounting decision"),
    "label" => trans("Period label"),
    "start" => trans("First accounting date"),
    "end" => trans("Last accounting date"),
    "open" => trans("Open"),
    "closed" => trans("Closed"),
    "status" => trans("Status"),
    "actions" => trans("Actions"),
    "more" => trans("More accounting actions"),
    "review" => trans("Review accounting change"),
    "confirm" => trans("Confirm accounting change"),
    "review_title" => trans("Review before confirming"),
    "review_help" => trans(
        "Check the accounts, dates and reason above. Editing a field requires a fresh review.",
    ),
    "saved" => trans("Accounting decision recorded."),
    "back" => trans("Back to accounting controls"),
    "back_scan" => trans("Back to reconciliation"),
    "back_journal" => trans("Back to journal"),
    "refresh" => trans("Refresh accounting controls"),
    "more_periods" => trans("Next accounting periods"),
    "no_periods" => trans("No accounting periods have been opened."),
    "reconcile" => trans("Reconcile period"),
    "verification" => trans("Retained posting verification"),
    "scan_help" => trans(
        "Verification compares ledger entries with their protected student-account sources "
        . "and the retained period checkpoint. It creates no charge, refund or journal.",
    ),
    "scan_next" => trans("Verify next entries"),
    "scan_restart" => trans("Start verification again"),
    "progress" => trans("{checked} of {total} entries verified"),
    "verified" => trans(
        "The period reconciles to its retained student-account activity.",
    ),
    "net" => trans("Net student receivable movement"),
    "journal" => trans("Journal"),
    "date" => trans("Accounting date"),
    "effect" => trans("Receivable movement"),
    "view_journal" => trans("View journal"),
    "empty" => trans("This period has no ledger activity."),
    "close" => trans("Review period closing"),
    "reopen" => trans("Review period reopening"),
    "close_help" => trans(
        "Closing prevents further postings in this period. "
        . "A new posting invalidates an unfinished closing review. "
        . "Reopening requires separate authority and a recorded reason.",
    ),
    "source" => trans("Automatic student-account journal"),
    "source_help" => trans(
        "Use the student account to correct this entry. Its original mapping and journal remain retained.",
    ),
    "source_account" => trans("Open student account"),
    "denied" => trans(
        "Accounting access is no longer available. Reopen the task after your access is restored.",
    ),
];
