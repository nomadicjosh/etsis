# Workspace toast notifications

Implemented November 23, 2026. Backend action feedback now uses the existing locally hosted Bootstrap Toast component, with the workspace's colors, icons and accessible controls. This covers transient staff action feedback. Persistent campus announcements and portal-wide notification delivery remain separate roadmap work.

## Use notifications

Successful workspace actions can show **Completed**, **Update** or **Needs attention** feedback. A toast never moves keyboard focus when it appears. Use **Dismiss notification** to close it. Ordinary toasts disappear after eight seconds; Bootstrap pauses dismissal while the pointer or keyboard focus is within the toast.

The bell in the workspace header opens **Recent workspace notifications**. It retains the newest twenty messages from this page, even after their toasts disappear. The badge counts recent messages, not unread institutional records. **Clear recent notifications** removes this temporary feedback only. It does not cancel jobs, delete audit/history or change recorded decisions. Closing the panel returns focus to the bell.

Only five toasts are visible at once. Further feedback remains available in the recent list. Toasts render as literal text, including any angle brackets in a message. They appear within the active drawer, modal or native report dialog so they remain visible and their dismiss controls work with the active focus boundary. The recent panel, cards and controls reflow on narrow screens and with enlarged text. Toasts do not animate.

## Sound and display preferences

**Play notification sounds** defaults off. Turn it on in the recent-notifications panel and use **Test notification sound** to check the short, quiet tone. Testing sound also enables the preference. The browser may require interaction before enabling audio; unavailable or blocked audio leaves visual feedback working.

Ordinary sounds are limited to one every two seconds, including when several notifications await the same browser audio unlock. Background tabs remain silent. Muting, clearing private feedback or leaving the page closes the audio context; a delayed unlock cannot play a tone after muting or after the tab becomes hidden. Reopening recent history and dismissing a toast do not play sounds.

Enable **Keep notifications on screen until dismissed** to remove the automatic timeout. This preference applies to currently visible feedback as well as future messages. Keyboard/pointer reading and explicit dismissal remain available.

Only the two boolean sound/display preferences are saved in this browser, under `etsis.workspace.notification-preferences`. They are browser preferences rather than account records. Notification messages, identities, job details and other private information are never written to local or session storage. If browser storage is disabled or contains an invalid preference shape, feedback still works using page-local settings.

## Connected background outcomes

Existing recipient-queue and delivery-authorization confirmations use informational feedback. **Recipients queued. No messages have been sent.** keeps queue acceptance distinct from delivery. These notifications do not send or retry messages.

An open communications progress screen can announce a transition from active processing to completed, held or blocked. A completed process may include outcomes closed without resending; the notification does not claim every message was sent. Held or blocked processing asks staff to review the existing source controls. Opening an already finished campaign or refreshing an unchanged outcome produces no duplicate completion notification.

Report Studio distinguishes **Export queued** from **Your report export is ready**. It announces genuine pending-to-ready, failed or cancelled changes and handles a preparation acknowledgement that is already ready. Opening a previously completed export does not replay an old completion alert. Existing generation/connected-view checks prevent an obsolete or closed report response from showing a new toast. The report's status and private download controls remain authoritative; no additional report, dataset, background poll or export is introduced.

## Privacy and validation

Messages live only in a bounded in-memory list. Reloading, leaving the page or signing out clears them. Workspace HTTP 401/403 responses clear visible and recent feedback before existing sign-in/access-error handling continues. Toasts contain safe action/outcome wording, not message bodies, addresses, export rows, provider responses or raw events. Existing business authorization, CSRF, command reviews and audit/history remain unchanged.

Fictional browser checks cover bounded bursts, literal rendering, focus, timed/persistent feedback, sound opt-in/rate limits, delayed unlock and mute/background races, blocked audio/storage, reload/privacy clearing, active drawer/native-dialog placement, keyboard use and WCAG narrow/enlarged-text review. Export and communications checks use injected providers and confirm that feedback does not issue sending requests. Native device audio and broader institution-led browser/screen-reader qualification remain deployment acceptance work.
