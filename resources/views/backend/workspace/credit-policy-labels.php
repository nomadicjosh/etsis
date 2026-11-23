<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'course_title' => trans_html('Course credit policy'),
    'section_title' => trans_html('Offering credits'),
    'back' => trans_html('Back to record'),
    'loading' => trans_html('Loading credit settings…'),
    'default' => trans_html('Default academic credits'),
    'mode' => trans_html('Course credit rule'),
    'fixed' => trans_html('Fixed credits'),
    'range' => trans_html('Credit range'),
    'minimum' => trans_html('Minimum academic credits'),
    'maximum' => trans_html('Maximum academic credits'),
    'increment' => trans_html('Credit increment'),
    'inherit' => trans_html('Use the retained course default'),
    'section_value' => trans_html('Fixed credits for this offering'),
    'range_help' => trans_html('Allowed: {min}–{max} academic credits, in increments of {step}.'),
    'fixed_help' => trans_html('The retained course allows {value} academic credits.'),
    'precision' => trans_html('Use up to three decimal places. These are academic credits; CEUs are separate.'),
    'course_help' => trans_html(
        'Save a draft default and permitted credit range together, then publish the course definition. '
        . 'Existing publications, student attempts and charges retain their accepted facts.',
    ),
    'section_help' => trans_html(
        'Choose one fixed offering value within its selected course policy. Publish and select the offering '
        . 'before new registrations use it. Students cannot choose individual credit values in this stage.',
    ),
    'save' => trans_html('Save credit draft'),
    'saved' => trans_html('Credit draft saved. Review its publication before using it for new registrations.'),
    'review' => trans_html('Review publication'),
    'invalid' => trans_html('Use a nonnegative credit value with up to three decimal places, at most 999.999.'),
    'unrecorded' => trans_html('Not recorded in this publication'),
    'unbound' => trans_html(
        'No course publication is selected. These limits come from the course draft. '
        . 'Choose a published course definition before publishing this offering.',
    ),
    'draft_inherit' => trans_html('Use the course draft default'),
    'draft_fixed_help' => trans_html('The course draft allows {value} academic credits.'),
];
