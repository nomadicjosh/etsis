<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'limited' => trans_html(
        'Additional shared-course explanations check three- and four-group combinations within a limit '
        . 'of 512 comparisons. Larger or unchecked combinations may require registrar review.'
    ),
    'limit_heading' => trans_html('Shared coursework review'),
    'back_progress' => trans_html('Back to progress review'),
    'no_bound' => trans_html(
        'No numeric shared-course shortage was identified within this explanation limit. '
        . 'A complete placement is still unavailable; review the retained requirements and sharing rules.'
    ),
];
