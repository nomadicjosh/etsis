<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Correct application program'),
    'context' => trans_html('ADMISSIONS PROGRAM'),
    'back' => trans_html('Back to application'),
    'loading' => trans_html('Loading application program…'),
    'current' => trans_html('Current planned program'),
    'help' => trans_html(
        'Correct the planned program before an admissions decision. Original submission and answers '
        . 'remain unchanged. Form-based applications must use the same shared academic-level form. A '
        . 'fresh admissions review is required; no student is created.'
    ),
    'closed' => trans_html('Only applications awaiting an admissions decision can change programs here.'),
    'program_form' => trans_html(
        'This application uses a program-specific form. Review a new application for the intended '
        . 'program; do not reuse program-specific answers automatically.'
    ),
    'pending' => trans_html(
        'Resolve pending answer corrections or withdrawal requests before changing the program.'
    ),
    'denied' => trans_html('Access changed. Return to the application to review your available actions.'),
    'load_failed' => trans_html('The application could not be loaded. Return to the application and try again.'),
    'search' => trans_html('Program code or title'),
    'find' => trans_html('Find programs'),
    'available' => trans_html('Available programs'),
    'pages' => trans_html('Program search pages'),
    'choose' => trans_html('Choose a different program, then enter the correction reason.'),
    'reason' => trans_html('Reason for program correction'),
    'review' => trans_html('Review program correction'),
    'finding' => trans_html('Finding programs…'),
    'search_changed' => trans_html('Search changed. Choose Find programs.'),
    'first' => trans_html('First results'),
    'more' => trans_html('More programs'),
    'empty' => trans_html('No matching programs. Review program setup with the catalog office.'),
    'search_failed' => trans_html('Programs could not be loaded. Your selected program and reason are unchanged.'),
    'preparing' => trans_html('Preparing program review…'),
    'review_failed' => trans_html('Review could not be completed. Your program and reason are still shown.'),
    'before' => trans_html('Before correction'),
    'after' => trans_html('After correction'),
    'confirm' => trans_html('Confirm program correction'),
    'revise' => trans_html('Revise'),
    'saving' => trans_html('Saving program correction…'),
    'saved' => trans_html('Program corrected. The application still awaits a decision.'),
    'ready' => trans_html('Review ready. Confirm to save.'),
    'commit_failed' => trans_html(
        'The correction could not be completed. Retry this confirmation or revise the review.'
    ),
    'leave' => trans_html('Leave the unfinished program correction?'),
    'history' => trans_html('Application program corrected'),
];
