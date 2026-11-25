<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Custom labels'),
    'add' => trans_html('Add custom labels'),
    'edit' => trans_html('Edit custom labels'),
    'help' => trans_html(
        'The first matching rule supplies the label. Rules label joined rows; '
        . 'they do not filter them or change student records.',
    ),
    'choose' => trans_html('Choose a labelled column'),
    'rule' => trans_html('Label rule'),
    'label' => trans_html('Show this label'),
    'conditions' => trans_html('When these conditions match'),
    'edit_rule' => trans_html('Edit rule'),
    'add_rule' => trans_html('Add label rule'),
    'up' => trans_html('Move rule up'),
    'down' => trans_html('Move rule down'),
    'remove_rule' => trans_html('Remove label rule'),
    'otherwise' => trans_html('Label for unmatched rows'),
    'blank' => trans_html('Leave unmatched rows blank'),
    'fallback_help' => trans_html(
        'Blank leaves the result unspecified. To label missing source values, '
        . 'add an explicit missing-value condition.',
    ),
    'remove' => trans_html('Use original field values'),
    'back' => trans_html('Back to column settings'),
    'incomplete' => trans_html('Complete every label and its conditions before previewing or saving.'),
    'limit' => trans_html(
        'Use up to eight label rules per column. '
        . 'All report conditions share the existing forty-condition limit.',
    ),
    'incompatible' => trans_html(
        'Remove the calculation, calendar grouping or numeric summary before adding text labels.',
    ),
    'source' => trans_html('Custom labels from conditions'),
    'result_note' => trans_html('These columns use custom labels rather than original field values:'),
    'counts' => trans_html(
        'Count counts labelled rows. Distinct count counts different labels. '
        . 'To count students, summarize a separate student reference.',
    ),
];
