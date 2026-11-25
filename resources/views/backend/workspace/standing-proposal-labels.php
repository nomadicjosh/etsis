<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'policy' => trans_html('Standing proposal policy'),
    'setup' => trans_html('Institution setup'),
    'intro' => trans_html(
        'Publish GPA floors for proposed standing. The highest floor met by the current pursuit ' .
        'institutional GPA applies. Staff must review and confirm each period; this does not ' .
        'change registration or student activity.'
    ),
    'enabled' => trans_html('Enable default institutional GPA proposals'),
    'minimum_credits' => trans_html('Minimum institutional GPA credits'),
    'minimum_help' => trans_html(
        'Count GPA credits after retained repeat rules. Undefined GPA never means zero GPA. This ' .
        'is a current cumulative assessment, not a term or backdated GPA calculation.'
    ),
    'floor' => trans_html('GPA floor'),
    'band' => trans_html('GPA band'),
    'choose' => trans_html('Choose standing definition'),
    'choose_help' => trans_html(
        'Choose an active institutional definition. Published labels remain retained; later label ' .
        'changes require policy review.'
    ),
    'add' => trans_html('Add GPA band'),
    'remove' => trans_html('Remove GPA band'),
    'empty' => trans_html('No GPA bands configured. Add a zero floor before enabling proposals.'),
    'review' => trans_html('Review proposal policy'),
    'review_title' => trans_html('Review standing proposal policy'),
    'publish' => trans_html('Publish proposal policy'),
    'published' => trans_html('Standing proposal policy published.'),
    'reason' => trans_html('Reason for policy publication'),
    'back' => trans_html('Back to record'),
    'edit' => trans_html('Keep editing'),
    'current_version' => trans_html('Published policy version'),
    'off' => trans_html('Proposals disabled'),
    'on' => trans_html('Proposals enabled'),
    'find' => trans_html('Find a standing definition'),
    'search' => trans_html('Search definitions'),
    'more' => trans_html('More definitions'),
    'previous' => trans_html('Previous definitions'),
    'none' => trans_html('No active standing definitions match.'),
    'error' => trans_html('The request could not be completed. Your draft remains available.'),
    'denied' => trans_html('Standing policy access is no longer available to this account.'),
    'calculate' => trans_html('Propose from current GPA'),
    'calculated' => trans_html('Current institutional GPA proposal'),
    'gpa' => trans_html('Institutional GPA'),
    'credits' => trans_html('GPA credits'),
    'as_of' => trans_html('Calculated using current records on'),
    'proposal_help' => trans_html(
        'Complete the effective dates and review the new period. Grades, requirements or policy ' .
        'changes require a fresh proposal. Current GPA does not reconstruct standing eligibility ' .
        'on an earlier date.'
    ),
    'manual' => trans_html('This period now uses manual standing review; its GPA proposal evidence was removed.'),
    'clear' => trans_html('Use manual review instead'),
    'history' => trans_html('Accepted GPA proposal evidence'),
    'unconfigured' => trans_html('No standing proposal policy has been published. Proposals are unavailable.'),
    'refresh' => trans_html('Refresh policy version'),
    'refresh_help' => trans_html(
        'Refresh preserves your draft. Review the latest published rules before confirming a ' .
        'replacement.'
    ),
    'before' => trans_html('Currently published'),
    'after' => trans_html('Proposed policy'),
    'institution' => trans_html('Institutional default'),
    'program' => trans_html('Program policy'),
    'academic_level' => trans_html('Academic-level policy'),
    'overrides' => trans_html('Program and academic-level overrides'),
    'precedence' => trans_html(
        'Program policy applies first, then the assigned published cohort academic level, then the institutional ' .
        'default. A disabled applicable override stops proposals for that scope; it does not fall through.'
    ),
    'override_help' => trans_html(
        'Each override has its own GPA bands, minimum GPA credits and enabled choice. Overrides can enable ' .
        'specific programs or levels while the institutional default is disabled. Dates and staff ' .
        'confirmation remain required.'
    ),
    'override_enabled' => trans_html('Enable GPA proposals for this override'),
    'edit_override' => trans_html('Edit standing policy override'),
    'remove_override' => trans_html('Remove policy override'),
    'add_program' => trans_html('Add program policy'),
    'add_level' => trans_html('Add academic-level policy'),
    'choose_program' => trans_html('Choose a program for standing policy'),
    'choose_level' => trans_html('Choose an academic level for standing policy'),
    'choose_target' => trans_html('Re-select policy target'),
    'duplicate_target' => trans_html(
        'This target already has an override. Edit that override or choose a different target.'
    ),
    'find_target' => trans_html('Find a program or academic level'),
    'use_target' => trans_html('Use this policy target'),
    'back_policy' => trans_html('Back to proposal policy'),
    'applied_scope' => trans_html('Applied standing policy'),
    'scope_unknown' => trans_html('Policy scope was not recorded in this earlier decision.'),
    'values_error' => trans_html('Complete the GPA values in the default and each override before reviewing.'),
    'target_none' => trans_html('No active policy targets match on this page.'),
    'more_targets' => trans_html('More policy targets'),
    'previous_targets' => trans_html('Previous policy targets'),
    'search_targets' => trans_html('Search policy targets'),
];
