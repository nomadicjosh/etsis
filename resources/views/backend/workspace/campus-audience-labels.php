<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'roles' => trans_html('Selected account roles'),
    'help' => trans_html(
        'Choose up to ten roles. An account needs any selected role to read this news or see its announcement. '
        . 'Role assignments are checked when reading; '
        . 'permissions and person capabilities do not substitute for a role.',
    ),
    'search' => trans_html('Search account roles'),
    'find' => trans_html('Find roles'),
    'available' => trans_html('Available roles'),
    'selected' => trans_html('Selected roles'),
    'remove' => trans_html('Remove role: {role}'),
    'next' => trans_html('Next roles'),
    'previous' => trans_html('Previous roles'),
    'loading' => trans_html('Loading account roles…'),
    'failed' => trans_html('Roles could not load. Use Find roles to try again.'),
    'empty' => trans_html('No account roles match this search.'),
    'none' => trans_html('No roles selected.'),
    'limit' => trans_html('Select one to ten account roles before saving.'),
    'unknown' => trans_html('Audience roles need review'),
    'public' => trans_html('Public website'),
    'campus' => trans_html('Signed-in campus'),
    'publish' => trans_html(
        'Publish this saved news for accounts currently assigned any selected role. '
        . 'Other accounts cannot read it through the bell, campus news or a direct link.',
    ),
];
