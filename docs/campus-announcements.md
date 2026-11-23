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

Open the header bell, then expand **Campus announcements**. The list loads on demand, twenty items per page, newest content identities first. Use **More announcements**, **Previous announcements** and **Refresh announcements** to navigate the live list. There is no background polling. New publication or read changes can move items between pages; refresh from the beginning for the current list.

Open a title to read the full announcement, then use **Mark as read** if desired. Opening alone does not mark it read. **Back to list** returns to the accepted page and filter; **Show read items** includes announcements already read by this account. Reopening the panel or signing in again reads the persisted state from the server. Another account has its own state, even if it has registrar or publishing permissions.

The reader preserves approved formatting and useful links using the existing server sanitizer and local DOMPurify renderer. Plain text retains literal angle brackets. The drawer/dialog keeps keyboard focus, visible errors and narrow-screen reflow; closing the disclosure or panel discards its private page contents and ignores late responses.

Read state means that this account marked the displayed revision read. It is **not a policy acknowledgement, proof of understanding, required acceptance or legal delivery receipt**. A stale request cannot hide a later publication. Repeat requests for the same available revision retain the original read timestamp without another audit entry. Each account/content pair keeps one current revision and read timestamp, not a historical acknowledgement ledger.

## Sound and temporary feedback

Initial loading, opening older unread items, paging and marking read are silent. A deliberate refresh can show **New campus announcements are available** when a newer content identity or a changed previously observed revision appears. This uses the existing optional, rate-limited [notification sound](workspace-notifications.md#sound-and-display-preferences); it sends no message and does not mark anything read. The observed identity/version set is bounded to one hundred page-local entries. Reopening starts a fresh silent observation.

The bell's numeric badge still counts recent temporary action feedback. It is not an institution-wide unread-announcement count. **Clear recent notifications** clears temporary feedback only; announcement content and account read state remain in their own workflow. Sound/display preferences remain the only notification values saved in browser storage. Published wording, content identities, reading state and other institutional data are never written there.

## Privacy and implementation

The shared portal API is also used by the staff bell:

- `GET /portal/api/announcements/?include_read=1&after={content-id}` lists the account's current page. Omit `include_read` for unread only; the response supplies `next_after` when another page exists. The page contains titles and revisions, not full bodies or another reader's data.
- `GET /portal/api/announcements/{id}/` reads currently available campus news without changing read state.
- `POST /portal/api/announcements/{id}/read/` accepts the displayed `revision`, with the existing typed form envelope and CSRF token. The signed-in account supplies ownership; callers cannot select another reader.

Every private read/write checks the current account. Responses are private/no-store and debug collectors stay suppressed. The read command locks the content row, rechecks current revision/audience/visibility, writes metadata and required audit atomically, and verifies the owner, content, expiry and saved state through final audit. Stale, unavailable or failed confirmations do not partially update read state. Read metadata contains only ULID identity, account/content references, revision and UTC timestamp. It creates no academic event and exposes no audience-wide reading report.

Migration `20261123000002_CreateCampusAnnouncementReads` adds the owner/content unique read-state table with existing account/content foreign keys. It changes no saved content, role grant, publication, academic projection or recorded event. SQLite behavior and five-dialect compilation supplement native MariaDB acceptance; they do not certify all live PDO drivers. The configured native database received the migration with all twenty protected events and journal support rows verified unchanged; its new table remains empty.

Fictional checks cover publication/read sequencing, missing/changed audiences, scheduling boundaries, stale reads, account isolation, paging, safe formatting, mandatory-audit/final-validation rollback, real routes/providers/CSRF/private responses, notification sound integration and closed/denied readers. Browser audio uses injected contexts. Custom recipient audiences, automatic registration/other domain triggers, push/email delivery and institution-selected retention/disposition remain separate roadmap work.
