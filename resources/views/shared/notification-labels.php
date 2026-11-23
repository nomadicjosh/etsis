<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'announcements' => trans_html('Campus announcements'),
    'announcement_publish_help' => trans_html(
        'After approval, campus news appears in the notification bell for signed-in applicants, students and staff. '
        . 'Publication times control its availability. Public website news is separate.',
    ),
    'announcement_changed' => trans_html('This announcement changed or is unavailable. Refresh the list.'),
    'announcement_failed' => trans_html('Announcements could not load. Use Refresh announcements to try again.'),
    'announcement_loading' => trans_html('Loading campus announcements…'),
    'announcement_new' => trans_html('New campus announcements are available.'),
    'announcement_unread' => trans_html('Unread'),
    'announcement_read' => trans_html('Read'),
    'announcement_pages' => trans_html('Announcement pages'),
    'announcement_previous' => trans_html('Previous announcements'),
    'announcement_more' => trans_html('More announcements'),
    'announcement_empty' => trans_html('There are no matching campus announcements.'),
    'announcement_loaded' => trans_html('Campus announcements loaded. Reading state is saved to your account.'),
    'announcement_mark' => trans_html('Mark as read'),
    'announcement_marked' => trans_html('Marked as read. This is not a policy acknowledgement.'),
    'announcement_back' => trans_html('Back to list'),
    'announcement_refresh' => trans_html('Refresh announcements'),
    'announcement_help' => trans_html(
        'Published campus news is available to signed-in applicants, students and staff. '
        . 'Open an announcement before marking its current version as read.',
    ),
    'announcement_include' => trans_html('Show read items'),
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
    'sound_choice' => trans_html('Notification sound'),
    'sound_help' => trans_html('Choose a sound, then test it. Changing this choice does not play audio.'),
    'sound_tone' => trans_html('Short tone'),
    'sound_message' => trans_html('Message'),
    'sound_warm' => trans_html('Warm success'),
    'sound_bird' => trans_html('Bird'),
    'sound_piano' => trans_html('Piano'),
    'sound_loading' => trans_html('Preparing sound…'),
    'sound_played' => trans_html('Sound preview played.'),
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
    'contact_saved' => trans_html('Your contact information is up to date.'),
    'application_submitted' => trans_html('Application submitted. Here’s to your next chapter.'),
    'section_changed' => trans_html(
        'Your section was changed. The original course charges were reversed and replacement charges applied together.',
    ),
    'course_removed' => trans_html(
        'Your course was removed. Its course charge was reversed if present. '
        . 'Any account credit is reviewed separately for a cash refund.',
    ),
    'course_withdrawn' => trans_html(
        'Your course stays on your record with grade {grade} and zero earned credits. Its course charge is retained.',
    ),
    'sections_registered' => trans_html('{count} course sections registered together.'),
    'registered' => trans_html('You’re registered. Your semester is taking shape.'),
    'transcript_requested' => trans_html('Your request is with the registrar. Follow its progress below.'),
    'transcript_cancelled' => trans_html('Transcript request cancelled.'),
];
