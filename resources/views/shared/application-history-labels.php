<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Application history'),
    'open' => trans_html('Application history'),
    'open_for' => trans_html('Application history for {application}'),
    'close' => trans_html('Close application history'),
    'pages' => trans_html('Application history pages'),
    'entries' => trans_html('Recorded application changes'),
    'current_status' => trans_html('Current status: {status}'),
    'checked' => trans_html('Current details checked: {date}'),
    'original' => trans_html('Original submission'),
    'current' => trans_html('Current planned program and intake'),
    'original_unknown' => trans_html('Original submission details were not recorded.'),
    'earlier_unknown' => trans_html(
        'Earlier application history is unknown. A current observation does not establish when an '
        . 'earlier decision happened.'
    ),
    'snapshot_help' => trans_html(
        'These pages keep the history available when you opened this view. Refresh history to check '
        . 'for newer changes. Current labels describe today’s program and term; earlier entries keep '
        . 'their recorded labels.'
    ),
    'intake' => trans_html('Entry term: {term}'),
    'submitted_at' => trans_html('Submitted: {date}'),
    'utc' => trans_html('{date} UTC'),
    'unknown' => trans_html('Not recorded'),
    'program_change' => trans_html('{before} → {after}'),
    'before' => trans_html('Before: {term}'),
    'after' => trans_html('After: {term}'),
    'academic_year' => trans_html('Academic year: {year}'),
    'term_dates' => trans_html('{start} through {end}'),
    'effective' => trans_html('Effective: {date}'),
    'recorded' => trans_html('Recorded: {date}'),
    'observed' => trans_html('Status seen: {status}. Observed: {date}. Earlier decision timing is unknown.'),
    'event_submission' => trans_html('Application submitted'),
    'event_observation' => trans_html('Current application observed'),
    'event_decision_accepted' => trans_html('Application accepted'),
    'event_decision_rejected' => trans_html('Application not accepted'),
    'event_correction_accepted' => trans_html('Admission decision corrected to accepted'),
    'event_correction_rejected' => trans_html('Admission decision corrected to not accepted'),
    'event_program_correction' => trans_html('Planned program corrected'),
    'event_entry_correct' => trans_html('Planned intake corrected'),
    'event_entry_recheck' => trans_html('Corrected intake calendar reviewed'),
    'event_entry_defer' => trans_html('Entry deferred'),
    'event_entry_reconfirm' => trans_html('Updated intake approved'),
    'event_resubmission' => trans_html('Application answers resubmitted'),
    'event_withdrawal' => trans_html('Application withdrawn'),
    'event_review_resumed' => trans_html('Application returned to review'),
    'event_unknown' => trans_html('Recorded application change'),
    'empty' => trans_html('No further applicant-visible application changes are recorded on this page.'),
    'empty_page' => trans_html(
        'This page has no applicant-visible changes. More history is available on the next page.'
    ),
    'previous' => trans_html('Previous history'),
    'next' => trans_html('Next history'),
    'refresh' => trans_html('Refresh history'),
    'retry' => trans_html('Try history again'),
    'next_steps' => trans_html('Open next steps'),
    'limit' => trans_html('This view reached its history page limit. Contact admissions for help.'),
    'loading' => trans_html('Loading application history…'),
    'ready' => trans_html('Application history is ready.'),
    'failed' => trans_html(
        'History could not be loaded. Previously loaded details are still shown. Retry or refresh '
        . 'history.'
    ),
    'denied' => trans_html(
        'This application history is no longer available to your account. Close it and check your '
        . 'sign-in.'
    ),
    'student_services' => trans_html('My campus'),
    'records' => trans_html('Application record'),
];
