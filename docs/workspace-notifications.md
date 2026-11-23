# Workspace and portal toast notifications

Implemented November 23, 2026. Staff and signed-in portal action feedback use the same locally hosted Bootstrap Toast component, colors, icons and accessible controls. The four supplied sound files are selectable. The same bell also provides [persistent campus announcements](campus-announcements.md); automatic domain-triggered/push/email delivery remains separate roadmap work.

## Use notifications

Successful workspace actions can show **Completed**, **Update** or **Needs attention** feedback. A toast never moves keyboard focus when it appears. Use **Dismiss notification** to close it. Ordinary toasts disappear after eight seconds; Bootstrap pauses dismissal while the pointer or keyboard focus is within the toast.

The bell in the workspace header opens **Recent workspace notifications**. It retains the newest twenty messages from this page, even after their toasts disappear. The badge counts recent messages, not unread institutional records. **Clear recent notifications** removes this temporary feedback only. It does not cancel jobs, delete audit/history or change recorded decisions. Closing the panel returns focus to the bell.

Expand **Campus announcements** in this panel for reviewed campus news and account-specific read state. It loads separately on demand and retains its read state on the server. Clearing recent action feedback does not clear announcements or mark them read. See [publication, reading and sound behavior](campus-announcements.md).

Only five toasts are visible at once. Further feedback remains available in the recent list. Toasts render as literal text, including any angle brackets in a message. They appear within the active drawer, modal or native report dialog so they remain visible and their dismiss controls work with the active focus boundary. The recent panel, cards and controls reflow on narrow screens and with enlarged text. Toasts do not animate.

## Sound and display preferences

**Play notification sounds** defaults off. Open the header bell, choose **Notification sound**, then use **Test notification sound**. Available choices are **Short tone**, **Message**, **Warm success**, **Bird** and **Piano**. Short tone retains the earlier synthesized tone. The other four choices use the files supplied in `resources/sounds/`. Changing the choice stays silent; testing also enables sound. A status message confirms preview or explains that sound is unavailable. Browser interaction may be required before audio can play; missing, unsupported or blocked audio leaves visual feedback working.

Ordinary sounds are limited to one every two seconds, including when several notifications await the same browser audio unlock or file load. A longer file plays alone; further notifications do not overlap it. Preview replaces any current sound. Background tabs remain silent. Muting, changing sound, clearing private feedback, hiding the tab or leaving the page stops playback and cancels pending file loading. A delayed unlock/load cannot play an obsolete choice. Reopening recent history and dismissing a toast do not play sounds.

Files load on demand from a fixed same-origin allowlist, without credentials or redirects. Nothing is downloaded just by selecting a choice, loading a page or leaving sound off. One decoded file is retained per current audio context; concurrent requests share one pending load, with an eight-second deadline, a two-MiB file limit and an eight-second playback ceiling. The supplied files are all shorter/smaller than those limits. No external sound provider is contacted. `npm run build:assets` copies only the four named source files to `public/assets/sounds/`; deploy those generated assets with the application. The source files are preserved unchanged.

Enable **Keep notifications on screen until dismissed** to remove the automatic timeout. This preference applies to currently visible feedback as well as future messages. Keyboard/pointer reading and explicit dismissal remain available.

Only `{sound, keep_visible, sound_id}` is saved in this browser, under `etsis.workspace.notification-preferences`. The booleans and allowlisted short sound ID are shared between the staff workspace and signed-in portal on this origin, and read when a page loads. They are browser preferences rather than account records. Earlier two-boolean preferences retain their settings with Short tone selected. Invalid sound IDs fall back to Short tone; stored URLs cannot select an audio source. Oversized/unrecognized preferences or blocked storage fall back safely. Notification messages, identities, job details and other private information are never written to local or session storage.

## Student and applicant portal

Signed-in campus pages have a **Recent notifications** bell, using the same recent-history, dismissal, sound and keep-visible controls. Guests have no private feedback panel or sound downloads. Contact updates, submitted applications, accepted registrations, section changes, drops/withdrawals and transcript request/cancellation acknowledgements use shared toasts. Their original CSRF, owner checks, review commands, preserved facts and request payloads remain in force. Queued transcript requests remain requests, not completed transcript delivery.

Messages are local to the current page, including when moving between portal services or opening the staff workspace. The panel is not an unread campus inbox. Admissions updates, booking updates, records and request status retain their existing private service views. No extra poll, message send, notification table or delivery worker was added. HTTP401/403 and page leave clear recent feedback and stop audio. Toast dismissal inside a modal restores a usable focus target; enlarged portal headings wrap on narrow screens. The assignment-score drawer now passes the portal's actual node factory to the shared gradebook presenter. Service panels retain a connected element so existing stale-response checks do not mistake a rendered page for a disconnected document fragment; replacing the service still disconnects the old panel.

## Connected background outcomes

Existing recipient-queue and delivery-authorization confirmations use informational feedback. **Recipients queued. No messages have been sent.** keeps queue acceptance distinct from delivery. These notifications do not send or retry messages.

An open communications progress screen can announce a transition from active processing to completed, held or blocked. A completed process may include outcomes closed without resending; the notification does not claim every message was sent. Held or blocked processing asks staff to review the existing source controls. Opening an already finished campaign or refreshing an unchanged outcome produces no duplicate completion notification.

Report Studio distinguishes **Export queued** from **Your report export is ready**. It announces genuine pending-to-ready, failed or cancelled changes and handles a preparation acknowledgement that is already ready. Opening a previously completed export does not replay an old completion alert. Existing generation/connected-view checks prevent an obsolete or closed report response from showing a new toast. The report's status and private download controls remain authoritative; no additional report, dataset, background poll or export is introduced.

## Privacy and validation

Messages live only in a bounded in-memory list. Reloading, leaving the page or signing out clears them. Workspace HTTP 401/403 responses clear visible and recent feedback before existing sign-in/access-error handling continues. Toasts contain safe action/outcome wording, not message bodies, addresses, export rows, provider responses or raw events. Existing business authorization, CSRF, command reviews and audit/history remain unchanged.

Fictional browser checks cover bounded bursts, literal rendering, focus, timed/persistent feedback, sound opt-in/rate limits, local choices/caching, load timeout, delayed unlock/load and mute/choice/background races, blocked audio/storage, reload/privacy clearing, active drawer/modal/native-dialog placement, keyboard use and WCAG narrow/enlarged-text review. Audio playback uses injected contexts; actual WAV/MP3 decoding is checked in an offline browser audio context without device playback. Portal actions retain their original requests; export and communications checks use injected providers and confirm that feedback does not issue sending requests. Native device audio and broader institution-led browser/screen-reader qualification remain deployment acceptance work.
