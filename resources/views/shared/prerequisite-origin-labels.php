<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'equivalents_title' => trans_html('Equivalent courses'),
    'equivalents_for' => trans_html('Equivalent courses for'),
    'equivalents_help' => trans_html(
        'Choose courses that may replace this named prerequisite in this course publication only. '
        . 'These choices are one-way and do not chain through other equivalents. '
        . 'The same pursuit, coursework source, grade minimum and completion dates still apply. '
        . 'Co-requisites, earned credit and degree requirements are unchanged.'
    ),
    'equivalents_counting' => trans_html(
        'Within one requirement, one completed course can fill only one named course slot. '
        . 'The same course may still satisfy separate requirements.'
    ),
    'add_equivalent' => trans_html('Add equivalent course'),
    'no_equivalents' => trans_html('No equivalent courses selected.'),
    'remove_equivalent' => trans_html('Remove equivalent'),
    'choose_equivalent' => trans_html('Choose an equivalent course'),
    'equivalents_clone' => trans_html(
        'Keep every equivalent of a retained prerequisite or omit that prerequisite entirely.'
    ),
    'max_age_months' => trans_html('Maximum age before section starts (months)'),
    'recency_help' => trans_html(
        'Optional whole months from 1 through 1200; 60 means five years. '
        . 'Completion must fall within that many calendar months ending on the published section start, inclusive. '
        . 'Only coursework eligible for this pursuit is considered. '
        . 'Earned credit and degree requirements are unchanged.'
    ),
    'recency_start' => trans_html('Published section start'),
    'recency_earliest' => trans_html('Earliest completion under age limit'),
    'recency_unknown' => trans_html(
        'The published section start is unavailable. Select a published offering to assess this age limit.'
    ),
    'invalid_completion_policy' => trans_html('Check the completion dates and optional whole-month age limit.'),
    'completion_from' => trans_html('Completed from'),
    'completion_through' => trans_html('Completed through'),
    'completion_window' => trans_html('Actual completion dates'),
    'completion_help' => trans_html(
        'Optional inclusive dates for this requirement. Leave dates and the age limit blank for no date restriction. '
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
