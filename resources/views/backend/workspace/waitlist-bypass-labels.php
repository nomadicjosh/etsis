<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Waitlist priority bypass'),
    'open' => trans_html('Review priority bypass'),
    'reason_label' => trans_html('Institutional reason for this bypass'),
    'review' => trans_html('Review bypass and registration'),
    'confirm' => trans_html('Confirm bypass and register'),
    'revise' => trans_html('Revise reason'),
    'refresh' => trans_html('Refresh bypass review'),
    'back_queue' => trans_html('Back to queue'),
    'success' => trans_html('Registration completed with the reviewed waitlist bypass.'),
    'help' => trans_html(
        'Register this later request ahead of earlier waiting requests for a documented institutional reason. '
        . 'Earlier requests keep their places. Normal registration eligibility, capacity and charges still apply.',
    ),
    'review_help' => trans_html(
        'Check the selected student, section, charges and reason. This review expires after twenty minutes '
        . 'and reserves no seat. A changed queue, registration source or price requires a fresh review.',
    ),
    'student' => trans_html('Student (current)'),
    'section' => trans_html('Section'),
    'course' => trans_html('Course'),
    'credits' => trans_html('Academic credits'),
    'earlier' => trans_html('Earlier waiting requests'),
    'charges' => trans_html('Registration charges'),
    'new_charge' => trans_html('New charge'),
    'not_due' => trans_html('Already assessed; no new charge'),
    'seats' => trans_html('Available seats'),
    'total' => trans_html('Total new charges'),
    'reason' => trans_html('Reason'),
    'checks' => trans_html('Registration checks'),
    'priority_notice' => trans_html('This review explicitly requests an exception to waitlist priority.'),
    'blocked' => trans_html('Resolve the listed registration, seat or pricing issues, then refresh this review.'),
    'draft_changed' => trans_html('Your reason changed while the review loaded. Review your latest text again.'),
    'unavailable' => trans_html('Unavailable'),
    'history' => trans_html('Bypass decision history'),
    'refresh_history' => trans_html('Refresh bypass history'),
    'loading_history' => trans_html('Loading recorded bypass decisions…'),
    'empty_history' => trans_html('No waitlist bypass decisions are recorded for this section.'),
    'history_help' => trans_html(
        'Recorded reasons and registration references remain unchanged after later queue changes or drops. '
        . 'Student names and institutional IDs below are current. '
        . 'Access to this history does not grant profile access.',
    ),
    'recorded_at' => trans_html('Recorded decision time'),
    'actor' => trans_html('Staff account reference'),
    'enrollment' => trans_html('Accepted registration reference'),
    'older' => trans_html('Older bypass decisions'),
    'newer' => trans_html('Newer bypass decisions'),
];
