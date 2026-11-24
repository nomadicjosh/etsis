<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
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
];
