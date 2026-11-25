<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    'policy' => trans('Standing proposal policy'),
    'setup' => trans('Institution setup'),
    'intro' => trans(
        'Publish GPA floors for proposed standing. The highest floor met by the current pursuit ' .
        'institutional GPA applies. Staff must review and confirm each period; this does not ' .
        'change registration or student activity.'
    ),
    'enabled' => trans('Enable institutional GPA standing proposals'),
    'minimum_credits' => trans('Minimum institutional GPA credits'),
    'minimum_help' => trans(
        'Count GPA credits after retained repeat rules. Undefined GPA never means zero GPA. This ' .
        'is a current cumulative assessment, not a term or backdated GPA calculation.'
    ),
    'floor' => trans('GPA floor'),
    'band' => trans('GPA band'),
    'choose' => trans('Choose standing definition'),
    'choose_help' => trans(
        'Choose an active institutional definition. Published labels remain retained; later label ' .
        'changes require policy review.'
    ),
    'add' => trans('Add GPA band'),
    'remove' => trans('Remove GPA band'),
    'empty' => trans('No GPA bands configured. Add a zero floor before enabling proposals.'),
    'review' => trans('Review proposal policy'),
    'review_title' => trans('Review standing proposal policy'),
    'publish' => trans('Publish proposal policy'),
    'published' => trans('Standing proposal policy published.'),
    'reason' => trans('Reason for policy publication'),
    'back' => trans('Back to record'),
    'edit' => trans('Keep editing'),
    'current_version' => trans('Published policy version'),
    'off' => trans('Proposals disabled'),
    'on' => trans('Proposals enabled'),
    'find' => trans('Find a standing definition'),
    'search' => trans('Search definitions'),
    'more' => trans('More definitions'),
    'previous' => trans('Previous definitions'),
    'none' => trans('No active standing definitions match.'),
    'error' => trans('The request could not be completed. Your draft remains available.'),
    'denied' => trans('Standing policy access is no longer available to this account.'),
    'calculate' => trans('Propose from current GPA'),
    'calculated' => trans('Current institutional GPA proposal'),
    'gpa' => trans('Institutional GPA'),
    'credits' => trans('GPA credits'),
    'as_of' => trans('Calculated using current records on'),
    'proposal_help' => trans(
        'Complete the effective dates and review the new period. Grades, requirements or policy ' .
        'changes require a fresh proposal. Current GPA does not reconstruct standing eligibility ' .
        'on an earlier date.'
    ),
    'manual' => trans('This period now uses manual standing review; its GPA proposal evidence was removed.'),
    'clear' => trans('Use manual review instead'),
    'history' => trans('Accepted GPA proposal evidence'),
    'unconfigured' => trans('No standing proposal policy has been published. Proposals are unavailable.'),
    'refresh' => trans('Refresh policy version'),
    'refresh_help' => trans(
        'Refresh preserves your draft. Review the latest published rules before confirming a ' .
        'replacement.'
    ),
    'before' => trans('Currently published'),
    'after' => trans('Proposed policy'),
];
