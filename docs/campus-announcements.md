# Campus announcements

Implemented November 23, 2026. Reviewed **campus news** appears in the shared notification bell on signed-in portal and staff pages. It uses the existing news content, author/publisher duties, approval receipts and publication windows. There is no separate announcement copy, recipient fan-out or second event store.

## Publish an announcement

1. Open **Campus content**, create a **News update** and select **Signed-in campus**, **Selected account roles** or **Selected student programs**. Signed-in campus includes applicants, students and staff; the selected audiences use the current membership rules below.
2. Save the draft and request review. A publisher with the existing content-management and publishing duties reviews and approves it. Authors with both duties can review their own saved drafts under the existing policy.
3. Publish immediately or choose the existing publication start/end controls. Dates are evaluated on each server request: the start is inclusive and the end is exclusive. A scheduled announcement becomes available when readers next open or refresh the list. No publishing worker is required.

Public website news stays in the public news workflow and does not enter this private announcement list. Text pages and links are also outside this list. Earlier content without an explicit saved campus audience is not inferred to be an announcement. Draft, pending, archived, not-yet-visible and expired news are unavailable through both the list and direct announcement API.

Existing explicit campus publications remain their existing content facts. This reader creates no inferred historical approval, publication or read event for earlier records.

Editing approved news returns it to draft. Its revised wording requires fresh approval before appearing again. Republishing creates a new content revision, so someone who read an earlier revision sees the revised announcement as unread. Archiving removes the item from this reader through the existing content workflow; it does not erase the original content or reading metadata.

## Selected student programs

Choose **Audience → Selected student programs** to address active students pursuing particular academic programs. **Find programs** searches program codes and titles; **Next programs** and **Previous programs** page through twenty labels. Select **one to ten programs**. The selector shows labels only, without student names, member counts, account grants or saved recipient lists.

A reader must have an enabled sign-in account linked to the canonical person, an active student record, and an **active pursuit in any selected program**. Both primary and secondary pursuits qualify. A primary-program field alone, an application, an earlier closed pursuit or an account role does not qualify. Staff and Super accounts receive it only if they also meet this student/pursuit rule. These are current operational memberships, not a degree-progress or registration-eligibility certification.

For example, selecting `ARTS-UG` addresses eligible students pursuing that broad program with any of its majors. It does not separately select English, History or a minor, and it does not restrict a catalog cohort. Selecting `ARTS-UG` and `SCI-UG` uses **either** program, rather than requiring both. Multiple matching pursuits still produce one announcement in the person's bell.

Save the selected programs with the draft before requesting review. The publisher sees their saved codes/titles with the wording and publication window. The review proof binds the exact identities and labels. A missing or renamed program definition requires saving and reviewing the selection again before a new publication; an already published notice continues to match the same surviving program identity. A same-code/name replacement with a new identity does not adopt an earlier selection. Retained targets never widen into Signed-in campus when a program is removed.

**Back to editor** restores the saved selection. Temporary switches between role/program/campus/public choices preserve each working selection in memory. Saving another audience clears the inactive target sets. Role and program audiences are separate choices; a role cannot substitute for a program, and their selections are not silently combined. Text pages and links cannot use program targeting; changing a new draft to either type requires an explicit new audience choice.

The shared bell, campus-news reader, private content URL and announcement detail/read/delete paths all check current membership, before paging where applicable. Closing a pursuit, deactivating the student, disabling the account or changing its canonical person link removes access on a later read/refresh unless another active selected pursuit still qualifies. Existing read/deletion state belongs to the same account/publication; eligibility changes do not create a new historical notice or duplicate. Public news and public article routes exclude program-targeted news. No email, push, recipient fan-out, report, account grant or academic decision is created.

Program directory labels require the existing content-management duty; approval keeps the independent publishing duty. Current student membership grants reading, not authoring or program-management authority. The new `20261127000002_CreateSisPortalAudiencePrograms` migration adds a ULID target table with a unique post/program pair and content foreign key. Program identity/name references remain after definition removal, so there is intentionally no program foreign key. Existing role targets, publications, academic/financial records, journals and receipts are preserved. Apply this migration before using the new authoring/reader code. Five-dialect schema compilation and populated SQLite workflows supplement read-only native PostgreSQL probes; remaining native-engine/concurrency acceptance stays open.

## Selected account roles

Choose **Audience → Selected account roles** when a news update is intended for particular
account roles. **Find roles** searches existing role names; **Next roles** and **Previous roles**
page through twenty labels at a time. Select **one to ten roles**. Selecting a role again or using
its remove button clears it. The selector exposes role labels only, without member lists, member
counts or permission grants. Role setup and account assignments remain in **Roles & access**.

An account must currently have **any one** of the selected roles. For example, selecting
`registrar` and `admissions` makes the update available to accounts assigned either role. This
checks actual account-role assignments; having a student/faculty capability, a related permission
or the protected Super role does not substitute for a selected role. Super receives the notice
only when one of its account's actual roles matches. Disabled accounts do not match.

Save the draft before requesting review or publication. Changing the selection disables review
until the revised draft is saved. The existing publisher reviews the saved role names alongside
the wording and publication window; the review proof binds that exact selection. Missing or
changed role definitions stop a new publication for review. **Back to editor** restores the saved
selection, and unsaved toggling between audience choices preserves the working role selection.
Saving Public website or Signed-in campus clears role targets from that draft. Text pages and
website links do not offer role targeting; changing to one of those types requires choosing
another audience explicitly.

The bell, signed-in campus-news API, private content URL and announcement detail/read/delete
endpoints apply the current role match. Filtering happens before paging. Removing a role
assignment takes effect on the next server read or refresh; later assignment can make a still
available announcement discoverable. Existing read/deletion state remains this account's own
state for that publication. Public website news and public article routes exclude role-targeted
news, including when someone happens to be signed in. The private announcement reader remains
the normal way to open it from the bell.

Selected role identities and labels remain attached to the saved content. Deleting a role does
not turn the news into a campus-wide announcement, and creating a new role with the same name
does not reuse its earlier identity. An authorized publisher can still archive news whose role
has been removed. Revising its targets returns the content to draft and needs fresh approval.

This is a current audience for reviewed news, not a delivery list, legal acknowledgement or
notification archive. It does not grant roles, send email, create recipient copies or infer
academic status. Further department/component audiences and external delivery remain separate
roadmap work. The original public and signed-in campus audiences remain available.

## Read and return

Open the header bell and find campus announcements in [your combined account list](account-notifications.md). Use **Read announcement** for full content, **Older notifications** or **Previous notifications** for twenty-item pages, and **Refresh notifications** for the current list. Publication time determines announcement order; an earlier unknown date is not invented. Publication/read changes can move live items between refreshed pages.

Opening an announcement automatically marks only its displayed revision read. **Back to notifications** returns to the originating page. Read notices remain listed until deleted. Another account keeps its own reading state, even with registrar or publishing duties. The bell refreshes bounded unread counts periodically; opening or refreshing the list loads only small authorized metadata pages.

The reader preserves approved formatting and useful links using the existing server sanitizer and local DOMPurify renderer. Plain text retains literal angle brackets. The drawer/dialog keeps keyboard focus, visible errors and narrow-screen reflow; closing the selected reader or panel discards its private page contents and ignores late responses.

Read state means that this account opened the displayed revision. It is **not a policy acknowledgement, proof of understanding, required acceptance or legal delivery receipt**. A stale request cannot hide a later publication. Repeat requests for the same available revision retain the original read timestamp without another audit entry. Each account/content pair keeps one current revision and read timestamp, not a historical acknowledgement ledger.

## Delete an announcement from your drawer

Use **Delete notification** beside a list item or inside its opened detail. The server physically deletes this account's read metadata and hides the displayed publication. Reopening and reloading do not restore it. Other accounts and the underlying published campus news remain unchanged; deleting a personal notification does not archive institutional content.

Because an announcement is shared news rather than a separate copy for each reader, the server retains one minimal account/content/revision suppression marker. It has no wording, read timestamp or revision history. This prevents the same publication from immediately being delivered again. A later reviewed republication has a higher revision and can appear as a new unread announcement. Retrying deletion of the earlier revision never hides that newer revision. There is no notification trash/archive or undo screen.

Deletion requires the displayed revision, current signed-in ownership and CSRF. The content lock, mandatory audit and final source/owner checks share one transaction; failures retain the original state. The audit records only the actor, action and content reference, not a notification body. Deleting again is idempotent. The user has explicitly requested disposable notifications rather than historical notification preservation.

## Sound and temporary feedback

Loading, reading, paging and reopening are silent. Use the shared **Refresh notifications** control for current availability; reading or deleting a notice creates no duplicate action toast. Sound/display settings are under the person's workspace drawer or the portal's **Personal information** screen; see [notification preferences](workspace-notifications.md#sound-and-display-preferences).

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

Fictional checks cover publication/read/delete sequencing, missing/changed audiences, scheduling boundaries, stale reads/deletions, account isolation, paging, safe formatting, mandatory-audit/final-validation rollback, real routes/providers/CSRF/private responses, notification sound integration and closed/denied readers. Browser audio uses injected contexts. [Waitlist registration/removal updates](registration-history.md#private-waitlist-outcome-updates) now provide one automatic domain trigger. Further recipient audiences, automatic triggers and push/email delivery remain separate roadmap work. Personal notifications require no historical archive.


Migration `20261127000001_CreateSisPortalAudienceRoles` adds only the selected-role reference table:
ULID identities, a unique post/role pair, the retained role label and the existing content foreign key.
It intentionally retains the role identity if that role is later removed; reader queries require a
current role and enabled account. It changes no existing audience, role assignment, publication,
personal read state or journal event. The configured PostgreSQL migration and fictional SQLite
workflows supplement five-dialect schema compilation; broader live-driver acceptance remains open.
