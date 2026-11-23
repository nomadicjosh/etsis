<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

// Escaped translations remain text in the browser; no HTML insertion is used.
return [
    'scope_help' => trans_html(
        'Holds do not share files, extend applicant access, or restore removed files.',
    ),
    'unavailable_account' => trans_html('Unavailable account'),
    'preservation' => trans_html('Document preservation'),
    'holds_help' => trans_html(
        'Holds protect existing and future admissions files from removal.',
    ),
    'finish_change' => trans_html(
        'Finish or cancel the document hold change first.',
    ),
    'scope_application' => trans_html('This application'),
    'scope_person' => trans_html('All applications for this person'),
    'review_heading' => trans_html('Document hold review'),
    'review_change' => trans_html('Review hold change'),
    'cancel_change' => trans_html('Cancel hold change'),
    'hold_reason' => trans_html('Reason for hold'),
    'release_reason' => trans_html('Reason for release'),
    'reviewing' => trans_html('Reviewing document hold…'),
    'place_confirmation' => trans_html(
        'Confirm this hold. File holds and decision evidence remain separate.',
    ),
    'release_confirmation' => trans_html(
        'Release this scope’s hold. Other holds, deadlines and decision evidence still apply.',
    ),
    'confirm_hold' => trans_html('Confirm hold'),
    'confirm_release' => trans_html('Confirm release'),
    'saving' => trans_html('Saving document hold…'),
    'saved' => trans_html('Document hold change saved.'),
    'revise_change' => trans_html('Revise hold change'),
    'review_ready' => trans_html('Review ready. Confirm or revise this change.'),
    'active' => trans_html('Hold active'),
    'inactive' => trans_html('No active hold'),
    'release_application' => trans_html('Release application hold'),
    'place_application' => trans_html('Place application hold'),
    'release_person' => trans_html('Release person hold'),
    'place_person' => trans_html('Place person hold'),
    'history' => trans_html('Hold history'),
    'released' => trans_html('Hold released'),
    'history_empty' => trans_html('No document hold decisions recorded.'),
    'loading_history' => trans_html('Loading hold history…'),
    'history_loaded' => trans_html('Hold history loaded.'),
    'newer_history' => trans_html('Newer holds'),
    'earlier_history' => trans_html('Earlier holds'),
    'protected_file' => trans_html(
        'A case or person hold protects this file from removal.',
    ),
];
