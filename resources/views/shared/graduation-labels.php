<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    "title" => trans("Graduation & credentials"),
    "queue" => trans("Group conferral queue"),
    "back" => trans("Back to record"),
    "back_queue" => trans("Back to conferral queue"),
    "loading" => trans("Loading graduation record…"),
    "empty" => trans("No credential has been conferred for this pursuit."),
    "help" => trans(
        "Eligibility records the reviewed academic evidence and institutional clearance. Conferral "
        . "is a separate authorized decision and closes only this pursuit."
    ),
    "eligible" => trans("Eligible for conferral"),
    "deferred" => trans("Eligibility incomplete"),
    "review_eligibility" => trans("Review eligibility"),
    "confer" => trans("Review conferral"),
    "correct" => trans("Correct title or honors"),
    "revoke" => trans("Revoke credential"),
    "history" => trans("Credential decision history"),
    "more" => trans("More decisions"),
    "credential_title" => trans("Credential title"),
    "awarded_on" => trans("Graduation date"),
    "term" => trans("Graduation term"),
    "honors" => trans("Honors (optional)"),
    "reason" => trans("Reason and supporting evidence"),
    "attested" => trans(
        "I verified the institution’s other graduation requirements and release clearances.",
    ),
    "history_acknowledged" => trans(
        "I reviewed any earlier-history gaps and verified the supporting records.",
    ),
    "academic_help" => trans(
        "All supported academic minimums and curriculum components must be satisfied. An "
        . "attestation cannot override a failed or unknown academic check."
    ),
    "date_help" => trans(
        "Enter the actual graduation date, on or before today. Earned work needs verified "
        . "completion dates. The academic review uses this date for recency checks."
    ),
    "review" => trans("Review decision"),
    "confirm" => trans("Confirm decision"),
    "revise" => trans("Revise review"),
    "saved" => trans("Graduation decision recorded."),
    "close_help" => trans(
        "Conferral closes this pursuit automatically. The student, account and other pursuits "
        . "remain available."
    ),
    "revoke_help" => trans(
        "Revocation retains the original award and its history. This does not reopen the pursuit or "
        . "change earned course credits."
    ),
    "correct_help" => trans(
        "Correct only the credential title or honors here. A change to graduation date, term or "
        . "curriculum requires revocation, a fresh eligibility review and a new conferral."
    ),
    "history_empty" => trans(
        "No graduation decisions have been recorded. A closed pursuit alone does not prove graduation.",
    ),
    "queue_help" => trans(
        "Choose up to ten candidates with recorded eligibility. Every selected candidate is "
        . "rechecked before the group is confirmed. If any review is stale, the entire selection "
        . "remains unconfirmed."
    ),
    "queue_empty" => trans(
        "No recorded eligible candidates are waiting for conferral. Review eligibility from each "
        . "student’s program record first."
    ),
    "select" => trans("Select candidate"),
    "review_group" => trans("Review selected conferrals"),
    "confirm_group" => trans("Confer selected group"),
    "group_saved" => trans(
        "The selected credentials were conferred and their pursuits closed.",
    ),
    "next" => trans("Next candidates"),
    "previous" => trans("Previous candidates"),
    "selected" => trans("Selected candidates"),
    "details" => trans("Credential details"),
    "eligibility" => trans("Eligibility decision"),
    "conferred" => trans("Credential conferred"),
    "corrected" => trans("Credential corrected"),
    "revoked" => trans("Credential revoked"),
    "recorded" => trans("Recorded"),
    "program" => trans("Program"),
    "components" => trans("Retained curriculum"),
    "requirements" => trans("Academic requirements"),
    "eligible_summary" => trans(
        "The retained curriculum allocation and all supported academic minimums are satisfied.",
    ),
    "deferred_summary" => trans(
        "Some graduation checks remain incomplete. Review the degree progress details before "
        . "recording a new eligibility decision."
    ),
    "choose" => trans("Choose at least one candidate."),
    "transfer_required" => trans(
        "This coursework belongs to a graduated pursuit. Formal transfer evaluation into the "
        . "receiving pursuit is required; ordinary reuse approval cannot make it count."
    ),
    "current" => trans("Current credential"),
    "history_note" => trans(
        "Original awards remain in history after correction or revocation. Protected reasons and "
        . "raw event details are excluded."
    ),
];
