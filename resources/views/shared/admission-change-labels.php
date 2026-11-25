<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    "title" => trans("Application correction requests"),
    "help" => trans(
        "Ask admissions to review your planned program or entry term. Sending a request does not "
        . "change your application or guarantee approval.",
    ),
    "staff_help" => trans(
        "Review the applicant’s request. Use the separate correction editor when a change is "
        . "appropriate, then reply and close this follow-up. Closing a follow-up does not change the "
        . "application or approve admission.",
    ),
    "loading" => trans("Loading application correction requests…"),
    "refresh" => trans("Refresh requests"),
    "active" => trans("Open requests"),
    "finished" => trans("Closed requests"),
    "pages" => trans("Application correction request pages"),
    "previous" => trans("Previous"),
    "next" => trans("Next"),
    "empty" => trans("No correction requests in this view."),
    "new" => trans("Request a correction"),
    "area" => trans("What needs attention?"),
    "program" => trans("Planned program"),
    "entry_term" => trans("Entry term"),
    "message" => trans("Describe the correction you would like"),
    "privacy" => trans(
        "Include only information needed to review this request. Do not include passwords, payment "
        . "details or supporting documents.",
    ),
    "send" => trans("Send request"),
    "closed_application" => trans(
        "New correction requests are available while your application awaits an admissions decision. "
        . "Contact admissions for other changes.",
    ),
    "requested" => trans("Awaiting admissions review"),
    "closed" => trans("Closed by admissions"),
    "cancelled" => trans("Cancelled by applicant"),
    "created" => trans("Requested (UTC)"),
    "resolved" => trans("Resolved (UTC)"),
    "changed" => trans(
        "The application has changed since this request. Review its current program and term before responding.",
    ),
    "owner_changed" => trans(
        "The linked account has changed. This reply stays with the original request and will not "
        . "create an update for a different account.",
    ),
    "reply" => trans("Reply visible to the applicant"),
    "reply_help" => trans(
        "Explain what was changed or why a change could not be made. This message is visible to the applicant.",
    ),
    "close" => trans("Reply and close follow-up"),
    "cancel" => trans("Cancel request"),
    "cancel_reason" => trans(
        "The applicant cancelled this correction request.",
    ),
    "correct_program" => trans("Review program correction"),
    "correct_entry" => trans("Review entry-term correction"),
    "retained" => trans(
        "Your reply draft is kept while you use the correction editor. Return to the application to "
        . "finish this follow-up.",
    ),
    "saving" => trans("Saving follow-up…"),
    "saved" => trans("Follow-up saved."),
    "failed" => trans(
        "The action could not be confirmed. Your captured action and draft are still here. Retry the "
        . "same action or discard it before making another change.",
    ),
    "load_failed" => trans(
        "Requests could not be refreshed. Any previous results and unsent drafts are still shown.",
    ),
    "denied" => trans(
        "These requests are no longer available with your current access. Return to the application "
        . "to review available actions.",
    ),
    "retry" => trans("Retry captured action"),
    "discard" => trans("Discard captured action"),
    "discard_help" => trans(
        "Discard the local captured action? A previously accepted action stays saved. Refresh "
        . "requests to review its current status.",
    ),
    "leave" => trans(
        "Leave unfinished correction follow-ups? Unsent messages and captured actions will be discarded.",
    ),
    "busy" => trans("Wait for the current request to finish."),
    "queue_pending" => trans("Applicant correction requests"),
    "case_missing" => trans("Staff checklist not started"),
    "case_done" => trans("Staff casework complete"),
    "queue_help" => trans(
        "Open applicant correction requests remain visible even when staff casework is complete or "
        . "the application is withdrawn. Assignment and due-date filters use staff casework.",
    ),
];
