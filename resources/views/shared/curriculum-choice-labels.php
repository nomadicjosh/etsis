<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'heading' => trans_html('Incompatible curriculum choices'),
    'help' => trans_html(
        'Choose pairs that cannot be selected together within this program. The rule works in both directions. '
        . 'Save the policy, then publish the program to apply it to future selections under that publication. '
        . 'Earlier student choices keep their retained policy.'
    ),
    'first' => trans_html('First curriculum component'),
    'second' => trans_html('Incompatible with'),
    'choose' => trans_html('Choose a curriculum component'),
    'add' => trans_html('Add incompatible pair'),
    'remove' => trans_html('Remove incompatible pair'),
    'invalid' => trans_html('Choose two different components and list each pair only once.'),
    'limit' => trans_html('A program policy supports up to thirty-two incompatible pairs.'),
    'unknown' => trans_html('Incompatible-choice rules were not recorded in this earlier publication.'),
    'none' => trans_html('No incompatible curriculum pairs in this publication.'),
    'draft_empty' => trans_html('No incompatible curriculum pairs configured in this draft.'),
    'unavailable' => trans_html('Unavailable component; remove this pair or reactivate its choice before publishing.'),
    'conflict' => trans_html('Remove one choice from each incompatible pair before reviewing this selection.'),
];
