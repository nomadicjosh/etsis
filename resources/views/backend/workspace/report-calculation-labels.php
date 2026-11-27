<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Calculate a numeric column'),
    'operation' => trans_html('Calculation'),
    'operand' => trans_html('Calculate with'),
    'field' => trans_html('Another field'),
    'constant' => trans_html('Decimal constant'),
    'choose_field' => trans_html('Choose the second numeric field:'),
    'value' => trans_html('Constant value'),
    'help' => trans_html(
        'Use up to nine whole digits and six decimal places, with a dot for decimals. '
        . 'Missing inputs and division by zero remain blank. Calculations run before summaries.',
    ),
    'money' => trans_html('Monetary calculations require another amount in the same currency and units.'),
    'invalid' => trans_html(
        'Complete each decimal constant using up to nine whole digits and six decimal places, '
        . 'or remove its calculation.',
    ),
    'add' => trans_html('Add'),
    'subtract' => trans_html('Subtract'),
    'multiply' => trans_html('Multiply'),
    'divide' => trans_html('Divide'),
    'remove' => trans_html('Remove calculation'),
];
