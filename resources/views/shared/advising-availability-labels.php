<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'policy' => trans_html('Current booking limits'),
    'policy_maximum' => trans_html('Maximum active appointments per program pursuit'),
    'policy_notice' => trans_html('Minimum advance notice in minutes'),
    'policy_horizon' => trans_html('Booking horizon in calendar days'),
    'capacity_reached' => trans_html(
        'The active-appointment limit has been reached. Resolve existing appointments or contact the advising office.'
    ),
    'title' => trans_html('Advisor availability'),
    'mine' => trans_html('Manage my availability'),
    'intro' => trans_html('Offer dated times for assigned students to book. Closing availability stops '
        . 'new bookings and preserves confirmed appointments.'),
    'add' => trans_html('Add availability'),
    'start' => trans_html('Availability starts at'),
    'end' => trans_html('Availability ends at'),
    'minutes' => trans_html('Minutes per appointment'),
    'instructions' => trans_html('Meeting instructions visible to students'),
    'save' => trans_html('Save availability'),
    'close' => trans_html('Close availability'),
    'reason' => trans_html('Reason for closing availability'),
    'confirm_close' => trans_html('Confirm closure'),
    'close_help' => trans_html('Confirmed appointments remain scheduled. Review and cancel those appointments '
        . 'separately if necessary.'),
    'limits' => trans_html('Use a future same-day window of up to four hours, with equal slots of 5–120 '
        . 'minutes. Times use the institution timezone. Choose times outside clock '
        . 'changes.'),
    'empty' => trans_html('No availability on this page.'),
    'refresh' => trans_html('Refresh availability'),
    'finished' => trans_html('Finished availability'),
    'upcoming' => trans_html('Upcoming availability'),
    'more' => trans_html('More availability'),
    'previous' => trans_html('Previous availability'),
    'back' => trans_html('Back to appointments'),
    'back_advisors' => trans_html('Back to advisor search'),
    'find' => trans_html('Find an advisor'),
    'search' => trans_html('Search advisors'),
    'more_advisors' => trans_html('More advisors'),
    'previous_advisors' => trans_html('Previous advisors'),
    'choose' => trans_html('Manage availability'),
    'choose_host' => trans_html('Assigned advisor'),
    'book' => trans_html('Book an available time'),
    'booking_intro' => trans_html('Choose an assigned advisor and date, then select an offered time. Times are '
        . 'checked again when you confirm; they are not reserved while you browse.'),
    'date' => trans_html('Availability date'),
    'find_windows' => trans_html('Find availability'),
    'choose_window' => trans_html('View available times'),
    'choose_slot' => trans_html('Choose this time'),
    'confirm_book' => trans_html('Confirm booking'),
    'keep' => trans_html('Keep choosing'),
    'no_hosts' => trans_html('No eligible advisors are assigned for self-booking. Contact the advising office.'),
    'no_windows' => trans_html('No offered availability for this advisor and date.'),
    'no_slots' => trans_html('No free future times remain in this window. Choose another window or date.'),
    'saved' => trans_html('Availability saved.'),
    'closed' => trans_html('Availability closed. Existing appointments remain unchanged.'),
    'booked' => trans_html('Your appointment is booked. Review it in Advising appointments or My timetable.'),
    'error' => trans_html('Unable to complete this action. Your draft remains here. Refresh the current '
        . 'source and review it before trying again.'),
    'denied' => trans_html(
        'Advising availability access is unavailable. Reopen the program after your access is restored.'
    ),
    'timezone' => trans_html('Institution timezone'),
    'expired' => trans_html('Expired availability'),
    'recurring' => trans_html('Recurring availability'),
    'recurring_intro' => trans_html(
        'Offer a finite weekly pattern as explicit dated windows. Review every window before confirming.'
    ),
    'add_recurring' => trans_html('Add recurring availability'),
    'refresh_recurring' => trans_html('Refresh recurring offers'),
    'back_availability' => trans_html('Back to availability'),
    'back_recurring' => trans_html('Back to recurring offers'),
    'view_recurring' => trans_html('View recurring offer'),
    'window_count' => trans_html('Dated windows'),
    'no_recurring' => trans_html('No recurring offers on this page.'),
    'pattern_help' => trans_html('Choose inclusive dates and weekdays. Repeats count from the Monday of the first '
        . 'selected week. Offer at most twenty windows together; each uses the same '
        . 'instructions and equal appointment slots.'),
    'from' => trans_html('First pattern date'),
    'through' => trans_html('Last pattern date'),
    'start_time' => trans_html('Daily availability starts at'),
    'end_time' => trans_html('Daily availability ends at'),
    'choose_days' => trans_html('Availability weekdays'),
    'every_weeks' => trans_html('Repeat every number of weeks'),
    'excluded_dates' => trans_html('Skipped availability dates'),
    'excluded_help' => trans_html('Optional: one ISO date per line, such as 2026-12-25. Each date must be a selected '
        . 'occurrence within the pattern.'),
    'weekdays' => [trans_html('Monday'), trans_html('Tuesday'), trans_html('Wednesday'), trans_html('Thursday'),
        trans_html('Friday'), trans_html('Saturday'), trans_html('Sunday')],
    'review_pattern' => trans_html('Review dated windows'),
    'draft_changed' => trans_html('Your draft changed while the review loaded. Review the current draft again.'),
    'confirm_help' => trans_html('All listed windows are offered together. Conflicts, limits and current access are '
        . 'checked again at confirmation. This does not book any appointments.'),
    'confirm_offer' => trans_html('Confirm recurring offer'),
    'keep_editing' => trans_html('Keep editing'),
    'recurring_saved' => trans_html('Recurring availability offered.'),
    'review_remaining' => trans_html('Review remaining-window closure'),
    'confirm_remaining' => trans_html('Close listed windows'),
    'no_remaining' => trans_html('No open unexpired windows remain for series closure. Earlier individual closures '
        . 'and expired windows retain their records.'),
    'series_status' => ['open' => trans_html('Offered'), 'closed' => trans_html('Remaining windows closed')],
    'status' => ['open' => trans_html('Open for booking'), 'closed' => trans_html('Closed to new bookings')],
];
