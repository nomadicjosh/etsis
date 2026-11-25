<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'completion_from' => trans_html('Completed from'),
    'completion_through' => trans_html('Completed through'),
    'completion_window' => trans_html('Actual completion dates'),
    'completion_help' => trans_html(
        'Optional inclusive dates for this requirement. Leave both blank for no date restriction. '
        . 'Unknown completion dates cannot meet a dated requirement; '
        . 'term and grade-recording dates are not substitutes.'
    ),
    'invalid_completion_window' => trans_html('Check the completion dates; from must not be after through.'),

    'field' => trans_html('Qualifying prerequisite coursework'),
    'either' => trans_html('Institutional or transfer'),
    'institutional' => trans_html('Institutional work only'),
    'transfer' => trans_html('Transfer work only'),
    'help' => trans_html(
        'Default for requirements without a coursework-source override. Co-requisites use their separate policy.',
    ),
    'requirement_field' => trans_html('Coursework source for this requirement'),
    'inherit' => trans_html('Use course default'),
    'effective' => trans_html('Effective coursework source'),
    'requirement_help' => trans_html(
        'Override the default for this requirement only. '
        . 'Each counted course must meet this source and grade minimum together.',
    ),
    'scope' => trans_html('This rule does not change earned credits or decide which work counts toward a degree.'),
];
