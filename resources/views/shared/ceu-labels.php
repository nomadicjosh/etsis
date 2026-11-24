<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'ceu_empty' => trans_html('No earned CEU awards are recorded for this transcript.'),
    'record_course' => trans_html('Course'),
    'record_title' => trans_html('Title'),
    'record_term' => trans_html('Term'),
    'earned_back_records' => trans_html('Back to CEU records'),
    'earned_actor' => trans_html('Recorded by (current name)'),
    'earned_actor_unknown' => trans_html('Staff account name unavailable'),
    'earned_read_only' => trans_html(
        'You can review recorded CEUs. Award and correction actions require the '
        . 'earned-CEU permission.',
    ),
    'earned_date_unknown' => trans_html('Not recorded'),
    'earned_records' => trans_html('Earned CEU records'),
    'earned_records_empty' => trans_html('No CEU-bearing attempts appear on this page.'),
    'earned_records_more' => trans_html('More CEU records'),
    'earned_records_previous' => trans_html('Previous CEU records'),
    'ceu_unofficial' => trans_html('Unofficial continuing education record'),
    'ceu_record_help' => trans_html(
        'For personal reference. Request an official CEU transcript from the '
        . 'records office.',
    ),
    'ceu_record' => trans_html('View CEU record'),
    'ceu_transcript' => trans_html('Continuing education transcript'),
    'earned_total' => trans_html('Total earned CEUs'),
    'course_title' => trans_html('Course CEUs'),
    'section_title' => trans_html('Offered CEUs'),
    'back' => trans_html('Back to record'),
    'loading' => trans_html('Loading CEU settings…'),
    'unrecorded' => trans_html('Not recorded in this publication'),
    'none' => trans_html('No CEUs'),
    'quantity' => trans_html('{value} CEUs'),
    'course_value' => trans_html('Course declaration: {value}'),
    'unbound' => trans_html(
        'No course publication is selected. The declaration above comes from the course draft. Select a '
        . 'course publication before publishing this offering.',
    ),
    'draft_inherit' => trans_html('Use the course draft declaration'),
    'inherit' => trans_html('Use the retained course declaration'),
    'fixed' => trans_html('Declare a CEU value'),
    'mode' => trans_html('CEU declaration'),
    'value' => trans_html('Continuing education units (CEUs)'),
    'help' => trans_html(
        'Use up to three decimal places, at most 999.999. CEUs do not convert to academic credits or '
        . 'contact hours.',
    ),
    'publication_help' => trans_html(
        'Save the draft, then publish it. Offered CEUs describe the offering; they do not award earned '
        . 'units. Existing registrations retain their accepted publications.',
    ),
    'save' => trans_html('Save CEU draft'),
    'review' => trans_html('Review publication'),
    'saved' => trans_html('CEU draft saved. Review its publication before using it.'),
    'invalid' => trans_html('Use a CEU value from 0 to 999.999 with up to three decimal places.'),
    'accepted_title' => trans_html('Accepted offered CEUs'),
    'offered_help' => trans_html('Offered CEUs are not earned units.'),

    'earned_title' => trans_html('Earned CEUs'),
    'earned_unknown' => trans_html('No earned CEU decision recorded'),
    'earned_none' => trans_html('No earned CEUs'),
    'earned_loading' => trans_html('Loading earned CEU evidence…'),
    'earned_help' => trans_html(
        'Record earned CEUs only after verifying institutional completion evidence. Offered CEUs are '
        . 'a separate publication; this decision changes neither academic credits, GPA, registration '
        . 'load nor charges.',
    ),
    'earned_unavailable' => trans_html(
        'This attempt needs registrar review: a retained offered CEU declaration, a posted passing '
        . 'grade, an actual completion date and no pending grade correction are required. Closed or '
        . 'unknown attempts need separate resolution.',
    ),
    'earned_mode' => trans_html('Earned CEU decision'),
    'earned_value_mode' => trans_html('Record earned CEUs'),
    'earned_revoke' => trans_html('Revoke the recorded CEU award'),
    'earned_value' => trans_html('Earned continuing education units (CEUs)'),
    'earned_effective' => trans_html('Effective date of this CEU decision'),
    'earned_reason' => trans_html('Reason and institutional completion evidence'),
    'earned_ack' => trans_html(
        'I verified the institutional evidence and the separate academic-credit and billing '
        . 'consequences.',
    ),
    'earned_review' => trans_html('Review earned CEUs'),
    'earned_confirm' => trans_html('Confirm CEU decision'),
    'earned_edit' => trans_html('Change CEU decision'),
    'earned_history' => trans_html('Earned CEU history'),
    'earned_history_loading' => trans_html('Loading earned CEU decisions…'),
    'earned_history_empty' => trans_html('No earned CEU decisions appear on this history page.'),
    'earned_more' => trans_html('Older history'),
    'earned_previous' => trans_html('Previous history page'),
    'earned_return' => trans_html('Return to CEU decision'),
    'earned_reload' => trans_html('Reload current academic evidence'),
    'earned_saved' => trans_html('Earned CEU decision recorded. Earlier decisions remain in protected history.'),
    'earned_before' => trans_html('Previously recorded CEUs'),
    'earned_after' => trans_html('Reviewed earned CEUs'),
    'earned_completion' => trans_html('Verified actual completion'),
    'earned_recorded' => trans_html('Recorded at'),
    'earned_effective_at' => trans_html('Effective at'),
    'earned_invalid' => trans_html(
        'Use a CEU value with up to three decimal places within the accepted '
        . 'offered amount.',
    ),
    'earned_no_auto' => trans_html(
        'CEUs are awarded explicitly; a passing grade or an offered value does not automatically '
        . 'award them.',
    ),
];
