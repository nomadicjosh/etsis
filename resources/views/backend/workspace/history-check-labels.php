<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'Admissions application' => trans_html('Admissions application'),
    'Check application history' => trans_html('Check application history'),
    'Application facts and history' => trans_html('Application facts and history'),
    'Application intake projection' => trans_html('Application intake projection'),
    'Business records were not changed. A restricted support receipt was recorded.' => trans_html(
        'Business records were not changed. A restricted support receipt was recorded.',
    ),
    'Run one selected-record observation with a 200-event allowance and bounded replay. '
        . 'It does not repair data, approve admissions, academic or financial decisions, verify external settlement, '
        . 'or certify archive completeness or restore readiness.' => trans_html(
            'Run one selected-record observation with a 200-event allowance and bounded replay. '
            . 'It does not repair data, approve admissions, academic or financial decisions, '
            . 'verify external settlement, '
            . 'or certify archive completeness or restore readiness.',
        ),
    'Previous results describe their recorded observation, not a continuing guarantee about the current record. '
        . 'No event payloads, application answers, private profile values, financial amounts or credentials '
        . 'are displayed.' => trans_html(
            'Previous results describe their recorded observation, '
            . 'not a continuing guarantee about the current record. '
            . 'No event payloads, application answers, private profile values, financial amounts or credentials '
            . 'are displayed.',
        ),
];
