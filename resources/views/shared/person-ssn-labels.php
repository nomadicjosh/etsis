<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Social Security number'),
    'back' => trans_html('Back to record'),
    'saved' => trans_html('Saved SSN'),
    'none' => trans_html('No saved SSN'),
    'help' => trans_html('Saved numbers show only the last four digits. Enter a full replacement for a correction.'),
    'replacement' => trans_html('Replacement SSN'),
    'confirmation' => trans_html('Enter replacement SSN again'),
    'reason' => trans_html('Reason for SSN correction'),
    'reason_help' => trans_html('Explain the correction without including the SSN or other nine-digit numbers.'),
    'review' => trans_html('Review SSN correction'),
    'clear' => trans_html('Clear saved SSN'),
    'before' => trans_html('Before'),
    'after' => trans_html('After'),
    'masked_help' => trans_html('Only the last four digits are shown, even when a correction changes earlier digits.'),
    'confirm' => trans_html('Confirm SSN correction'),
    'edit' => trans_html('Keep editing'),
    'reload' => trans_html('Reload saved mask'),
    'history' => trans_html('SSN correction history'),
    'more' => trans_html('More SSN history'),
    'previous' => trans_html('Previous SSN history'),
    'empty' => trans_html('No SSN decisions have been recorded.'),
    'recorded' => trans_html('Number recorded'),
    'cleared' => trans_html('Number cleared'),
    'success' => trans_html('SSN correction recorded. Only the saved last four digits are displayed.'),
    'readonly' => trans_html('Your access permits masked viewing. Editing requires the separate SSN correction duty.'),
    'limit' => trans_html('This record needs operator review before another SSN decision can be recorded.'),
    'denied' => trans_html('SSN access changed. Reopen the person record before continuing.'),
    'error' => trans_html('The SSN task could not be completed. Review the current record before trying again.'),
    'reload_help' => trans_html('Reloading preserves your replacement draft and requires a new correction review.'),
];
