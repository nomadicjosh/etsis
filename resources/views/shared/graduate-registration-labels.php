<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    "policy" => trans("Undergraduate access to graduate courses"),
    "eyebrow" => trans("REGISTRATION POLICY"),
    "help" => trans(
        // phpcs:ignore Generic.Files.LineLength -- Keep this complete translated message together.
        "At registration, an undergraduate must meet the current pursuit GPA minimum and receive both assigned-advisor approval and a separate registrar override. Neither approval waives academic minimums. Later GPA changes do not remove earned credit.",
    ),
    "enabled" => trans("Require graduate-course eligibility and approvals"),
    "undergraduate" => trans("Undergraduate academic level"),
    "graduate" => trans("Graduate academic level"),
    "levels_help" => trans(
        // phpcs:ignore Generic.Files.LineLength -- Keep this complete translated message together.
        "Levels come from published course and assigned program definitions. Program names do not determine academic level.",
    ),
    "level_search" => trans("Find academic levels"),
    "choose" => trans("Choose a level"),
    "more" => trans("More academic levels"),
    "find" => trans("Find levels"),
    "gpa" => trans("Minimum current undergraduate pursuit GPA"),
    "gpa_help" => trans(
        // phpcs:ignore Generic.Files.LineLength -- Keep this complete translated message together.
        "The default is 2.000. Registration uses verified institutional cumulative GPA for the current pursuit. An undefined GPA requires registrar review.",
    ),
    "grade" => trans("Minimum grade points in every completed graduate course"),
    "grade_help" => trans(
        // phpcs:ignore Generic.Files.LineLength -- Keep this complete translated message together.
        "Optional. Leave blank to omit this additional rule. Every completed institutional graduate course in this pursuit must meet the minimum for further graduate enrollment. No earlier graduate course is required for the first enrollment.",
    ),
    "reason" => trans("Reason for this decision"),
    "review" => trans("Review policy"),
    "publish" => trans("Publish policy"),
    "review_title" => trans("Review graduate-course policy"),
    "published" => trans("Graduate-course registration policy published."),
    "loading" => trans("Loading graduate-course eligibility…"),
    "back" => trans("Back to record"),
    "edit" => trans("Back to policy"),
    "reload" => trans("Reload current policy"),
    "disabled" => trans("Disabled"),
    "active" => trans("Required"),
    "none" => trans("No additional grade minimum"),
    "denied" => trans("This information is no longer available to this account."),
    "pending" => trans("A decision is still being saved. Wait for completion, then refresh this record."),
    "approvals" => trans("Graduate-course approvals"),
    "approval_help" => trans(
        // phpcs:ignore Generic.Files.LineLength -- Keep this complete translated message together.
        "Review academic eligibility first. The assigned advisor approves the course, then the registrar records the override. A changed policy, assigned advisor, course, offering or program cohort requires fresh approvals.",
    ),
    "advisor_approve" => trans("Approve as assigned advisor"),
    "advisor_revoke" => trans("Revoke advisor approval"),
    "registrar_override" => trans("Authorize registrar override"),
    "registrar_revoke" => trans("Revoke registrar override"),
    "review_decision" => trans("Review decision"),
    "confirm" => trans("Confirm decision"),
    "decision_review" => trans("Review graduate-course decision"),
    "recorded" => trans("Graduate-course decision recorded. Refresh registration eligibility to continue."),
    "refresh" => trans("Refresh eligibility"),
    "not_required" => trans("This registration does not require undergraduate graduate-course approval."),
    "find_sections" => trans("Find sections"),
    "more_sections" => trans("More sections"),
    "no_sections" => trans("No sections match this search."),
    "choose_section" => trans("Choose a graduate course section"),
    "open_approvals" => trans("Review graduate-course approvals"),
];
