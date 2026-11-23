<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

// Escaped translations remain text in the browser; no HTML insertion is used.
return [
    'Holds do not share files, extend applicant access, or restore removed files.' => trans_html(
        'Holds do not share files, extend applicant access, or restore removed files.',
    ),
    'Unavailable account' => trans_html('Unavailable account'),
    'Document preservation' => trans_html('Document preservation'),
    'Holds protect existing and future admissions files from removal.' => trans_html(
        'Holds protect existing and future admissions files from removal.',
    ),
    'Finish or cancel the document hold change first.' => trans_html(
        'Finish or cancel the document hold change first.',
    ),
    'This application' => trans_html('This application'),
    'All applications for this person' => trans_html('All applications for this person'),
    'Document hold review' => trans_html('Document hold review'),
    'Review hold change' => trans_html('Review hold change'),
    'Cancel hold change' => trans_html('Cancel hold change'),
    'Reason for hold' => trans_html('Reason for hold'),
    'Reason for release' => trans_html('Reason for release'),
    'Reviewing document hold…' => trans_html('Reviewing document hold…'),
    'Confirm this hold. File holds and decision evidence remain separate.' => trans_html(
        'Confirm this hold. File holds and decision evidence remain separate.',
    ),
    'Release this scope’s hold. Other holds, deadlines and decision evidence still apply.' => trans_html(
        'Release this scope’s hold. Other holds, deadlines and decision evidence still apply.',
    ),
    'Confirm hold' => trans_html('Confirm hold'),
    'Confirm release' => trans_html('Confirm release'),
    'Saving document hold…' => trans_html('Saving document hold…'),
    'Document hold change saved.' => trans_html('Document hold change saved.'),
    'Revise hold change' => trans_html('Revise hold change'),
    'Review ready. Confirm or revise this change.' => trans_html('Review ready. Confirm or revise this change.'),
    'Hold active' => trans_html('Hold active'),
    'No active hold' => trans_html('No active hold'),
    'Release application hold' => trans_html('Release application hold'),
    'Place application hold' => trans_html('Place application hold'),
    'Release person hold' => trans_html('Release person hold'),
    'Place person hold' => trans_html('Place person hold'),
    'Hold history' => trans_html('Hold history'),
    'Hold released' => trans_html('Hold released'),
    'No document hold decisions recorded.' => trans_html('No document hold decisions recorded.'),
    'Loading hold history…' => trans_html('Loading hold history…'),
    'Hold history loaded.' => trans_html('Hold history loaded.'),
    'Newer holds' => trans_html('Newer holds'),
    'Earlier holds' => trans_html('Earlier holds'),
    'A case or person hold protects this file from removal.' => trans_html(
        'A case or person hold protects this file from removal.',
    ),
];
