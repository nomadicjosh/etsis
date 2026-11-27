<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    'title' => trans('Course auditing'),
    'basis' => trans('Enrollment basis'),
    'standard' => trans('Standard enrollment'),
    'audit' => trans('Audit — no credit or GPA'),
    'allowed' => trans('Allow auditing for this section'),
    'self_service' => trans('Allow students to request an audit seat'),
    'fee' => trans('Audit section fee'),
    'policy_help' => trans(
        'Auditors use a seat and follow the same eligibility, timetable and registration limits. '
        . 'They receive no academic credits, GPA points or CEUs and cannot satisfy prerequisites '
        . 'or degree requirements.'
    ),
    'pricing_help' => trans(
        'The audit fee replaces the ordinary section fee. Registered-credit charges are zero; '
        . 'applicable once-per-term fees still apply. Enter zero to waive the audit section fee.'
    ),
    'publication_help' => trans(
        'Save these draft rules, then publish and select the section offering. '
        . 'Later policy changes do not change existing enrollment bases or charges.'
    ),
    'review_help' => trans(
        'Audit enrollment uses one seat and earns zero academic credits, GPA points or CEUs. '
        . 'AU is an audit designation, not an earned grade. Review the charges before confirming.'
    ),
    'change_help' => trans(
        'To change an audit enrollment, review its drop and make a new registration during add/drop. '
        . 'Credit waitlist requests cannot be converted to audit requests.'
    ),
    'save' => trans('Save audit rules'),
    'saved' => trans('Audit rules saved. Publish and select the offering before using them.'),
    'publish' => trans('Review offering publication'),
    'back' => trans('Back to record'),
    'loading' => trans('Loading audit rules…'),
    'auditor' => trans('Auditor'),
    'no_grade' => trans('Audited courses do not receive an academic grade.'),
    'certification_scope' => trans(
        'Includes current standard enrollments and withdrawals, up to fifty. '
        . 'Auditors, early drops and cancelled registrations are excluded. Certification retains this review; '
        . 'later grade corrections remain available. Names and institutional IDs shown here are current values.'
    ),
];
