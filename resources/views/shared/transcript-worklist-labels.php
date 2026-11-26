<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    "denied" => trans_html(
        "Transcript assignment access changed. Reopen the request after your office access is restored."
    ),
    "title" => trans_html("Work assignment"),
    "help" => trans_html(
        "Route this request to an eligible colleague. Assignment grants no access "
        . "and does not prepare or deliver a transcript."
    ),
    "scope" => trans_html("Assigned work"),
    "status" => trans_html("Request status"),
    "all" => trans_html("All"),
    "mine" => trans_html("My work"),
    "unassigned" => trans_html("Unassigned"),
    "active" => trans_html("Active requests"),
    "pending" => trans_html("Pending"),
    "ready" => trans_html("Prepared"),
    "finished" => trans_html("Finished requests"),
    "assigned" => trans_html("Assigned to"),
    "current" => trans_html("Current assignment"),
    "loading" => trans_html("Loading transcript assignment…"),
    "reload" => trans_html("Reload assignment"),
    "closed" => trans_html("Finished requests retain their assignment; they cannot be reassigned."),
    "selected" => trans_html("Assignment to save"),
    "claim" => trans_html("Assign to me"),
    "clear" => trans_html("Clear assignment"),
    "you" => trans_html("You"),
    "search" => trans_html("Search eligible colleagues"),
    "find" => trans_html("Search colleagues"),
    "searching" => trans_html("Searching eligible colleagues…"),
    "search_required" => trans_html("Enter at least two characters to find eligible colleagues."),
    "choose" => trans_html("Choose a colleague; save the assignment when ready."),
    "empty" => trans_html("No eligible colleagues match this search."),
    "previous" => trans_html("Previous colleagues"),
    "more" => trans_html("More colleagues"),
    "save" => trans_html("Save assignment"),
    "saving" => trans_html("Saving assignment…"),
    "saved" => trans_html("Transcript assignment saved."),
    "changed" => trans_html(
        "The request or assignment changed. Reload the assignment before saving; your selection is retained.",
    ),
    "failed" => trans_html("The assignment is unavailable. Reload it or reopen the request to try again."),
];
