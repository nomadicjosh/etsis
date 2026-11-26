<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'since_start' => trans_html('Require completion on or after pursuit start'),
    'since_start_help' => trans_html(
        'Use the actual completion date and recorded start of the selected program pursuit. '
        . 'This restricts this group only; awarded credit and GPA stay unchanged.'
    ),
    'since_start_rule' => trans_html('Actual completion must be on or after the recorded pursuit start.'),
    'since_start_date' => trans_html('Recorded pursuit start: {date} (inclusive).'),
    'start_unknown' => trans_html('The recorded pursuit start is unavailable; registrar review is required.'),
    'before_start' => trans_html('Completed before the recorded pursuit start required by this group.'),
    'start_review' => trans_html('Review the recorded pursuit start before evaluating this group’s completion rule.'),
];
