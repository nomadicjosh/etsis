<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    "recorded" => trans("Decision recorded at"),
    "connector_help" => trans(
        "Managed course access, course creation and reviewed grade intake use connector " .
        "functions. Enable the features and dedicated service permissions required by your " .
        "institution."
    ),
    "open_correction" => trans("Review grade-correction request"),
    "source_unavailable" => trans(
        "The SIS enrollment, academic history or approved Moodle person link needs review. No" .
        " Moodle grade was requested.",
    ),
    "title" => trans("Moodle grade intake"),
    "help" => trans(
        "Collect course totals in the background, then review each SIS grade. Moodle results " .
        "never publish SIS grades automatically.",
    ),
    "notices" => trans("Moodle grade intake"),
    "open" => trans("Review imported grades"),
    "source" => trans("Captured Moodle course total"),
    "total" => trans("Course total"),
    "scale" => trans("Original minimum and maximum"),
    "observed" => trans("Observed at"),
    "student" => trans("Student"),
    "institutional_id" => trans("Institutional ID"),
    "reason" => trans("Evaluation reason"),
    "effective" => trans("Effective date"),
    "grade" => trans("Published SIS grade"),
    "pursuit" => trans("Grading pursuit"),
    "queue" => trans("Start background intake"),
    "refresh" => trans("Refresh intake status"),
    "cancel" => trans("Cancel remaining intake"),
    "queue_help" => trans(
        "The server processes existing active registrations independently of this browser. " .
        "You will receive a private completion notification. Audits, CEUs and unverifiable " .
        "sources are excluded.",
    ),
    "disabled" => trans(
        "Reviewed Moodle final-grade intake is disabled. Enable the institutional integration" .
        " and reviewed_final mode first.",
    ),
    "state" => trans("Intake status"),
    "processed" => trans("Registrations checked"),
    "ready" => trans("Results collected for review"),
    "empty" => trans("No imported results are available for this section."),
    "more" => trans("More imported results"),
    "record" => trans("View imported result"),
    "back_record" => trans("Back to record"),
    "back_results" => trans("Back to imported results"),
    "accept" => trans("Review SIS grade"),
    "dismiss" => trans("Review dismissal"),
    "review" => trans("Review imported grade decision"),
    "confirm" => trans("Confirm grade decision"),
    "revise" => trans("Revise grade decision"),
    "saved" => trans("Imported grade decision recorded."),
    "queued" => trans("Queued"),
    "running" => trans("Processing on the server"),
    "complete" => trans("Intake finished"),
    "failed" => trans("Intake needs review"),
    "cancelled" => trans("Intake cancelled"),
    "pending" => trans("Ready for review"),
    "unavailable" => trans("No released numeric course total"),
    "accepted" => trans("SIS grade recorded"),
    "requested" => trans("Grade-change approval requested"),
    "dismissed" => trans("Dismissed by staff"),
    "superseded" => trans("Superseded by a later observation"),
    "stale" => trans(
        "The SIS source changed after collection. Run intake again to refresh this pending " .
        "result, or dismiss it with a reason.",
    ),
    "review_help" => trans(
        "Compare the captured course total and its original scale with the published SIS " .
        "grade. Select the grade explicitly and record why. This does not copy Moodle " .
        "assignment scores or recalculate either gradebook.",
    ),
    "approval" => trans(
        "Institution policy requires independent approval of this grade correction. " .
        "Confirmation will request that approval and preserve the current grade.",
    ),
    "none" => trans("No grade recorded"),
    "before" => trans("Current SIS grade"),
    "after" => trans("Proposed SIS grade"),
    "hidden" => trans("Moodle course total is hidden"),
    "ungraded" => trans("No course total recorded in Moodle"),
    "unsupported" => trans(
        "This Moodle total needs recalculation or uses an unsupported scale",
    ),
    "issue" => trans(
        "Some sources need setup or verification. Review their person links, active academic " .
        "enrollment and published section before starting a fresh intake.",
    ),
    "notification_complete" => trans("Moodle grade intake finished"),
    "notification_failed" => trans("Moodle grade intake needs attention"),
    "notification_cancelled" => trans("Moodle grade intake cancelled"),
];
