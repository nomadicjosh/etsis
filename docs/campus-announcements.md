# Campus announcements

Implemented November 23, 2026. Reviewed **campus news** now appears under **Campus announcements** in the notification bell on signed-in portal and staff pages. It uses the existing news content, author/publisher duties, approval receipts and publication windows. There is no separate announcement copy, recipient fan-out or second event store.

## Publish an announcement

1. Open **Campus content**, create a **News update** and select **Signed-in campus**. This audience includes signed-in applicants, students and staff; it is not restricted to people who already have a student record.
2. Save the draft and request review. A publisher with the existing content-management and publishing duties reviews and approves it. Authors with both duties can review their own saved drafts under the existing policy.
3. Publish immediately or choose the existing publication start/end controls. Dates are evaluated on each server request: the start is inclusive and the end is exclusive. A scheduled announcement becomes available when readers next open or refresh the list. No publishing worker is required.

Public website news stays in the public news workflow and does not enter this private announcement list. Text pages and links are also outside this list. Earlier content without an explicit saved campus audience is not inferred to be an announcement. Draft, pending, archived, not-yet-visible and expired news are unavailable through both the list and direct announcement API.

Existing explicit campus publications remain their existing content facts. This reader creates no inferred historical approval, publication or read event for earlier records.

Editing approved news returns it to draft. Its revised wording requires fresh approval before appearing again. Republishing creates a new content revision, so someone who read an earlier revision sees the revised announcement as unread. Archiving removes the item from this reader through the existing content workflow; it does not erase the original content or reading metadata.

## Read and return

Open the header bell, then expand **Campus announcements**. The list loads on demand, twenty items per page, newest content identities first. Use **More announcements** and **Previous announcements** to navigate the live list; close and reopen the section to reload it. There is no background polling. New publication or read changes can move items between pages; reopen from the beginning for the current list.

Open a title to read the full announcement and automatically mark that displayed revision read. **Back to list** returns to the accepted page. Read and unread notices stay listed until deleted. Reopening the panel or signing in again reads persisted state from the server. Another account has its own state, even if it has registrar or publishing permissions.

The reader preserves approved formatting and useful links using the existing server sanitizer and local DOMPurify renderer. Plain text retains literal angle brackets. The drawer/dialog keeps keyboard focus, visible errors and narrow-screen reflow; closing the disclosure or panel discards its private page contents and ignores late responses.

Read state means that this account opened the displayed revision. It is **not a policy acknowledgement, proof of understanding, required acceptance or legal delivery receipt**. A stale request cannot hide a later publication. Repeat requests for the same available revision retain the original read timestamp without another audit entry. Each account/content pair keeps one current revision and read timestamp, not a historical acknowledgement ledger.

## Delete an announcement from your drawer

Use **Delete announcement** beside a list item or inside its opened detail. The server physically deletes this account's read metadata and hides the displayed publication. Reopening and reloading do not restore it. Other accounts and the underlying published campus news remain unchanged; deleting a personal notification does not archive institutional content.

Because an announcement is shared news rather than a separate copy for each reader, the server retains one minimal account/content/revision suppression marker. It has no wording, read timestamp or revision history. This prevents the same publication from immediately being delivered again. A later reviewed republication has a higher revision and can appear as a new unread announcement. Retrying deletion of the earlier revision never hides that newer revision. There is no notification trash/archive or undo screen.

Deletion requires the displayed revision, current signed-in ownership and CSRF. The content lock, mandatory audit and final source/owner checks share one transaction; failures retain the original state. The audit records only the actor, action and content reference, not a notification body. Deleting again is idempotent. The user has explicitly requested disposable notifications rather than historical notification preservation.

## Sound and temporary feedback

Loading, reading, paging and reopening are silent. There is no refresh toolbar in the notification center and no duplicate action toast for reading or deleting a notice. Sound/display settings are under the person's workspace drawer or the portal's **Personal information** screen; see [notification preferences](workspace-notifications.md#sound-and-display-preferences).

**Clear recent notifications** clears temporary action feedback only. Announcement content and account read state remain in their own workflow. No announcement content, identities, reading state or notification preferences enter browser storage.

## Privacy and implementation

The shared portal API is also used by the staff bell:

- `GET /portal/api/announcements/?include_read=1&after={content-id}` lists the account's current page. Omit `include_read` for unread only; the response supplies `next_after` when another page exists. The page contains titles and revisions, not full bodies or another reader's data.
- `GET /portal/api/announcements/{id}/` reads currently available campus news without changing read state.
- `POST /portal/api/announcements/{id}/read/` accepts the displayed `revision`, with the existing typed form envelope and CSRF token. The signed-in account supplies ownership; callers cannot select another reader.
- `POST /portal/api/announcements/{id}/delete/` accepts the displayed `revision` with the same protections, deletes this account's read metadata and suppresses that publication. Deleted current revisions are also unavailable through the private detail/read endpoints.

Every private read/write checks the current account. Responses are private/no-store and debug collectors stay suppressed. The read command locks the content row, rechecks current revision/audience/visibility, writes metadata and required audit atomically, and verifies the owner, content, expiry and saved state through final audit. Stale, unavailable or failed confirmations do not partially update read state. Read metadata contains only ULID identity, account/content references, revision and UTC timestamp. It creates no academic event and exposes no audience-wide reading report.

Migration `20261123000002_CreateCampusAnnouncementReads` adds the owner/content unique read-state table with existing account/content foreign keys. It changes no saved content, role grant, publication, academic projection or recorded event. SQLite behavior and five-dialect compilation supplement native MariaDB acceptance; they do not certify all live PDO drivers. The configured native database received the migration with all twenty protected events and journal support rows verified unchanged; its new table remains empty.

Migration `20261123000010_CreateCampusAnnouncementDismissals` adds the current-publication suppression table. Its unique account/content pair stores a single revision and ULID, with account/content foreign keys. No source publication or journal event is rewritten. The configured MariaDB now has migration 10; both new notification tables remain empty, and the twenty original protected event envelopes have the same SHA-256 aggregate as before migrations 9–10. Five-dialect compiler coverage supplements native MariaDB and SQLite checks without certifying every live driver.

Fictional checks cover publication/read/delete sequencing, missing/changed audiences, scheduling boundaries, stale reads/deletions, account isolation, paging, safe formatting, mandatory-audit/final-validation rollback, real routes/providers/CSRF/private responses, notification sound integration and closed/denied readers. Browser audio uses injected contexts. [Waitlist registration/removal updates](registration-history.md#private-waitlist-outcome-updates) now provide one automatic domain trigger. Custom recipient audiences, further triggers and push/email delivery remain separate roadmap work. Personal notifications require no historical archive.
