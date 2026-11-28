<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    "activity" => trans_html("Import activity"),
    "cancelled" => trans_html("Cancelled"),
    "cancel" => trans_html("Cancel remaining import steps"),
    "cancelled_help" => trans_html("Remaining import steps were cancelled. Completed records have not been changed."),
    "cancel_help" => trans_html(
        "Completed records remain. Cancellation stops future steps after any current transaction finishes."
    ),
    "people" => trans_html("Import people"),
    "setup" => trans_html("New-person intake"),
    "help" => trans_html(
        "Upload a UTF-8 CSV up to 2 MB with up to 5,000 people. Each new person gets a linked account " .
        "awaiting private password setup. Existing emails must be corrected; this import never merges " .
        "people or assigns student or employment status.",
    ),
    "server" => trans_html(
        "Imports run on the server. You can close this browser after queuing. A private notification " .
        "appears when processing finishes.",
    ),
    "file" => trans_html("CSV file"),
    "delimiter" => trans_html("Columns separated by"),
    "comma" => trans_html("Comma"),
    "semicolon" => trans_html("Semicolon"),
    "tab" => trans_html("Tab"),
    "reason" => trans_html("Reason for creating these people"),
    "inspect" => trans_html("Read columns"),
    "map" => trans_html("Match person fields"),
    "given_name" => trans_html("Given name"),
    "family_name" => trans_html("Family name"),
    "email" => trans_html("Email"),
    "choose" => trans_html("Choose a column"),
    "review" => trans_html("Review people"),
    "queue" => trans_html("Queue import"),
    "back" => trans_html("Back to setup"),
    "back_activity" => trans_html("Back to import activity"),
    "loading" => trans_html("Loading import activity…"),
    "refresh" => trans_html("Refresh"),
    "previous" => trans_html("Previous"),
    "next" => trans_html("Next"),
    "empty" => trans_html("No imports have been queued by this account."),
    "rows" => trans_html("Rows"),
    "destination" => trans_html("Destination"),
    "state" => trans_html("Status"),
    "processed" => trans_html("Processed"),
    "created" => trans_html("Created"),
    "linked" => trans_html("Linked"),
    "skipped" => trans_html("Previously imported"),
    "omitted" => trans_html("Left out"),
    "queued" => trans_html("Queued"),
    "running" => trans_html("Processing"),
    "completed" => trans_html("Completed"),
    "needs_attention" => trans_html("Needs attention"),
    "details" => trans_html("View progress"),
    "progress" => trans_html("Import progress"),
    "record" => trans_html("Record"),
    "name" => trans_html("Name"),
    "issues" => trans_html("Review"),
    "ready" => trans_html("Ready to create"),
    "correct" => trans_html(
        "Correct the highlighted rows in your file and review it again.",
    ),
    "queued_help" => trans_html(
        "Your import is queued on the server. You can leave this screen. Check Import activity or " .
        "your notification drawer for completion.",
    ),
    "stopped" => trans_html(
        "Processing stopped. The first {processed} of {total} data records were processed. No later " .
        "records were imported. Resolve the remaining records before reviewing a new file; do not " .
        "repeat already processed people.",
    ),
    "permission" => trans_html(
        "The submitting account no longer has the required access.",
    ),
    "conflict" => trans_html(
        "Records changed after the review. Review the remaining records using their current values.",
    ),
    "expired" => trans_html("The server processing window expired."),
    "source_unavailable" => trans_html(
        "The private source could not be verified.",
    ),
    "processing_error" => trans_html(
        "The server could not finish this batch. Review the remaining records and server configuration.",
    ),
    "notices" => trans_html("Import updates"),
    "notice_help" => trans_html(
        "Completed and stopped imports appear here. Deleting a notice permanently removes it; the " .
        "import result remains in Import activity.",
    ),
    "include" => trans_html("Show read updates"),
    "mark" => trans_html("Mark as read"),
    "delete" => trans_html("Delete notification"),
    "notice_empty" => trans_html("There are no matching import updates."),
    "loaded" => trans_html("Import updates loaded."),
    "failed" => trans_html(
        "Import updates could not load. Use Refresh to try again.",
    ),
    "queued_summary" => trans_html(
        "Rows queued for server processing: {total}. Completion will appear in your notification drawer.",
    ),
    "large" => trans_html("Choose a UTF-8 CSV up to 2 MB."),
    "count" => trans_html("Showing {from}–{to} of {total} records."),
    "sample" => trans_html("Sample people CSV"),
    "catalog_queue" => trans_html("Queue catalog import"),
    "pages" => trans_html("Import pages"),
    "notices_pages" => trans_html("Import notification pages"),
];
