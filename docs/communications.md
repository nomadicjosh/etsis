# Communications

Open **Communications** in the staff workspace. Message content is plain text and encrypted at rest. Creating a draft, maintaining a template and reviewing recipients never sends mail. All reads and writes require `sis:communications:manage`; opening or changing a private composition also requires ownership or `sis:communications:admin`. Approved institutional templates may be read and copied by other Communications staff; this grants no editing or recipient-directory access.

## Reusable message templates

Choose **My message templates → Create reusable template**. Enter the subject and message, then choose **Save reusable template**. The subject identifies the template in the library. New templates begin as private reusable wording and have no delivery instructions. The library shows your own active templates in pages of twenty; **Show retired templates** opens your retired entries. List responses contain subjects and revisions, not message bodies.

Open a template to review its wording. **Edit template** saves an expected revision; another edit makes an older form stale. A template cannot acquire recipients, enter the delivery queue or send mail. Templates use the existing encrypted campaign storage in an explicit template state; this adds no parallel message store, event journal or schema migration.

**Start a new message** copies the reviewed template revision into a new draft owned by the signed-in account. The draft has its own identity and revision, and no recipients or deliveries. Review and edit it before selecting recipients. Later template edits, retirement or restoration do not change that draft or any queued/sent messages. Wording is copied literally: no SQL, PHP, HTML rendering, recipient substitution or external template code is executed.

**More → Retire template → Confirm template retirement** prevents new drafts from using it. Existing messages remain intact. Restore it through the retired library before editing or using it again. Restoration always returns it to private editing; institutional approval is not restored automatically. Retirement/restoration checks the expected revision and retains the record. These controls do not delete message or delivery history. Secondary template tasks use **More** when several apply; editing and creating a new message remain visible above the wording.

The private library is scoped to its signed-in owner. An administrator with `sis:communications:admin` can use the existing campaign administration access to open another owner's template; a new draft made from it belongs to the administrator's current account. Ordinary Communications permission alone never grants access to another person's private, submitted or retired wording.

## Institutional review and shared library

Open a private template and choose **More → Submit for institutional approval → Confirm template submission**. The confirmation shows the exact subject and wording. Editing pauses while the submission is pending. **My submissions** shows your pending templates; **Return to private editing** withdraws the request if you need to make changes.

A reviewer with both `sis:communications:manage` and `sis:communications:admin` opens **Awaiting approval**, reviews the submitted wording, then chooses **Approve institutional template** or **Return to author** and confirms the decision. Ownership alone never grants approval authority. The administration permission also applies when reviewing your own wording; a separate second-person approval policy is not imposed. Returning a submission puts it back in its owner's private editing library.

Approved wording appears in **Institutional library** for Communications staff. Readers can review it and **Start a new message**, creating an ordinary draft owned by their current account. Shared access does not permit editing, retirement, approval, or access to the author's other templates. Choosing recipients still requires independent person-directory access.

Approved wording cannot be edited in place. The owner or a Communications administrator must first choose **Return to private editing → Confirm withdrawal to private editing**. This removes institutional availability before edits; a later submission requires a fresh approval. Retiring also removes availability. Earlier drafts, queued messages and sent wording retain their own copies.

Every submission, approval, return and withdrawal checks the exact revision under the existing composition lock, rechecks current authority and commits its metadata audit atomically. Changed authority or an audit failure rolls back the decision. A stale approval cannot approve a replacement submission, and a private or retired template cannot be approved directly. Reads and copies recheck visibility before completion; if institutional access is withdrawn, another staff member cannot finish reading or copying that source. Owner, submitted, retired, institutional and reviewer lists all retain twenty-entry pages and omit message bodies. No schema migration, external delivery or academic event is added.

## Drafts and recipient review

Choose **Draft a message**, or start from a reusable template or a [published public-event invitation](public-campus-events.md#event-announcements). Save and review the message before choosing recipients. Draft edits require the current revision and stop after queueing.

**Choose recipients** also requires `sis:person:manage`, matching staff person-directory access. Search by name or institutional ID, select people with checkboxes and keep those selections while searching or loading more matches. Search returns at most fifty minimal person/account labels per page, without opening private profiles or returning email addresses. Disabled accounts and people without a linked account are unavailable.

**Review message and recipients** shows the saved message and complete selected audience, twenty recipients per page. **Confirm recipient queue** requires that exact draft revision and rechecks all selected person/account links and delivery addresses. A changed message requires a fresh review; an unavailable recipient stops the whole queue operation. Duplicate selections create one delivery per account. The audience is limited to 500 people. Validation uses a bounded batch; inserts store only delivery identities, campaign/account references, status and revision.

### Start from a saved student report

In Report Studio, **Choose students for messaging** selects up to 25 distinct students from a saved dynamic detail report. It uses the existing report shortlist, current membership/version checks and independent data permissions. Report-run/student-read and Communications/person-directory duties are all required. Student-account report sources retain their independent financial read permission. Summaries and static captures cannot become live audiences. [Full handoff workflow](workflow-review.md#message-students-selected-from-a-saved-report).

Before opening the normal composer, etSIS resolves every student through the canonical person to an enabled account with a valid delivery address. One unavailable account stops the handoff; students are not silently skipped, created or relinked. Labels exclude email addresses and private profile payloads. Account lookup creates no draft or delivery, and rechecks current actor and authority after reading.

Save the message, then use the existing recipient picker with those accounts preselected. You can remove/add recipients and review the complete wording/audience before queueing. Report values are not inserted or attached. Queueing and background-delivery authorization remain separate confirmations; the existing worker and progress bar apply. After handoff, normal campaign ownership and recipient permissions govern the editable message. No schema, queue or domain-event change is required.

## Background delivery and progress

Queueing does not send. Choose **Review background delivery**, review the saved message and the queued audience, then choose **Confirm background delivery** once. Confirmation saves an authorization and returns immediately; it never contacts the mail server. You can navigate away, close the browser or sign out. The server worker continues independently, checking that the authorizing account still has current Communications and person-directory permission and ownership or Communications administration access.

The same review offers **Send now** or **Schedule for later**. Scheduling reveals one local date/time field with the institution's time zone beside it. Choose the time, then **Update delivery review**. The review shows the exact scheduled start, message and complete queued audience. **Confirm scheduled delivery** authorizes that combination. Changing the timing disables confirmation until you update the review; it cannot submit the earlier time silently.

Choose a future time in the displayed institution zone, regardless of the browser's own time zone. Invalid dates and local times that do not exist or occur twice during a clock change are rejected with an error on the time field. A changed institution time zone or a scheduled time that passes before confirmation requires a fresh review. Once confirmed, the UTC instant is retained; later institution time zone changes do not move the send. Progress presents that instant in the institution's current zone.

Scheduled campaigns start **no earlier than** the reviewed time, on the next available worker run. They may take several batches to finish. The worker filters future authorizations before its ten-job limit, so they cannot hide due work. It verifies the indexed time against the protected review and rechecks the due time, permission and ownership before each claim. Pausing, cancelling or losing authority still prevents new sends when the scheduled time arrives. Resuming an overdue paused campaign permits its active authorization to continue; cancelling requires a new review before remaining queued messages can be authorized again.

**Sending progress** shows confirmed sends out of the complete audience, a percentage bar and separate queued, sending and needs-review counts. It shows the scheduled start and distinguishes waiting from sending. While the screen is visible and authorization active, it checks every six seconds during delivery or every minute while awaiting the scheduled time. **Refresh progress** checks immediately. Reopening the campaign reads its current database status; it does not resume a browser sending loop. “Sent” means the configured mail transport accepted the message, not proof that the recipient read it or that their mail server delivered it to an inbox.

Reviews expire after twenty minutes and belong to the current account, message revision and queued recipient set. Confirmation checks these again under the campaign lock. Repeating the same accepted confirmation returns its existing status and cannot reactivate completed or cancelled work or replace a later authorization. The encrypted authorization contains only references, revisions and a composition fingerprint; bodies and addresses remain outside jobs. Existing queued campaigns are not automatically authorized by installing this update.

**Pause campaign** stops new claims; **Resume campaign** allows an existing active authorization to continue. **Cancel background delivery** ends that authorization. A send already claimed may finish after pause/cancel. Sent and uncertain records remain intact. A new review can authorize only recipients still queued, after resolving any blocking account, permission or mail configuration issue. It never retries sent or uncertain deliveries.

An unavailable account/address, revoked authority or disabled mail transport blocks new sends before a claim. An interrupted or uncertain send holds the authorization for institutional review and is never automatically retried. Progress retains these distinct outcomes. The successful-send receipt is recorded even if the authorizer loses access during transport.

### Review uncertain deliveries

The campaign's delivery table offers **Review delivery** to staff with `sis:communications:manage`, `sis:communications:admin` and `sis:person:manage`. Owning the campaign alone does not grant this action. The review shows the delivery reference used in the mail header, its recorded transport result and any retained decision. Notes and evidence references are private to these authorized reviewers; ordinary delivery lists contain only the disposition and decision reference.

First cancel an active background authorization. Pausing alone leaves an authorization available to resume, so it does not permit a delivery decision. Then choose one outcome and record a reason:

- **Confirm sent using evidence** requires a mail-service receipt or institutional evidence reference. It is available only after the send attempt returned an uncertain result. A send still recorded as in progress cannot be manually confirmed.
- **Close without resending** closes the review without making another send attempt. It records no claim that the recipient did or did not receive the message. A previously claimed send may still finish.

Choose **Review decision**, check the outcome, reason and evidence, then **Confirm delivery decision**. Changing wording through **Back to decision** requires another review. Reviews expire after twenty minutes and are tied to the same actor, delivery, campaign revision, transport result and authorization. Changed results or permissions stop confirmation. Repeating the same accepted confirmation returns its retained decision without another send or duplicate audit entry. A different decision cannot overwrite it.

The decision records who acted and when, with its reason and reference encrypted at rest. Use a short institutional reference rather than pasting email text, addresses, credentials or raw provider responses. The existing metadata audit contains the actor, action and delivery identity only.

The original transport status and version remain intact. A real transport receipt arriving later is still recorded and takes precedence in sent counts; the earlier administrative decision remains available under **View delivery decision**. Evidence-based staff confirmations count as sent. Closed reviews have a separate count and are excluded from sent totals, so a finished campaign may show fewer confirmed sends than its complete audience. A campaign finishes when every delivery has a real send receipt or a retained administrative disposition.

Neither outcome sends, retries or requeues a message. If other recipients remain queued, review and authorize those separately through the existing background-delivery flow. This action creates no new queue, event journal or automatic recovery process.

### Server setup

Apply migrations `20261115000016_CreateSisCampaignDispatches.php`, `20261115000017_AddSisCampaignSchedules.php` and `20261115000018_AddSisDeliveryReviews.php`. They add the authorization table, its nullable UTC schedule/due-work index and nullable delivery-decision metadata. Earlier authorizations retain their original scope and send-as-soon-as-possible meaning. Existing delivery status/version and message/event records are preserved. Live delivery requires `sis.mail_enabled`, a valid institutional sender and mail debugging disabled.

Configure the server to run Codefy's scheduler once a minute using the institution's PHP 8.4+ executable, for example:

```cron
* * * * * cd /path/to/etsis && /path/to/php84 codex schedule:run >> /dev/null 2>&1
```

The application registers `sis:communications:send --apply --limit=10` every minute when institutional mail is enabled. Codefy's `onlyOneInstance()` prevents overlapping scheduled runs. Each invocation examines at most ten active authorizations, attempts at most ten deliveries by default (configurable from one through twenty) and stops starting new work after thirty seconds. Paused campaigns are excluded from the indexed pending-job query. Transport connection timeouts still apply to an individual in-progress send.

For a deliberate manual worker invocation, use `php84 codex sis:communications:send --apply --limit=10`, or `ddev codex sis:communications:send --apply --limit=10` in the existing DDEV environment. Omitting `--apply` shows usage without sending or inspecting institutional messages. Worker output contains aggregate counts only. The browser progress bar does not run this command; a missing server scheduler leaves confirmed messages queued.

The service rechecks current authority, the active authorization and expected revisions after locks and before claiming delivery. The existing delivery engine and per-recipient claim are shared by the worker; no second sender, event journal or login session is created.

Private responses use no-store caching, browser mutations preserve framework CSRF and debug collectors stay suppressed. Message content, addresses, credentials and upstream responses do not enter audit metadata, browser storage or delivery-job payloads. Metadata audits identify the actor, action and affected composition/delivery only. Template operations are ordinary authorized, revision-checked CRUD and add no historical academic or financial events.

Automatic schedule-change/cancellation alerts remain roadmap work. This workflow does not add attachments, tracking pixels or automatic resend/recovery actions.
