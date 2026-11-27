<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    "student" => trans("Student"),
    "institutional_id" => trans("Institutional ID"),
    "gpa_treatment" => trans("Grade policy for GPA"),
    "change_term" => trans("Correct posting term"),
    "posting_term_changed" => trans("Receiving posting term changed"),
    "term_review" => trans("Review posting term"),
    "term_confirm" => trans("Confirm posting term"),
    "term_help" => trans(
        "The receiving posting term is independent of the original completion date. This " .
            "correction preserves the source coursework and its dates.",
    ),
    "title" => trans("Same-institution transfer evaluation"),
    "help" => trans(
        "Evaluate institutionally earned academic coursework from a genuinely conferred degree " .
            "for this receiving pursuit. The original coursework and credential remain unchanged.",
    ),
    "evaluate" => trans("Evaluate completed-degree work"),
    "candidates" => trans("Available completed-degree coursework"),
    "empty" => trans(
        "No eligible, unevaluated institutional coursework is available for this pursuit.",
    ),
    "no_awards" => trans(
        "No same-institution transfer evaluations are recorded for this pursuit.",
    ),
    "source" => trans("Original coursework and credential"),
    "receiving" => trans("Receiving pursuit"),
    "course" => trans("Receiving course"),
    "publication" => trans("Published receiving definition"),
    "posting_term" => trans("Posting term"),
    "credits" => trans("Academic credits to accept"),
    "grade" => trans("Receiving grade"),
    "source_grade" => trans("Original grade"),
    "gpa_included" => trans("GPA-eligible grade"),
    "gpa_excluded" => trans("Grade excludes GPA"),
    "source_help" => trans(
        "The accepted amount cannot exceed the earned source or the published receiving " .
            "maximum. CEUs and audited work are excluded. Completion remains the original verified " .
            "date.",
    ),
    "original_credits" => trans("Originally earned credits"),
    "completed" => trans("Verified completion"),
    "credential" => trans("Source credential"),
    "graduated" => trans("Conferral date"),
    "source_program" => trans("Source program"),
    "search" => trans("Find receiving course"),
    "find" => trans("Search courses"),
    "choose" => trans("Select course"),
    "more_courses" => trans("More courses"),
    "more_publications" => trans("More publications"),
    "no_publications" => trans(
        "Publish an academic receiving-course definition before evaluating this course.",
    ),
    "reason" => trans("Evaluation reason"),
    "review" => trans("Review evaluation"),
    "confirm" => trans("Confirm evaluation"),
    "edit" => trans("Revise evaluation"),
    "back_record" => trans("Back to record"),
    "back_list" => trans("Back to evaluations"),
    "back_candidates" => trans("Back to available coursework"),
    "open" => trans("View evaluation"),
    "more" => trans("Load more evaluations"),
    "more_candidates" => trans("More available coursework"),
    "received" => trans("Accepted receiving credit"),
    "voided" => trans("Voided evaluation"),
    "amend" => trans("Correct receiving units or grade"),
    "void" => trans("Void receiving award"),
    "reinstate" => trans("Reinstate receiving award"),
    "replace_course" => trans("Correct receiving course"),
    "grant" => trans("Receiving award established"),
    "history" => trans("Receiving decision history"),
    "earlier" => trans("More decisions"),
    "course_history" => trans("Receiving course publication changed"),
    "saved" => trans("Receiving decision recorded."),
    "effective" => trans("Effective date"),
    "course_review" => trans("Review receiving course"),
    "course_confirm" => trans("Confirm receiving course"),
    "changed" => trans(
        "Source evidence or receiving details may have changed. Review the current records again.",
    ),
    "review_help" => trans(
        "Review the original evidence and the receiving decision before confirming. This " .
            "decision applies only to the selected receiving pursuit.",
    ),
    "void_help" => trans(
        "Voiding removes the current credits and GPA contribution of this receiving award. It " .
            "preserves the original coursework, credential and all earlier receiving decisions.",
    ),
    "closed" => trans(
        "This pursuit is closed. Existing evaluations remain available for authorized " .
            "corrections; new awards require an active receiving pursuit.",
    ),
    "no_courses" => trans("No matching courses were found."),
    "date_help" => trans(
        "This is the effective date of the receiving-course correction; the original completion" .
            " date remains unchanged.",
    ),
    "pending" => trans(
        "Select a published definition and the receiving academic details.",
    ),
    "choice_help" => trans(
        "The published receiving grade controls its points and GPA eligibility. Degree rules " .
            "may still exclude transfer GPA. A passing source " .
            "grade does not silently select a receiving grade.",
    ),
];
