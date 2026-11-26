<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'regular_limit' => trans_html('Maximum regular-assignment bonus percentage points (optional)'),
    'regular_limit_help' => trans_html(
        'Limit only points earned above the base maximum on regular assignments. Blank is unrestricted; '
        . 'zero counts no regular bonus. Apply after drops and excusals, before category weighting and the cap. '
        . 'Bonus-only work has its own limit. Saved marks stay unchanged.'
    ),
    'regular_limit_feedback' => trans_html('Regular-assignment bonus contribution limit: {limit} percentage points.'),
    'summary_regular_limits' => trans_html('Categories with a regular-assignment bonus limit: {count}.'),
    'bonus_limit' => trans_html('Maximum bonus-only percentage points (optional)'),
    'bonus_limit_help' => trans_html(
        'Leave blank for no additional limit. Zero excludes bonus-only contributions. '
        . 'Apply after drops and excusals, before category weighting and the category cap. '
        . 'Bonus marks on regular assignments and recorded scores remain unchanged.'
    ),
    'bonus_limit_error' => trans_html(
        'Use 0 to 100 percentage points with at most two decimal places, or leave blank.'
    ),
    'bonus_limit_feedback' => trans_html('Bonus-only contribution limit: {limit} percentage points.'),
    'bonus_limit_applied' => trans_html(
        'Recorded contribution: {raw}; counted before the category cap: {applied} percentage points.'
    ),
    'bonus_limit_awaiting' => trans_html('Graded regular work is required before this contribution can be calculated.'),
    'summary_bonus_limits' => trans_html('Categories with a bonus-only contribution limit: {count}.'),
    'cap' => trans_html('Cap category average at 100%'),
    'cap_help' => trans_html(
        'Apply this cap after drops, excusals and bonus points, before the category weight. '
        . 'Recorded points and other categories remain unchanged.'
    ),
    'protected' => trans_html('Protected from automatic drops'),
    'protect' => trans_html('Protect from automatic drops'),
    'protect_help' => trans_html(
        'Protect regular assignments that must stay included, such as a final exam. '
        . 'Assign a category first. Bonus-only work cannot be protected because it is never automatically dropped.'
    ),
    'protection_cleared' => trans_html(
        'Automatic-drop protection was cleared when this assignment changed categories.'
    ),
    'rules' => trans_html('Category calculation rules'),
    'cap_used' => trans_html('Category average capped at 100% before weighting.'),
    'cap_ready' => trans_html('Category average is limited to 100% before weighting.'),
    'raw_average' => trans_html('Uncapped category average'),
    'bonus_caps' => trans_html(
        'Recorded bonus points are shown before category caps. '
        . 'Each category rule determines how those points contribute to the running average.'
    ),
    'protected_count' => trans_html('Assignments protected from automatic drops'),
    'summary' => trans_html('Category caps at 100%: {caps}. Assignments protected from automatic drops: {protected}.'),
];
