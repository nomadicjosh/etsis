<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Notifications'),
    'recent' => trans_html('Recent workspace notifications'),
    'notification' => trans_html('Workspace notification'),
    'close' => trans_html('Close notifications'),
    'dismiss' => trans_html('Dismiss notification'),
    'clear' => trans_html('Clear recent notifications'),
    'empty' => trans_html('There are no notifications from this workspace session.'),
    'session_help' => trans_html(
        'Recent action feedback stays only in this page. Reloading or signing out clears it. '
        . 'Recorded decisions and background job status remain in their own workspaces.',
    ),
    'sound' => trans_html('Play notification sounds'),
    'keep_visible' => trans_html('Keep notifications on screen until dismissed'),
    'preference_help' => trans_html(
        'Only these display and sound preferences are saved in this browser. '
        . 'Notification messages and campus records are never saved here.',
    ),
    'test_sound' => trans_html('Test notification sound'),
    'sound_unavailable' => trans_html('Sound is unavailable. Visual notifications will still appear.'),
    'success' => trans_html('Completed'),
    'info' => trans_html('Update'),
    'warning' => trans_html('Needs attention'),
    'export_queued' => trans_html('Export queued. Its download will be available after preparation finishes.'),
    'export_ready' => trans_html('Your report export is ready. Open the export controls to download it.'),
    'export_failed' => trans_html('The report export could not finish. Review its status before trying again.'),
    'export_cancelled' => trans_html('Report export cancelled.'),
    'delivery_completed' => trans_html('Delivery processing finished. Review confirmed sends and closed outcomes.'),
    'recipients_queued' => trans_html('Recipients queued. No messages have been sent.'),
    'delivery_queued' => trans_html('Background delivery confirmed. You can leave this screen.'),
    'delivery_held' => trans_html('Delivery needs review. Unconfirmed messages will not be retried automatically.'),
    'delivery_blocked' => trans_html('Delivery stopped. Review access, recipients and institutional mail settings.'),
];
