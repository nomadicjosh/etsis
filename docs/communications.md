# Communications

Open **Communications** in the staff workspace. Message content is plain text and encrypted at rest. Creating a draft, maintaining a template and reviewing recipients never sends mail. All reads and writes require `sis:communications:manage`; opening or changing a private composition also requires ownership or `sis:communications:admin`. Approved institutional templates may be read and copied by other Communications staff; this grants no editing or recipient-directory access.

## Reusable message templates

Choose **My message templates → Create reusable template**. Enter the subject and message, then choose **Save reusable template**. The subject identifies the template in the library. New templates begin as private reusable wording and have no delivery instructions. The library shows your own active templates in pages of twenty; **Show retired templates** opens your retired entries. List responses contain subjects and revisions, not message bodies.

Open a template to review its wording. **Edit template** saves an expected revision; another edit makes an older form stale. A template cannot acquire recipients, enter the delivery queue or send mail. Templates use the existing encrypted campaign storage in an explicit template state; this adds no parallel message store, event journal or schema migration.

**Start a new message** copies the reviewed template revision into a new draft owned by the signed-in account. The draft has its own identity and revision, and no recipients or deliveries. Review and edit it before selecting recipients. Later template edits, retirement or restoration do not change that draft or any queued/sent messages. Wording is copied literally: no SQL, PHP, HTML rendering, recipient substitution or external template code is executed.

**Retire template → Confirm template retirement** prevents new drafts from using it. Existing messages remain intact. Restore it through the retired library before editing or using it again. Restoration always returns it to private editing; institutional approval is not restored automatically. Retirement/restoration checks the expected revision and retains the record. These controls do not delete message or delivery history.

The private library is scoped to its signed-in owner. An administrator with `sis:communications:admin` can use the existing campaign administration access to open another owner's template; a new draft made from it belongs to the administrator's current account. Ordinary Communications permission alone never grants access to another person's private, submitted or retired wording.

## Institutional review and shared library

Open a private template and choose **Submit for institutional approval → Confirm template submission**. The confirmation shows the exact subject and wording. Editing pauses while the submission is pending. **My submissions** shows your pending templates; **Return to private editing** withdraws the request if you need to make changes.

A reviewer with both `sis:communications:manage` and `sis:communications:admin` opens **Awaiting approval**, reviews the submitted wording, then chooses **Approve institutional template** or **Return to author** and confirms the decision. Ownership alone never grants approval authority. The administration permission also applies when reviewing your own wording; a separate second-person approval policy is not imposed. Returning a submission puts it back in its owner's private editing library.

Approved wording appears in **Institutional library** for Communications staff. Readers can review it and **Start a new message**, creating an ordinary draft owned by their current account. Shared access does not permit editing, retirement, approval, or access to the author's other templates. Choosing recipients still requires independent person-directory access.

Approved wording cannot be edited in place. The owner or a Communications administrator must first choose **Return to private editing → Confirm withdrawal to private editing**. This removes institutional availability before edits; a later submission requires a fresh approval. Retiring also removes availability. Earlier drafts, queued messages and sent wording retain their own copies.

Every submission, approval, return and withdrawal checks the exact revision under the existing composition lock, rechecks current authority and commits its metadata audit atomically. Changed authority or an audit failure rolls back the decision. A stale approval cannot approve a replacement submission, and a private or retired template cannot be approved directly. Reads and copies recheck visibility before completion; if institutional access is withdrawn, another staff member cannot finish reading or copying that source. Owner, submitted, retired, institutional and reviewer lists all retain twenty-entry pages and omit message bodies. No schema migration, external delivery or academic event is added.

## Drafts and recipient review

Choose **Draft a message**, or start from a reusable template or a [published public-event invitation](public-campus-events.md#event-announcements). Save and review the message before choosing recipients. Draft edits require the current revision and stop after queueing.

**Choose recipients** also requires `sis:person:manage`, matching staff person-directory access. Search by name or institutional ID, select people with checkboxes and keep those selections while searching or loading more matches. Search returns at most fifty minimal person/account labels per page, without opening private profiles or returning email addresses. Disabled accounts and people without a linked account are unavailable.

**Review message and recipients** shows the saved message and complete selected audience, twenty recipients per page. **Confirm recipient queue** requires that exact draft revision and rechecks all selected person/account links and delivery addresses. A changed message requires a fresh review; an unavailable recipient stops the whole queue operation. Duplicate selections create one delivery per account. The audience is limited to 500 people. Validation uses a bounded batch; inserts store only delivery identities, campaign/account references, status and revision.

## Deliberate delivery

Queueing does not send. Review the saved message and a selected queued delivery, then use **Review and send → Confirm send to this recipient**. Live delivery requires `sis.mail_enabled`, a valid institutional sender and mail debugging disabled. Pause/resume controls prevent new claims while paused; completed campaigns retain their delivery records.

The service rechecks the same actor's permission and ownership after locks and before committing changes or claiming delivery. A denied claim or a known unavailable account/address leaves the delivery queued before any mail attempt. An attempted send with an uncertain outcome stays held for institutional reconciliation and is never automatically retried. A successful external send retains its receipt even if access changes during transport.

Private responses use no-store caching, browser mutations preserve framework CSRF and debug collectors stay suppressed. Message content, addresses, credentials and upstream responses do not enter audit metadata, browser storage or delivery-job payloads. Metadata audits identify the actor, action and affected composition/delivery only. Template operations are ordinary authorized, revision-checked CRUD and add no historical academic or financial events.

Scheduled dispatch and automatic schedule-change/cancellation alerts remain roadmap work. This workflow does not add attachments, tracking pixels or automatic resend/recovery actions.
