<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
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
    'status' => ['open' => trans_html('Open for booking'), 'closed' => trans_html('Closed to new bookings')],
];
