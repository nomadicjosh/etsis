<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'remove' => trans_html(
        'Remove',
    ),
    'all_definitions' => trans_html(
        'All recorded standing definitions',
    ),
    'selected_definitions' => trans_html(
        'Selected standing definitions',
    ),
    'unavailable_definition' => trans_html(
        'Unavailable definition',
    ),
    'find_definitions' => trans_html(
        'Find definitions',
    ),
    'more_definitions' => trans_html(
        'More definitions',
    ),
    'previous_definitions' => trans_html(
        'Previous definitions',
    ),
    'available_definitions' => trans_html(
        'Available standing definitions',
    ),
    'find_definition' => trans_html(
        'Find a standing definition',
    ),
    'include_standings' => trans_html(
        'Standings to include',
    ),
    'selection_help' => trans_html(
        'Choose up to 25 standing definitions. Retired definitions can still identify earlier '
        . 'recorded periods. Transcripts keep the original recorded labels.',
    ),
    'selection_required' => trans_html(
        'Choose at least one definition or include all standings.',
    ),
    'remove_selection' => trans_html(
        'Remove selection',
    ),
    'loading_definitions' => trans_html(
        'Loading standing definitions…',
    ),
    'retired_definition' => trans_html(
        'Retired definition',
    ),
    'selection_hint' => trans_html(
        'Choose the definitions to include. Other recorded standings will be omitted.',
    ),
    'no_definitions' => trans_html(
        'No standing definitions match this search.',
    ),
    'lookup_failed' => trans_html(
        'Standing definitions could not be loaded. Your choices are kept; try again.',
    ),
    'setup_unavailable' => trans_html(
        'Transcript setup is no longer available. Reopen it after access is restored.',
    ),
    'setup_saved' => trans_html(
        'Transcript setup saved.',
    ),
    'newer_edits_unsaved' => trans_html(
        'Transcript setup saved. Newer edits are still unsaved.',
    ),
    'setup_selection_hint' => trans_html(
        'This setup selects particular standing definitions for new preparations.',
    ),
    'document_selection_hint' => trans_html(
        'This document includes institution-selected standing definitions.',
    ),
    'selected_standing_empty' => trans_html(
        'No selected academic standing is included through this date.',
    ),
    'periods' => trans_html(
        'Standing periods to include'
    ),
    'periods_all' => trans_html(
        'All recorded periods through preparation'
    ),
    'periods_current' => trans_html(
        'Only standing effective at preparation'
    ),
    'show_dates' => trans_html(
        'Print standing effective dates'
    ),
    'presentation_help' => trans_html(
        'These choices apply at the preparation cutoff. Existing originals keep their choices. Hidden ' .
        'effective dates do not hide the preparation cutoff or change standing records.'
    ),
    'current_hint' => trans_html(
        'Only the recorded standing effective at the preparation cutoff is included.'
    ),
    'all_hint' => trans_html(
        'Recorded standing periods through the preparation cutoff are included.'
    ),
    'dates_shown' => trans_html(
        'Standing effective dates are printed.'
    ),
    'dates_hidden' => trans_html(
        'Standing effective dates are omitted from this document.'
    ),
    'current_empty' => trans_html(
        'No standing is recorded for this date.'
    ),
    'current_selected_empty' => trans_html(
        'No selected academic standing is included for this date.'
    ),
    'content_help' => trans_html(
        'Off by default. Choose standing definitions, periods and whether to print effective dates. ' .
        'New preparations retain these choices under the selected primary pursuit. Staff reasons ' .
        'and policy references stay private.'
    ),
];
