<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Appointment booking policy'),
    'intro' => trans_html(
        'Configure institution-wide limits for new advising appointment times. '
        . 'Existing appointments and original retries retain their accepted rules.'
    ),
    'maximum_active' => trans_html('Maximum active appointments per program pursuit'),
    'notice_minutes' => trans_html('Minimum advance notice in minutes'),
    'horizon_days' => trans_html('Booking horizon in calendar days'),
    'student_class_mode' => trans_html('Published student class overlaps'),
    'advisor_teaching_mode' => trans_html('Published advisor teaching overlaps'),
    'class_allow' => trans_html('Allow overlaps'),
    'class_block' => trans_html('Block overlaps'),
    'class_help' => trans_html(
        'When blocked, new advising times must avoid published meetings for the student’s active classes. '
        . 'Classes without a published schedule cannot be checked. Existing appointments remain unchanged.'
    ),
    'teaching_help' => trans_html(
        'When blocked, new advising times must avoid the advisor’s published teaching meetings. '
        . 'Existing appointments remain unchanged.'
    ),
    'ranges' => trans_html(
        'Use 1–20 active appointments, 0–43,200 notice minutes and a 1–366-day horizon. '
        . 'Notice must be shorter than the horizon.'
    ),
    'reason' => trans_html('Reason for this policy change'),
    'review' => trans_html('Review booking policy'),
    'confirm' => trans_html('Confirm policy change'),
    'keep' => trans_html('Keep editing'),
    'refresh' => trans_html('Refresh current policy'),
    'defaults' => trans_html('Use default values'),
    'default_info' => trans_html('Default booking limits apply until your institution saves a policy.'),
    'history' => trans_html('Booking policy history'),
    'back' => trans_html('Back to booking policy'),
    'before' => trans_html('Current rules'),
    'after' => trans_html('Proposed rules'),
    'revision' => trans_html('Policy revision'),
    'saved' => trans_html('Your policy change was accepted. Current rules are shown below.'),
    'changed' => trans_html('Your draft changed while the review loaded. Review the current draft again.'),
    'empty' => trans_html('No recorded booking policy changes on this page.'),
    'more' => trans_html('Older policy changes'),
    'previous' => trans_html('Newer policy changes'),
    'failure' => trans_html('Unable to complete the policy action. Your draft remains available.'),
    'denied' => trans_html('Booking policy access is unavailable. Reopen after your access is restored.'),
];
