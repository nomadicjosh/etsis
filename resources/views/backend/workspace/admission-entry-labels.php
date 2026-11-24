<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'loading' => trans_html('Loading entry term…'),
    'load_failed' => trans_html('The intake could not be loaded. Return to the application and try again.'),
    'denied' => trans_html('Access changed. Return to the application to review your available actions.'),
    'previous' => trans_html('Previously approved intake'),
    'current' => trans_html('Current entry term'),
    'approval_help' => trans_html(
        'Keep this application and its answers. A new intake approval needs a fresh offer under institutional policy.'
    ),
    'correction_help' => trans_html(
        'Correct the planned intake before a decision. Keep original intake and answers; no student is created.'
    ),
    'withdrawal_pending' => trans_html('Resolve the pending withdrawal request before approving an intake.'),
    'answers_pending' => trans_html('Resolve the answer correction request before correcting the entry term.'),
    'closed' => trans_html('Only submitted or accepted, unmatriculated applications can receive an intake change.'),
    'calendar_changed' => trans_html(
        'The approved calendar changed. Review the current intake before issuing an offer or matriculating.'
    ),
    'review_current' => trans_html('Review current intake'),
    'choose_later' => trans_html('Choose later intake'),
    'choose_term' => trans_html('Correct entry term'),
    'replace_draft' => trans_html('Replace the unfinished intake review?'),
    'later_title' => trans_html('Choose a later intake'),
    'correct_title' => trans_html('Correct planned intake'),
    'updated_title' => trans_html('Review updated intake'),
    'search_placeholder' => trans_html('Term code or name'),
    'available' => trans_html('Available intakes'),
    'search_pages' => trans_html('Intake search pages'),
    'find' => trans_html('Find terms'),
    'find_intake' => trans_html('Find an intake'),
    'find_later' => trans_html('Find a later intake'),
    'finding_intakes' => trans_html('Finding available intakes…'),
    'finding_later' => trans_html('Finding later intakes…'),
    'search_changed' => trans_html('Search changed. Choose Find terms.'),
    'first' => trans_html('First results'),
    'more' => trans_html('More terms'),
    'choose_reason' => trans_html('Choose an intake, then add the reason.'),
    'no_intakes' => trans_html(
        'No matching intakes. Choose a term that has not started and has an academic year with valid dates.'
    ),
    'no_later' => trans_html('No matching later intakes. Review academic year and term setup with the catalog office.'),
    'search_failed' => trans_html('Could not find terms. Your reason and selected intake are unchanged.'),
    'updated_calendar' => trans_html('Updated calendar definition'),
    'review' => trans_html('Review intake'),
    'approval_reason' => trans_html('Reason for approval'),
    'correction_reason' => trans_html('Reason for correction'),
    'choose_first' => trans_html('Choose an intake first.'),
    'later_first' => trans_html('Choose a later intake first.'),
    'preparing' => trans_html('Preparing intake review…'),
    'review_heading' => trans_html('Review before confirming'),
    'before_correction' => trans_html('Before correction'),
    'after_correction' => trans_html('After correction'),
    'before_approval' => trans_html('Before approval'),
    'after_approval' => trans_html('After approval'),
    'approval_review_help' => trans_html(
        'Keep earlier terms, answers and offers. Issue a fresh offer for this intake; no student is created.'
    ),
    'correction_review_help' => trans_html(
        'The application stays awaiting a decision. Earlier intake and answers remain; no student is created.'
    ),
    'revise' => trans_html('Revise'),
    'approve' => trans_html('Approve intake'),
    'confirm_correction' => trans_html('Confirm entry-term correction'),
    'approved' => trans_html('Intake approved. Issue a fresh offer.'),
    'corrected' => trans_html('Entry term corrected. The application still awaits a decision.'),
    'commit_failed' => trans_html('The change could not be completed. Retry this confirmation or revise the review.'),
    'ready' => trans_html('Review ready. Confirm to save.'),
    'review_failed' => trans_html('Review could not be completed. Your reason and choice are still shown.'),
    'leave' => trans_html('Leave the unfinished intake review?'),
    'dates' => trans_html('{start} through {end}'),
    'year' => trans_html('Academic year {year}'),
    'no_year' => trans_html('Academic year not linked'),
    'institutional_id' => trans_html('Institutional ID: {id}'),
    'selected' => trans_html('Selected intake: {code}'),
    'selected_year' => trans_html('Selected intake: {code} · {year}'),
    'entry_title' => trans_html('Entry term'),
    'entry_context' => trans_html('ADMISSIONS INTAKE'),
    'back_application' => trans_html('Back to application'),
    'history_correct' => trans_html('Entry term corrected'),
    'history_recheck' => trans_html('Corrected intake calendar reviewed'),
    'history_defer' => trans_html('Entry deferred'),
    'history_reconfirm' => trans_html('Updated intake approved'),
];
