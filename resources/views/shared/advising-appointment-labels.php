<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Advising appointments'),
    'context' => trans_html('Student program'),
    'intro' => trans_html('Arrange a time with an assigned advisor. All appointment details and reasons '
        . 'are visible to the student. Recording attendance does not record a completed '
        . 'check-in or change academic eligibility.'),
    'schedule' => trans_html('Schedule an appointment'),
    'save' => trans_html('Save appointment'),
    'refresh' => trans_html('Refresh appointments'),
    'active' => trans_html('Scheduled appointments'),
    'finished' => trans_html('Finished appointments'),
    'previous' => trans_html('Previous appointments'),
    'more' => trans_html('More appointments'),
    'empty' => trans_html('No appointments on this page.'),
    'unavailable' => trans_html('Scheduling needs an active primary program and an eligible assigned advisor.'),
    'host' => trans_html('Assigned advisor'),
    'start' => trans_html('Starts at'),
    'end' => trans_html('Ends at'),
    'timezone' => trans_html('Institution timezone'),
    'message' => trans_html('Appointment details or reason visible to the student'),
    'limits' => trans_html('Use a future, same-day time of 5–240 minutes within 366 days. Include meeting '
        . 'instructions in the details. Only overlapping advising appointments are '
        . 'checked; review other commitments separately.'),
    'history' => trans_html('Appointment changes'),
    'reschedule' => trans_html('Reschedule appointment'),
    'cancel' => trans_html('Cancel appointment'),
    'held' => trans_html('Record as held'),
    'no_show' => trans_html('Record no-show'),
    'confirm' => trans_html('Save appointment change'),
    'keep' => trans_html('Keep appointment unchanged'),
    'back' => trans_html('Back to programs'),
    'saved' => trans_html('Appointment saved. Review the scheduled details below.'),
    'changed' => trans_html('Appointment change saved.'),
    'failure' => trans_html('Unable to save. Your draft remains here. Refresh and review the current '
        . 'appointment before trying again.'),
    'denied' => trans_html('Appointment access is unavailable. Reopen the program after your access is restored.'),
    'cancel_only' => trans_html(
        'Only cancellation is available here. Contact the advising office for further changes.'
    ),
    'reschedule_limit' => trans_html('After ten time changes, cancel and schedule a replacement if needed.'),
    'status' => [
        'scheduled' => trans_html('Scheduled'),
        'cancelled' => trans_html('Cancelled'),
        'held' => trans_html('Held'),
        'no_show' => trans_html('No-show')
    ],
    'actions' => [
        'schedule' => trans_html('Scheduled'),
        'reschedule' => trans_html('Rescheduled'),
        'cancel' => trans_html('Cancelled'),
        'held' => trans_html('Recorded as held'),
        'no_show' => trans_html('Recorded no-show')
    ],
];
