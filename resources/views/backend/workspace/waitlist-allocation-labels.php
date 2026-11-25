<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Register oldest waiting requests'),
    'count' => trans_html('Number of oldest requests'),
    'help' => trans_html(
        'Choose consecutive requests from the start of this queue, up to the available seats and this page. '
        . 'Review eligibility and fees before confirming. One failure rolls back the entire group; '
        . 'later requests are not skipped into a place.'
    ),
    'review' => trans_html('Review oldest requests together'),
    'back' => trans_html('Back to waitlist'),
    'review_help' => trans_html(
        'Waiting students are processed in accepted queue order. '
        . 'Every earlier request must be included before a later request can register in this group.'
    ),
    'access_changed' => trans_html('Access to this registration group changed. Reopen the task after checking access.'),
];
