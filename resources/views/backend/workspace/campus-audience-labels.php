<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'programs' => trans_html('Selected student programs'),
    'program_help' => trans_html(
        'Choose up to ten programs. Active students need an active pursuit in any selected program, '
        . 'including a secondary pursuit, and an enabled linked account. Applications, closed pursuits and '
        . 'account roles do not qualify. Current membership is checked when reading.',
    ),
    'program_search' => trans_html('Search program codes or titles'),
    'program_find' => trans_html('Find programs'),
    'program_available' => trans_html('Available programs'),
    'program_selected' => trans_html('Selected programs'),
    'program_remove' => trans_html('Remove program: {program}'),
    'program_next' => trans_html('Next programs'),
    'program_previous' => trans_html('Previous programs'),
    'program_loading' => trans_html('Loading programs…'),
    'program_failed' => trans_html('Programs could not load. Use Find programs to try again.'),
    'program_empty' => trans_html('No programs match this search.'),
    'program_none' => trans_html('No programs selected.'),
    'program_limit' => trans_html('Select one to ten programs before saving.'),
    'program_unknown' => trans_html('Audience programs need review'),
    'program_publish' => trans_html(
        'Publish this saved news for active students with an active pursuit in any selected program '
        . 'and an enabled linked account. Other accounts cannot read it through the bell, '
        . 'campus news or a direct link.',
    ),
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
