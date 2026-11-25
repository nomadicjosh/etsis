<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'policy' => trans_html('Current booking limits'),
    'applied_policy' => trans_html('Booking limits used for this change'),
    'policy_maximum' => trans_html('Maximum active appointments per program pursuit'),
    'policy_notice' => trans_html('Minimum advance notice in minutes'),
    'policy_horizon' => trans_html('Booking horizon in calendar days'),
    'policy_classes' => trans_html('Published student class overlaps'),
    'policy_teaching' => trans_html('Published advisor teaching overlaps'),
    'class_allow' => trans_html('Allow overlaps'),
    'class_block' => trans_html('Block overlaps'),
    'class_help' => trans_html(
        'The enabled checks use published meetings only. '
        . 'Changes after confirmation do not automatically change this appointment.'
    ),
    'capacity_reached' => trans_html(
        'The active-appointment limit has been reached. Resolve existing appointments or contact the advising office.'
    ),
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
    'calendar' => trans_html('Appointment calendar'),
    'view_calendar' => trans_html('View appointment calendar'),
    'back_calendar' => trans_html('Back to appointment calendar'),
    'back_list' => trans_html('Back to appointment list'),
    'date' => trans_html('Advising date'),
    'view' => trans_html('Appointment calendar view'),
    'week' => trans_html('Week'),
    'month' => trans_html('Month'),
    'previous_period' => trans_html('Previous period'),
    'next_period' => trans_html('Next period'),
    'show_cancelled' => trans_html('Show cancelled appointments'),
    'refresh_calendar' => trans_html('Refresh appointment calendar'),
    'calendar_empty' => trans_html('No advising appointments in this period.'),
    'calendar_help' => trans_html('Dates and times use the institution timezone. '
        . 'Program names are current labels. Class meetings and advising appointments have separate calendars.'),
    'view_appointment' => trans_html('View appointment'),
    'calendar_failure' => trans_html('The appointment calendar is unavailable. Refresh to try again.'),
    'class_failure' => trans_html('The class timetable is unavailable. Reload this screen to try again.'),
    'class_loading' => trans_html('Loading class times…'),
    'calendar_loading' => trans_html('Loading advising appointments…'),
    'correct_held' => trans_html('Correct attendance to held'),
    'correct_no_show' => trans_html('Correct attendance to no-show'),
    'correction_help' => trans_html('Explain why the recorded attendance needs correction. '
        . 'The original outcome stays in Appointment changes. The reason is visible to the student.'),
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
        'no_show' => trans_html('Recorded no-show'),
        'correct_held' => trans_html('Attendance corrected to held'),
        'correct_no_show' => trans_html('Attendance corrected to no-show'),
    ],
];
