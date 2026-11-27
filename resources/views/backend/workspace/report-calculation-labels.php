<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'next_operation' => trans_html('Add another operation'),
    'edit_start' => trans_html('Edit starting expression'),
    'back_calculation' => trans_html('Back to calculation'),
    'starting_expression' => trans_html('Starting expression'),
    'group_invalid' => trans_html(
        'Complete each calculation group with numeric fields and valid decimal constants, or remove the calculation.',
    ),
    'group' => trans_html('Calculation group'),
    'group_help' => trans_html(
        'A group calculates its two operands first. Use up to four group levels and fifteen operations per column. '
        . 'Groups use nonmonetary numeric fields. Missing inputs and division by zero remain blank '
        . 'unless you explicitly choose a fallback.',
    ),
    'left' => trans_html('Left operand'),
    'right' => trans_html('Right operand'),
    'operand_type' => trans_html('Operand type'),
    'numeric_field' => trans_html('Numeric field'),
    'edit_left' => trans_html('Edit left group'),
    'edit_right' => trans_html('Edit right group'),
    'back_group' => trans_html('Back to parent group'),
    'group_limit' => trans_html('This group has reached the nesting or operation limit.'),
    'unavailable' => trans_html('Unavailable field'),
    'expression' => trans_html('Calculated expression'),
    'title' => trans_html('Calculate a numeric column'),
    'operation' => trans_html('Calculation'),
    'operand' => trans_html('Calculate with'),
    'field' => trans_html('Another field'),
    'constant' => trans_html('Decimal constant'),
    'choose_field' => trans_html('Choose the second numeric field:'),
    'value' => trans_html('Constant value'),
    'help' => trans_html(
        'Use up to nine whole digits and six decimal places, with a dot for decimals. '
        . 'Missing inputs and division by zero remain blank unless you explicitly choose a fallback. '
        . 'Calculations run before summaries.',
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
    'coalesce' => trans_html('Use fallback'),
    'nullif' => trans_html('Blank matching value'),
    'coalesce_help' => trans_html(
        'Keep the first value when it is specified; otherwise use the second value. '
        . 'Zero is a specified value. A missing fallback leaves the result unspecified.'
    ),
    'nullif_help' => trans_html(
        'Keep the first value unless it equals the second value; a match becomes unspecified. '
        . 'A missing comparison value does not erase a specified first value.'
    ),
    'null_help' => trans_html(
        'Missing values remain unspecified by default. Use fallback and blank matching value are '
        . 'explicit choices for nonmonetary numeric fields, applied before summaries.'
    ),
    'null_money' => trans_html('Use nonmonetary numeric fields for missing-value calculations.'),
    'remove' => trans_html('Remove calculation'),
];
