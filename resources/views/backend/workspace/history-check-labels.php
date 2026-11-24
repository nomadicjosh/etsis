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
    'primary_position' => trans_html('Primary position designation'),
    'position' => trans_html('Position agreement'),
    'time_entry' => trans_html('Retained time decision'),
    'check_primary' => trans_html('Check primary designation history'),
    'check_position' => trans_html('Check position agreement history'),
    'check_time' => trans_html('Check retained time decision'),
    'hr_count' => trans_html('Selected HR records checked'),
    'primary_components' => trans_html('Primary designation and projection'),
    'position_components' => trans_html('Retained position agreement and projection'),
    'time_components' => trans_html('Retained time decision and working entry'),
    'earlier_time' => trans_html('Earlier time decision not recorded'),
    'earlier_time_help' => trans_html(
        'The entry has a decision status, but its earlier decision was not recorded. '
        . 'This observation cannot verify that decision.',
    ),
    'hr_identity' => trans_html('Employment and person routing'),
    'primary_empty' => trans_html('No recorded primary designation observed'),
    'primary_empty_help' => trans_html(
        'No protected designation was found. This does not establish earlier primary history.',
    ),
    'time_empty' => trans_html('No retained time decision observed'),
    'time_empty_help' => trans_html(
        'The entry is pending with no recorded decision. This check does not approve or reject it.',
    ),
    'hr_help' => trans_html(
        'Run one selected HR observation with a 200-event allowance and bounded replay. '
        . 'It does not approve employment or time, '
        . 'recalculate current appointment eligibility, determine wages/payroll or certify archive completeness.',
    ),
    'hr_history_help' => trans_html(
        'Earlier checks describe their recorded observation. HR reasons, pay terms, work intervals, '
        . 'raw event payloads and credentials are not displayed here.',
    ),
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
