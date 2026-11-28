<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    "title" => trans("Tuition and invoices"),
    "intro" => trans(
        "Review pending tuition and fees before they become payable. Issued invoices retain their original charges.",
    ),
    "schedules" => trans("Tuition schedules"),
    "assignment" => trans("Assign a tuition schedule"),
    "pending" => trans("Pending charges"),
    "all" => trans("All term bills and invoices"),
    "new_schedule" => trans("Create tuition schedule"),
    "edit" => trans("Edit published schedule"),
    "back" => trans("Back"),
    "back_invoice" => trans("Back to invoice"),
    "back_bill" => trans("Back to term bill"),
    "back_record" => trans("Back to record"),
    "name" => trans("Schedule name"),
    "currency" => trans("Currency"),
    "income" => trans("Classified tuition income account"),
    "reason" => trans("Reason for this billing decision"),
    "brackets" => trans("Credit brackets"),
    "bracket" => trans("Bracket"),
    "bracket_help" => trans(
        "Use contiguous ranges from 0 through 999.999 academic credits. Each bracket calculates "
         . "a flat amount plus its rate for credits above the specified threshold. CEUs are "
         . "excluded.",
    ),
    "from_milli" => trans("From credits (inclusive)"),
    "through_milli" => trans("Through credits (inclusive)"),
    "flat_minor" => trans("Flat amount"),
    "rate_minor" => trans("Amount per additional credit"),
    "rate_from_milli" => trans("Charge the rate above this many credits"),
    "add_bracket" => trans("Add bracket"),
    "remove_bracket" => trans("Remove bracket"),
    "up" => trans("Move bracket up"),
    "down" => trans("Move bracket down"),
    "review" => trans("Review billing decision"),
    "review_title" => trans("Review before confirming"),
    "review_help" => trans(
        "Check the exact amounts and source below. Editing a field requires a fresh review.",
    ),
    "confirm" => trans("Confirm billing decision"),
    "saved" => trans("Billing decision recorded."),
    "more" => trans("Next page"),
    "empty" => trans("No records on this page."),
    "scope" => trans("Assignment level"),
    "scope_help" => trans(
        "Student assignment takes precedence, then program, then term. Existing term bills "
         . "retain their schedule until an authorized reprice before their first invoice.",
    ),
    "student" => trans("Student"),
    "program" => trans("Academic program"),
    "term" => trans("Term"),
    "target" => trans("Assignment target"),
    "schedule" => trans("Tuition schedule"),
    "fallback" => trans("Clear this assignment and use the next fallback"),
    "loading" => trans("Loading the current assignment…"),
    "current" => trans("Current assignment loaded."),
    "view" => trans("Review term bill"),
    "due_on" => trans("Invoice due date"),
    "line" => trans("Pending line"),
    "quoted" => trans("Quoted amount"),
    "amount" => trans("Amount to invoice"),
    "exclude" => trans("Remove from this invoice"),
    "restore" => trans("Restore quoted amount"),
    "invoice_help" => trans(
        "Adjust or remove each pending line, then review the complete invoice. Zero records an "
         . "excluded charge. Nothing becomes payable until confirmation.",
    ),
    "issue" => trans("Review and issue invoice"),
    "invoices" => trans("Issued invoices"),
    "invoice" => trans("Student invoice"),
    "issued" => trans("Issued"),
    "reference" => trans("Invoice reference"),
    "total" => trans("Invoice total"),
    "pending_total" => trans("Pending total · Not yet payable"),
    "credits" => trans("Registered academic credits"),
    "reprice" => trans("Review current schedule repricing"),
    "reprice_help" => trans(
        "Use the current assignment and publication for this unissued term bill. Accepted "
         . "registration quantities stay unchanged. Issued terms retain their original pricing.",
    ),
    "adjustment" => trans(
        "Invoiced term tuition exceeds the current quote. Review the original account charge "
         . "for any permitted credit; no automatic tuition refund is inferred.",
    ),
    "open_account" => trans("Open student account"),
    "print" => trans("Print invoice"),
    "denied" => trans(
        "Tuition information is unavailable with your current access.",
    ),
    "pending_notice" => trans(
        "These are pending estimates. Staff must review and issue an invoice before the charges are payable.",
    ),
    "section_fee" => trans("Section fee"),
];
