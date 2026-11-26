<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'term_context' => trans_html('ACADEMIC TERM'),
    'term_default' => trans_html('Reporting-group default'),
    'loading_default' => trans_html('Loading term default…'),
    'no_default' => trans_html('No term default'),
    'default_help' => trans_html(
        'This is a preset for section drafts. '
        . 'Staff must copy it into a section and save their choice before publication. '
        . 'Changing or clearing this default leaves existing sections and student history unchanged.',
    ),
    'default_retired' => trans_html(
        'This reporting group is retired. Choose an active group before using the preset on another section.',
    ),
    'back_default' => trans_html('Back to term default'),
    'clear_default' => trans_html('Clear term default'),
    'save_default' => trans_html('Save term default'),
    'back_record' => trans_html('Back to record'),
    'default_saved' => trans_html('Term default saved. Existing section choices are unchanged.'),
    'use_default' => trans_html('Copy term default'),
    'copy_help' => trans_html(
        'Copying fills this draft only. Save section setup, then review publication. '
        . 'Later term-default changes do not update this choice.',
    ),
    'default_copied' => trans_html('Term default copied into this draft.'),

    'group' => trans_html('Reporting group'),
    'groups' => trans_html('Reporting groups'),
    'choose_group' => trans_html('Choose reporting group'),
    'clear_group' => trans_html('Clear reporting group'),
    'find_groups' => trans_html('Find reporting groups'),
    'add_group' => trans_html('Add reporting group'),
    'edit_group' => trans_html('Edit reporting group'),
    'group_code' => trans_html('Reporting group code'),
    'group_name' => trans_html('Reporting group name'),
    'back_groups' => trans_html('Back to reporting groups'),
    'choose' => trans_html('Choose'),
    'edit' => trans_html('Edit'),
    'group_help' => trans_html(
        'An optional institutional reporting period or group shared by related sections. '
        . 'It does not change instructional terms, dates, credits, charges or census rules.',
    ),
    'section_setup' => trans_html('Section classifications'),
    'current_reporting_group' => trans_html('Current section reporting group'),
    'current_reporting_group_id' => trans_html('Current reporting group reference'),
    'current_reporting_group_code' => trans_html('Current reporting group code'),
    'current_reporting_group_title' => trans_html('Current reporting group name'),
    'retained_reporting_group_id' => trans_html('Retained reporting group reference'),
    'retained_reporting_group_code' => trans_html('Retained reporting group code'),
    'retained_reporting_group_title' => trans_html('Retained reporting group name'),
    'retained_reporting_group_recorded' => trans_html('Reporting group choice recorded in accepted offering'),
];
