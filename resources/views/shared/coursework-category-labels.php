<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
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
