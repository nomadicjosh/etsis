<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'rate' => trans_html('Account credit (% of original registration charges)'),
    'help' => trans_html(
        'Optional. Applies only to charges linked to this registration. Earlier charge credits count '
        . 'toward the scheduled reduction. Separate term fees and cash refunds require their own decisions.',
    ),
    'authority' => trans_html(
        'Publishing or replacing a policy with account credits also requires finance posting permission.',
    ),
    'invalid' => trans_html('Use a percentage from 0 to 100 with at most two decimal places, or leave it blank.'),
    'period' => trans_html('{rate}% registration-charge account credit'),
    'review' => trans_html(
        'Scheduled account credit: {amount}. This reduces registration charges; it does not issue a cash refund.',
    ),
    'none' => trans_html('No additional registration-charge credit is due under this schedule.'),
    'saved' => trans_html('Registration-charge account credit recorded: {amount}. No cash refund was issued.'),
];
