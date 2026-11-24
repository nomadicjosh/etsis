<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'policy' => trans_html('Policy for this determination'),
    'current' => trans_html('Use the current effective policy'),
    'current_help' => trans_html('The institution policy applies unless this program has a current override.'),
    'select' => trans_html('Choose a published policy'),
    'selection_help' => trans_html(
        'Select a publication only when the institution authorizes its use for this determination. '
        . 'The reason must explain the choice. Registration quantities are verified as of today; '
        . 'this selection does not reconstruct an earlier census or set a default for this pursuit.',
    ),
    'source' => trans_html('Publication scope'),
    'global' => trans_html('Institution'),
    'program' => trans_html('This program'),
    'publication' => trans_html('Publication {version} · {date}'),
    'threshold' => trans_html('{label}: from {credits} credits'),
    'pick' => trans_html('Use publication {version}'),
    'cleared' => trans_html('No credit bands in this publication. It cannot be selected.'),
    'empty' => trans_html('No policy publications are available in this scope.'),
    'more' => trans_html('More publications'),
    'previous' => trans_html('Previous publications'),
    'close' => trans_html('Back to determination'),
    'retry' => trans_html('Retry publication list'),
    'loading' => trans_html('Loading verified policy publications…'),
    'failed' => trans_html('Policy publications could not be loaded. Retry or return to the determination.'),
    'selected' => trans_html('Explicitly selected publication for this determination'),
    'effective' => trans_html('Current effective policy at determination'),
    'unrecorded' => trans_html('The selection basis and publication date were not separately recorded.'),
    'record_help' => trans_html(
        'Record the current term using verified registered credits and the reviewed policy. '
        . 'This action does not backdate a census, replace an earlier determination '
        . 'or change registrations or charges.',
    ),
];
