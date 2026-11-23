<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'program_standing' => trans_html('Program standing'),
    'retained_load' => trans_html('Retained enrollment load'),
    'pursuit_identity' => trans_html('Program pursuit identity'),
    'standing_components' => trans_html('Standing periods and history'),
    'load_components' => trans_html('Retained load decision and projection'),
    'standing_empty' => trans_html('No recorded standing observed'),
    'standing_empty_help' => trans_html(
        'No standing decision was found for this pursuit. Earlier standing remains unknown.',
    ),
    'standing_count' => trans_html('Standing periods checked'),
    'load_count' => trans_html('Retained determinations checked'),
    'check_standing' => trans_html('Check standing history'),
    'check_load' => trans_html('Check retained load history'),
    'application' => trans_html('Admissions application'),
    'check_application' => trans_html('Check application history'),
    'application_components' => trans_html('Application facts and history'),
    'intake_component' => trans_html('Application intake projection'),
    'receipt_saved' => trans_html(
        'Business records were not changed. A restricted support receipt was recorded.',
    ),
    'check_help' => trans_html(
        'Run one selected-record observation with a 200-event allowance and bounded replay. '
            . 'It does not repair data, approve admissions, academic or financial decisions, '
            . 'verify external settlement, '
            . 'or certify archive completeness or restore readiness.',
    ),
    'history_help' => trans_html(
        'Previous results describe their recorded observation, '
            . 'not a continuing guarantee about the current record. '
            . 'No event payloads, application answers, private profile values, financial amounts or credentials '
            . 'are displayed.',
    ),
];
