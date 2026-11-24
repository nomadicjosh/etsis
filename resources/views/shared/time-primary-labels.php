<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'setting' => trans_html('Require a current primary position before time approval'),
    'setting_help' => trans_html(
        'Pending work can still be submitted. HR must record or reaffirm a primary appointment covering today '
        . 'before approval. This does not change the position worked, compensation or earlier decisions.',
    ),
    'rule' => trans_html('Primary position for approval'),
    'required' => trans_html('Current primary designation required'),
    'optional' => trans_html('Primary designation is optional'),
    'unrecorded_rule' => trans_html('This earlier policy did not record a primary-position rule.'),
    'check' => trans_html('Primary position check'),
    'context' => trans_html(
        'This check concerns the designation at review and confirmation. '
        . 'It does not establish a primary position on the earlier work date.',
    ),
    'checked_on' => trans_html('Checked on {date}'),
    'current' => trans_html('Current at this review'),
    'unrecorded' => trans_html('No primary designation has been recorded'),
    'cleared' => trans_html('The primary designation was explicitly cleared'),
    'changed' => trans_html('The designated appointment changed and needs reaffirmation'),
    'ended' => trans_html('The designated appointment has ended'),
    'voided' => trans_html('The designated appointment was marked incorrect'),
    'scheduled' => trans_html('The designated appointment has not started'),
    'not_assessed' => trans_html('The primary designation was not assessed for this rejection'),
    'blocked' => trans_html(
        'Approval is unavailable until HR records or reaffirms a current primary position. '
        . 'The time-exception duty cannot bypass this requirement. The entry can still be rejected.',
    ),
    'open' => trans_html('Review primary position'),
    'back_time' => trans_html('Back to time entry'),
    'no_time_flags' => trans_html('No configured clocked-time exceptions found.'),
];
