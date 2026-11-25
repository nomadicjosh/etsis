<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'graduate_policy' => trans_html('Graduate-course registration policy'),
    'graduate_approvals' => trans_html('Graduate-course approvals for this pursuit'),
    'graduate_count' => trans_html('Retained graduate decisions checked'),
    'graduate_components' => trans_html('Protected graduate decisions and technical counters'),
    'graduate_references' => trans_html(
        'Original retained policy, course, program, curriculum and offering references'
    ),
    'graduate_empty' => trans_html('No graduate decision observed'),
    'graduate_empty_help' => trans_html(
        'No decision was recorded in this selected source. Earlier unrecorded activity remains unknown.'
    ),
    'graduate_limit_help' => trans_html(
        'Long histories need a bounded operator check. More than 200 reference selections, oversized inventories '
        . 'or the cooperative time limit need an offline review; increasing the event allowance alone '
        . 'does not remove those bounds.'
    ),
    'graduate_help' => trans_html(
        'Compare recorded policy or pursuit approval decisions with their original definitions, including '
        . 'revoked decisions. This check does not recalculate earlier GPA, determine current registration '
        . 'eligibility or establish complete historical coverage. Academic and financial records remain unchanged.'
    ),
    'catalog_course' => trans_html('Course publications'),
    'catalog_section' => trans_html('Section publications'),
    'catalog_program' => trans_html('Program publications'),
    'catalog_group' => trans_html('Reusable course group publications'),
    'catalog_curriculum' => trans_html('Degree and specialization requirements publications'),
    'check_program' => trans_html('Check program publication history'),
    'check_group' => trans_html('Check course group publication history'),
    'check_curriculum' => trans_html('Check requirements publication history'),
    'catalog_curriculum_help' => trans_html(
        'Compare this publication stream with its own retained projections and current pointers using a '
        . '200-event allowance. Program checks include recorded cohort definitions and specialization choices. '
        . 'Group and requirements checks verify publication order, routing and current references. '
        . 'This does not evaluate dependent course or grading streams, student work, graduation eligibility, '
        . 'current drafts or archive completeness. Nothing is published or repaired.'
    ),
    'check_course' => trans_html('Check course publication history'),
    'check_section' => trans_html('Check section publication history'),
    'catalog_count' => trans_html('Retained publications checked'),
    'catalog_help' => trans_html(
        'Compare recorded course or section publications and bounded retained references using a 200-event allowance. '
        . 'Section checks also verify the retained course binding and offering-selection history. '
        . 'This does not validate current drafts, student eligibility, every dependent academic record, '
        . 'finances, external synchronization or archive completeness. Nothing is published, selected or repaired.'
    ),
    'catalog_empty' => trans_html('No publication observed'),
    'catalog_empty_help' => trans_html(
        'No publication was found for this record. Earlier catalog history remains unknown.'
    ),
    'catalog_limit_help' => trans_html(
        'A longer publication history may need a bounded operator check. '
        . 'More than 200 cached references or an oversized inventory needs an offline review; '
        . 'raising the event allowance alone does not remove those bounds.'
    ),
    'catalog_identity' => trans_html('Catalog record routing'),
    'catalog_publication' => trans_html('Retained publication and reference projection'),
    'course_reference' => trans_html('Retained course definitions'),
    'course_binding' => trans_html('Section course binding'),
    'offering_selection' => trans_html('Future-registration offering selection'),
    'waitlist' => trans_html('Waitlist request'),
    'waitlist_components' => trans_html('Retained request, queue projection and protected history'),
    'waitlist_count' => trans_html('Retained requests checked'),
    'check_waitlist' => trans_html('Check waitlist request history'),
    'waitlist_help' => trans_html(
        'Compare this request with protected queue history using a 200-event allowance. '
        . 'This check allocates no seat and does not verify registration eligibility, academic or financial records, '
        . 'notifications or archive completeness.'
    ),
    'academic_credit' => trans_html('Selected academic record'),
    'credit_count' => trans_html('Academic sources checked'),
    'check_credit' => trans_html('Check selected academic history'),
    'credit_help' => trans_html(
        'Compare this institutional or transfer credit with its available protected source, '
        . 'registration and projections. '
        . 'This uses a 200-event allowance and makes no correction. '
        . 'Earlier gaps, pursuit attribution, equivalence, finances and full archive completeness need separate review.'
    ),
    'recorded_progress' => trans_html('Recorded degree progress'),
    'progress_components' => trans_html('Accepted progress observation, protected history and projection'),
    'progress_count' => trans_html('Recorded progress observations checked'),
    'check_progress' => trans_html('Check recorded progress history'),
    'progress_help' => trans_html(
        'Compare this saved progress observation with its protected history and projection. '
        . 'This check does not recalculate progress, apply current policies or certify graduation.',
    ),
    'advisor_assignments' => trans_html('Advisor assignments'),
    'degree_selection' => trans_html('Selected degree requirements'),
    'advisor_components' => trans_html('Retained advisor assignments and projection'),
    'requirement_components' => trans_html('Degree selection, projection and retained definitions'),
    'advisors_empty' => trans_html('No recorded advisor assignment observed'),
    'advisors_empty_help' => trans_html(
        'No advisor decision was found for this pursuit. Earlier advising remains unknown.',
    ),
    'requirements_empty' => trans_html('No recorded degree selection observed'),
    'requirements_empty_help' => trans_html(
        'No degree selection was found for this pursuit. '
        . 'This does not assign requirements or establish earlier history.',
    ),
    'advisor_count' => trans_html('Retained advisor periods checked'),
    'requirement_count' => trans_html('Selected requirement baselines checked'),
    'check_advisors' => trans_html('Check advisor assignment history'),
    'check_requirements' => trans_html('Check degree selection history'),
    'curriculum_help' => trans_html(
        'Compare retained advisor assignments or the selected degree baseline with history, '
        . 'using a 200-event allowance. Current advisor eligibility and degree completion need their own reviews; '
        . 'this check changes neither access nor academic requirements.',
    ),
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
