# Admissions workbench

Admissions staff keep application responses, educational background, the working checklist, office routing and follow-up together in the existing application review. Formal decisions and matriculation continue through their own reviewed, retained workflows. Working casework does not accept/reject an application, create a student, award credit or change registration eligibility.

## Start an application

Choose **Admissions → Start an application**, select the existing person, academic program and entry term, then choose **Open program application**. Starting from a person record keeps that person selected. Complete the program’s published form and use its existing answer review before submitting.

**Back to application setup** retains the selections and unfinished answers for the same person, program, term and published form. Reopen that same selection to resume. A different selection or published form starts with fresh answers; one applicant’s answers are never copied to another. After submission, **Start another application** obtains a fresh submission request. **Back to Admissions** or **Back to person record** returns to the originating workspace.

## Identity correction follow-up

Staff with person-management access open **an application → Identity correction requests** to load the person’s bounded identity-only follow-up list. Request specific owner-visible information, then review the reply and current recorded names/ID. **Edit person names** opens the canonical staff editor inline with a required protected correction reason; applying a correction does not close the request or decide admission. A separate owner-visible resolution closes or cancels the follow-up. Identifier/credential changes retain their existing authorized paths.

Applicants open **Start your application → Identity correction requests**, review their verified current identity and reply without replacing unfinished application answers. Personal information offers the same requests. Ownership, encrypted working messages, current-profile verification, private responses/CSRF, stale-source checks and access-loss cleanup remain in force. The correction reason stays in restricted history; the resolution note is the message shown to the owner. No reply or closure changes an identity, creates a student, imposes an admission requirement or sends mail. See the [complete identity workflow](person-identity-and-accounts.md#identity-correction-follow-up).

## Institutional policies

Choose **Admissions → Policies**. Acceptance checklist, Application revisions, Offer acceptance and Applicant withdrawal have separate labeled groups, explanations and save actions. Each save sends that policy’s current revision. Saving one policy preserves unsaved choices in the others. While a save is pending, save actions are unavailable; a newer input remains marked as unsaved after the accepted response. Ordinary failures keep the draft; denied access removes the controls. Earlier decisions and issued offers retain their recorded policies.

## Start or continue a case

Open **Admissions**, find an applicant and open the application. Choose **Checklist & follow-up**. This loads only that application's current working case; opening an ordinary listing does not load its notes or replay history.

Add checklist items or use a reusable checklist. Each item has a label, instructions, **Needed to finish casework**, **Show item and instructions to applicant**, progress and an optional due date. A staff-only item can serve as an internal follow-up task. Both types use the same editor and thirty-item bound, with collapsible named cards, keyboard move controls and explicit save.

Progress can be **Pending**, **Received**, **Complete** or **Not needed**. Use **Received** when an item has arrived through the institution's existing delivery process and still needs review. A case cannot be marked **Done** while a needed item remains pending or received. These are working checklist statuses; **Not needed** is not an academic waiver or a formal exception to an admissions policy.

Under **Routing & next steps**, enter the office, a next step for the applicant, private staff notes, the work status and an optional follow-up date. Office and next-step fields are explicitly applicant-visible. Keep confidential working commentary in **Private staff notes**, or in a staff-only checklist item.

Choose **Assign to me** to claim responsibility, **Keep current assignment** to retain it, **Assign a colleague** to hand it over, or **Leave unassigned** to release it. For a colleague, search with at least two characters of their name or institutional ID and select a result. Results include only linked, enabled accounts with current `sis:admissions:manage` authority, including direct grants and role permissions. Search returns twenty eligible records at a time, without email addresses or full permission sets. A colleague needs admissions access before they can be assigned; assignment never grants it.

Save casework to commit the checklist, routing, next step and assignment together. Selected assignments recheck both the actor and recipient under the access-definition lock before changing the application. If access was revoked, choose another colleague; the screen preserves the draft. Any authorized admissions manager can continue the working case; assignment does not establish an exclusive lock.

Work status is **Open**, **Waiting** or **Done**. It is separate from the application's submitted/accepted/rejected/matriculated status. Completing, waiting or reopening casework does not change an admissions outcome. An unchanged save appends no audit or revision. A current exact retry is a no-op; an older retry after another edit receives a conflict and cannot overwrite the newer case.

## Reuse and maintain checklists

Expand **Reusable checklists** and use **Find presets**. The library shows enabled presets for this program or all programs, with twenty metadata records per page. Search uses literal text; application notes and answers are not searched or returned.

**Use checklist** adds its definitions to the current draft. New copied items begin pending with no due date. Existing manual work is preserved. Applying the same preset again does not duplicate or reset its already copied items; later preset edits do not replace application-specific wording or progress. Different presets have separate item references. If a copy would exceed thirty items, the whole copy is rejected and the draft stays intact. Save casework when the combined list is ready.

Staff with `sis:forms:manage` can also **Create preset from draft** or **Edit preset**. Review the generic labels and instructions before saving. The preset includes labels, instructions, visibility and whether items are needed; it excludes application progress, dates, assignment and private case notes. Uncheck **Available to all programs** to scope it to this application's program. Disable a preset to stop new use without removing existing application copies. Preset edits use their own version checks and save action; they do not discard the unsaved casework draft or update other cases.

The workbench itself requires `sis:admissions:manage`; authoring presets additionally requires the form-management permission in this screen. The preset configuration API permits a form manager to maintain a known preset without granting access to private applications.

## Applicant program and entry-term correction requests

Applicants open **Start your application → Your applications → Application correction requests** for the relevant application. Choose **Planned program** or **Entry term**, explain the requested correction, then choose **Send request**. New requests are available while the application awaits an admissions decision. Each application can have one open request for each area. A request asks the office to review a change; it does not replace the current target, approve admission, create a student or satisfy a formal checklist requirement.

Use **Open requests** to review pending work and **Closed requests** to read earlier replies/cancellations. **Cancel request** withdraws the follow-up, leaving the application itself unchanged. For changes after an admission decision, contact admissions through the institution's normal channels; staff retain their existing separate deferral/withdrawal/decision workflows.

### Staff review and correction returns

Staff open **Admissions → an application → Application correction requests**, or open the application from **Follow-up queue**. The queue includes pending applicant requests even when no staff checklist exists, staff casework is done, or the application was subsequently withdrawn. It shows a request count without loading messages. Opening/requesting follow-up never creates or completes a staff checklist. Assignment and due-date filters continue to describe staff casework; an unstarted checklist has no inferred deadline.

Staff with admissions-management access can read requests and enter a **Reply visible to the applicant**. Where a change is appropriate, staff who also have the existing correction duty can choose **Review program correction** or **Review entry-term correction**. These open the ordinary reviewed correction editors. Their program/form/calendar eligibility rules and permissions still apply. Return with **Back to application**; the same request panel reopens and keeps the unsent reply. A successful correction refreshes the application revision before follow-up closure. Request text is never interpreted as a program identifier, term selection or approval.

Choose **Reply and close follow-up** to save the explanation. The reply may explain a completed correction, a declined change, or the additional institutional steps required. Closing the follow-up does not itself correct the program/intake or decide admission. A concurrent application change requires refresh before a new closure. Staff can resolve an existing follow-up after a later decision/withdrawal, but cannot use this panel to bypass the existing actual-change restrictions.

Closure creates/coalesces the existing private admissions update reference only while the request's original account still owns the person/application. **Admissions updates → Read update → Application correction requests → Closed requests** opens the reply without loading student academic or financial context. Account relinking does not transfer earlier private request messages to a new owner or send the new account an update for the original owner's request. Separately authorized staff retain the request with an account-change explanation and the existing super-account safeguards.

### Request limits, privacy and recovery

Messages and replies allow 1–1,000 characters. Lists return ten records per page, filter owner/state before paging, and permit at most one hundred retained requests per application. Blank/invalid messages, duplicate open areas, stale revisions and excess requests fail without changing the application. Only current canonical owners may create/cancel their requests; staff privileges do not override the owner portal. Actual corrections keep their own independent authorization.

A failed write keeps one captured action in memory. **Retry captured action** resends exactly that reference, revision and message; an accepted original retry returns its original result even after later corrections, admission decisions or matriculation. It never reopens a closed request or repeats a notification. **Discard captured action** clears that local capture and refreshes the current state; it does not undo a previously accepted action. Unsent messages/replies survive ordinary failed refreshes. Drawer/page leave guards protect unfinished work, access denial clears private details and detached responses cannot restore them. Private text is rendered as text and is never placed in browser storage, logs, email or background jobs.

The request envelope, messages, replies and two bounded action receipts are encrypted operational CRUD casework in ULID-keyed `sis_admission_change_requests`, with indexed routing/state and compare-and-swap revisions. Required audits, account/application locks, source-history verification and final authority/source rechecks commit with the working change and update reference. Formal program/intake writers continue to preserve actual changes in the one existing protected journal. The additive migration inherits referenced PostgreSQL ID collations on converted installations, compiles for all five supported schema dialects, preserves existing facts on repeat, and refuses destructive rollback. Institution-approved retention/disposition remains separate; the follow-up list is not an academic-history or general reporting source.

## Work the follow-up queue

The assignment filter and due-date checkbox share a bounded field group. **Refresh queue** applies those choices; paging sits above the results. Changing filters leaves previous results visible until refreshed. Failed paging retains the accepted page and cursor. Open a case and use **Back to follow-up queue** to return to its accepted assignment, due-date filter and page. **Back to Admissions** closes the queue.


From **Admissions**, choose **Follow-up queue**. Filter **All open**, **Assigned to me** or **Unassigned**, optionally check **Due today or earlier**, then refresh. Open/waiting cases and applications with pending applicant correction requests appear in pages of twenty, ordered by application identity with exclusive cursors. Private notes, instructions and encrypted case/request payloads are not loaded into this queue.

The follow-up date is the earliest of the case's explicit follow-up date and its unfinished checklist item dates. Completed or not-needed items contribute no deadline. Done cases leave the queue unless an applicant correction request is still open; their completed casework remains unchanged. Dates use the institution's configured clock; the queue displays the institution date used for the comparison. This queue tracks work; it does not send automatic messages or make admissions decisions.

## Report on casework and return to the application

In **Reports → Report Studio**, the **Applications & admissions** source now includes **Casework office**, **Casework status**, **Next casework due date** and **Casework updated UTC**. These are current operational routing facts, separate from the application’s admissions decision. The due date is the existing earliest unfinished-item/follow-up deadline used by the work queue; a done case has no next deadline. Missing casework remains blank rather than appearing open or unassigned by inference.

Three starting points reuse the visual builder:

These report starters describe staff casework. Applicant correction requests are handled in the follow-up queue and are not added as reportable message data.

- **Admissions follow-up** lists open/waiting casework for applications that are not withdrawn, with program/intake and both application and work statuses. Add a due-date condition for the institution-calendar cutoff you want; the starter does not silently choose today.
- **Admissions workload by office** counts distinct applications by office and work status under those same filters. Distinct counts avoid inflation when authorized related student records are joined. Add the desired named relationships and conditions using the ordinary builder.
- **Applications without casework** finds applications with no case row. It includes all application statuses until you deliberately narrow them; it does not certify that guidance is required or automatically create a case.

Staff who also have `sis:person:manage` can add **Assigned colleague** and **Assigned account reference**, or use them in filters. The name follows the canonical person/account link and reflects current labels. An assignment with a missing person link has no inferred name. A recorded assignee is not a claim that the account remains eligible for new assignments, and reporting never grants admissions access through assignment. Names, emails and private staff records are not made generally reportable by adding casework fields.

Saved reports use existing dynamic/static, sharing, expiry and CSV controls. Readers require current report-run and admissions permissions; assignee fields retain the additional person duty, even when hidden in filters. A dynamic report follows routing changes; an earlier static capture retains its original answer. Private notes, checklist labels/instructions, next-step prose, application answers and encrypted payloads remain excluded from the report registry and SQL selection. Reporting reads the indexed operational projection and does not decrypt or replay every case.

A saved **dynamic**, ungrouped application report that includes **Application reference** offers **Open an application from these results**. Choose the displayed row to open the existing current application review, which independently checks access. **Back to report** reopens and refreshes the saved report. Unfinished casework, documents, offers and other guarded forms keep their existing protections; an unsuccessful application read restores the saved report with an error. Static captures, summary rows and draft previews offer no action. Opening a case does not assign it, send a message or make an admissions decision.

This slice adds no migration, new event, case store, report engine or automatic follow-up job. The same authorized casework editor remains responsible for changes. Institutional retention, broader admissions facts and other reporting datasets remain on the roadmap.

## Applicant next steps

Applicants open **Start your application → Your applications → Next steps** for the relevant program/term. The read happens on demand and preserves a new application being edited on the same page. It shows the office, applicant next step and only the checklist items staff marked visible, including instructions, progress and due dates. It excludes private notes, internal tasks, assignee identities, work status and revision metadata. Staff privileges do not override ownership on this portal endpoint.

If staff have not added guidance, the portal directs the applicant to contact admissions. Checklist progress alone is not an admission approval or registration confirmation. Reading next steps neither changes the application nor writes an event/audit. Closing it or refreshing the application list prevents detached viewers from restoring late results.

Withdrawn applications are paused in the follow-up queue without changing their working case, unless an applicant correction request still needs a response. Returning one to review restores its unfinished tasks; this is separate from changing a student record or closing a person account. See [application withdrawal](admissions-decisions.md#withdraw-an-application-or-return-it-to-review).

## In-portal admissions updates

Applicants can choose **Admissions updates** from their application toolbar or campus-services navigation. This page is available to a signed-in person before they have a student record. It loads only update metadata, without requesting the full student overview. Updates show the application program and entry term, the update time and whether they are unread. Use **Read update** to open the current next steps. **Include read updates** and explicit refresh let applicants revisit earlier guidance; lists contain twenty records per page.

The shared workspace and signed-in portal bell includes admissions notices in [one account list](account-notifications.md). Choose **View admissions update** to open that exact notice's current guidance, application history and correction-request controls, or choose **Delete notification**. The bell has no preference editor or read filter. Its visible-page count includes unread admissions notices, including those beyond the first page. Sound and display preferences remain under the person's workspace record or portal **Personal information**. Following a notice link still requires its current original owner; a copied, deleted or inaccessible link reveals no application content.

Saving a change to applicant-visible office, next step or checklist guidance automatically creates an unread update for the application's current owner. Staff-only notes, internal tasks, assignments and working status stay silent unless an enforced checklist changes the applicant's generic readiness message. That message never identifies hidden tasks or their counts. The update and case save commit together. Existing cases receive no fabricated backfill; their next visible change creates the first update.

Each application/owner pair has one reference with a public-update counter. Repeated changes coalesce into that reference, so it is an inbox of current guidance, not a timeline of earlier wording or an extra event journal. It contains no copied checklist, note or message body. Private case revisions do not advance its public counter. Changing the person account owner makes earlier-owner references inaccessible; staff authority cannot override current ownership.

Loading the list does not mark updates read. After successfully opening guidance, the page submits an explicit, CSRF-protected read confirmation for the update it displayed. A delayed confirmation cannot hide a newer update, and repeating an older confirmation cannot undo a later read. Closing the viewer before guidance arrives prevents that detached request from confirming the update. Read-state changes require audit and commit atomically.

**Delete update** physically removes the selected personal notice and its read counter from the database. It does not delete or change the application, working case, correction requests, earlier decisions, protected event history or required minimal administrative audit. There is no notification archive, trash or undo. On the separate updates page, use **Include read updates** to find a previously read notice for deletion. A stale delete cannot remove a newer public revision; refresh before trying again. Repeating an accepted delete is harmless. Retrying the original case save or changing private staff notes does not recreate it; a genuinely later applicant-visible update creates a fresh notice identifier. Account relinking does not transfer the earlier account's notice.

Read and delete operations recheck the signed-in account, canonical person and current application under the same transaction as required audit. A failed audit or changed source rolls the operation back. Access loss clears private details; late responses cannot restore a closed viewer. Failed refreshes preserve drafts, and a successful deletion is still reported as saved if reloading the remaining list fails. No new schema or historical backfill is needed for these controls.

These are in-portal updates. This workflow sends no email or push messages and does not start a background delivery job. Institutions should continue using their established delivery channels for time-sensitive communication.

## Applicant application history

Applicants open **Start your application → Your applications → Application record → Application history** for an application. A direct staff application has the same history action even when it has no form answers. **Admissions updates → Read update → Application history** opens the same owner-only view without loading the student overview or changing the update's existing read receipt.

The view separates **Original submission** from the **Current planned program and intake**, and labels the current status and when those details were checked. Original labels and submission timing come from genuine recorded submission evidence. Program/intake corrections retain their before/after labels; calendar approvals show their actual retained dates and academic-year label. Earlier missing submissions remain **not recorded**. A current observation shows when the status was seen, with its earlier effective timing explicitly unknown.

The recorded list includes submissions, admissions decisions/corrections, actual program/intake changes, answer resubmissions and withdrawal/return-to-review decisions. Current matriculated status points to **My campus**; it does not invent a matriculation entry or date in the separate admissions stream. Existing **Admission offer** controls continue to handle addressed letters and responses. The history view excludes letter contents/activity, staff reasons, actors, document/checklist evidence, application answers, event/operation identities and raw protected envelopes.

**Open next steps** returns to the existing application-specific guidance controls. Opening history respects an unfinished answer revision; declining the leave prompt keeps that draft and performs no history read. History and answer details share the existing application workspace without replacing a new application being edited. Closing history returns focus to its opening action. Each button uses the portal's Bootstrap branding and icons, with scoped heading sizes, wrapping cards and keyboard-visible focus.

### History pages and access

History loads only when requested. The backend verifies the protected application and its current projection, reads up to ten journal entries per page and returns only the approved applicant fields. Some pages can contain no applicant-visible entries after private letter activity is omitted; **Next history** continues to the next committed page. The first request freezes the history head. Current details shown by the browser remain the snapshot checked when the view was opened; later appends do not silently enter those pages. **Refresh history** deliberately starts a new snapshot. A failed refresh or next-page read preserves the already loaded details and retry position.

Previous pages reuse accepted page data in memory, with a limit of two hundred pages and only the selected page rendered. Closing, switching applications, leaving the page or access denial clears the private view; no history is stored in local/session storage. Unseen pages recheck the signed-in owner. A denied response clears earlier cached details and paging actions. Detached, closed and superseded requests cannot reopen a view or replace another application's history.

This route requires the current person/account to own the application, even for an account with staff or super privileges. Staff use their separately authorized application review. The read locks the person/application, verifies the existing journal, records a minimal access audit and rechecks the actor, owner, current labels/application and both admissions/matriculation heads before returning. An audit/source/ownership change or damaged history returns no partial private result. Existing bounded replay limits remain in force; an oversized or unsupported history needs office review. Opening the view never creates an observation, repairs a projection, changes status or marks an update read.

Browser responses stay private/no-store with debug suppression. Focused validation covers 87 distinct PHP/HTTP/adjacent cases, twenty application/update browser cases and eleven adjacent JavaScript cases. Browser checks exercise delayed/denied reads, cached and failed paging, original/current labels, answer-draft preservation, keyboard/focus, axe and 320 px / enlarged-text layouts. No migration, new report, permission grant, event type or journal is added. The native PostgreSQL application history is checked separately read-only; this view's fictional SQLite/HTTP/browser coverage does not complete every native-engine or institutional acceptance gate.

## Message an applicant

Choose **Message applicant** in application review when you have Communications and person-directory duties as well as admissions access. This opens the [shared Communications workflow](communications.md#message-from-a-person-student-or-application) with the applicant's enabled linked account selected. Save or clear any unsaved casework or educational-background/review feedback first; the shortcut preserves unfinished work rather than discarding it.

Write and save the message, review the recipient, then queue it. Sending still requires the existing separate background-delivery confirmation and configured server worker. Use **Back to application** to continue admissions work. Private notes and application responses are never prefilled into the message. These are deliberate staff messages; saving visible checklist guidance continues to create only the in-portal update described above.

## Private working documents

Open **Documents** in staff application review or **Your applications → Documents** in the portal. Portal editing opens at full width below the application list. Files and filenames load only when the panel is opened. Owners see only files shared with them on their own applications; admissions staff use a separate authorized route. Applicant uploads are visible to their owner. Staff uploads default to staff-only; records staff with the independent document permission can share a file with its applicant or restrict it later. Hidden files and their names do not appear in the owner inventory. Staff privileges cannot override ownership on the portal route.

When deployment setup is complete, choose one PDF, JPEG or PNG of up to **5 MiB**, then **Upload document**. The server checks the bytes instead of trusting the browser's media type or filename extension. Each application can retain twenty documents, and each person can retain fifty across applications. Originals are immutable: upload a replacement as a new file. An interrupted request can retry the same selected file without creating another document or duplicate audit. Changing the file or its initial audience starts another upload reference. A retry preserves the accepted upload without reversing later access changes.

An upload starts in **Waiting for safety scan**. The background worker changes it to **Available** only after a successful scan; unavailable scanners and inconclusive results keep it quarantined. A rejected file cannot be downloaded. You can leave the page while scanning and choose **Refresh documents** later. Uploading or scanning does not mark a checklist item received, verify a credential, accept an application or award credit. Staff review the actual material and update working checklist progress separately.

Available files download as private attachments with a neutral filename. They are never published as media, rendered inline or sent to an AI provider. The application encrypts durable file contents and metadata, keeps storage outside the public directory and verifies the protected scan/routing state and file digest before download. Filenames, file contents, scanner output and disposal reasons stay out of logs and jobs. Downloads are audited by actor and document reference. Expiry prevents owner downloads; authorized admissions staff can still review retained material.

Unfinished file selections and retention forms block switching into applicant messaging or refreshing the document panel. Clear a selection explicitly with **Clear selection** when needed. Application actions—including decisions, histories and opening the person—also respect unfinished casework, documents and education edits. Portal status refresh also preserves pending document work. Late requests cannot restore a detached panel. The shared owner/staff editor uses ordinary labeled controls and an explicit removal review. **File controls** opens one **Access & retention** panel at a time; save or discard changes before opening another file’s controls. Refreshing temporarily disables editing while the request finishes.

### Retention and disposal

Each upload captures its UTC retention deadline from the deployment policy; changing the default later does not silently shorten earlier records. **File controls → Access & retention** requires both `sis:admissions:manage` and the independent protected permission **`sis:admissions:documents:retain`**. Grant the latter deliberately through Roles & access; the regular admin/SIS role does not receive it automatically. Super remains unrestricted.

Authorized staff can **Show file to applicant**, **Keep under hold**, release a hold, or extend retention through an explicitly labeled UTC date. **Save controls** applies access, hold and deadline changes together; changing applicant access has its own audit entry. Holds prevent disposal and survive rescanning. Changes require the current document revision and required audit. Admissions staff can **Quarantine for recheck** to block an available file immediately, or **Retry scan** after a scanner problem is resolved. Both reuse the same bounded worker; files remain unavailable until another successful scan.

Files selected for a recorded admissions decision show **Used in an admissions decision** and cannot enter ordinary disposal, even after a manual hold is released or the original retention date expires. The disposal review verifies the protected decision history as well as the file marker. There is no ordinary button to release formal evidence; a future institution-approved disposition workflow must handle that separately.

Expiry alone never deletes a file. For an expired file without a hold, enter a reason and choose **Review file removal**, then **Permanently remove file**. Confirmation is bound to the actor, file and reviewed revision and expires after twenty minutes. A newly placed hold, changed audience, extended deadline or changed scan state invalidates it. Editing the reason or unsaved retention fields invalidates the on-screen confirmation too.

Approval makes the file inaccessible immediately and replaces identifying metadata with an encrypted disposition receipt. The worker then deletes the encrypted artifact and marks the reference disposed. Cleanup can resume after interruption and still runs when new uploads are disabled. A late scanner result cannot restore a disposed file. A repeated accepted confirmation does not duplicate the approval or reverse later state. There is no automatic deletion of rejected files or mass purge.

Uploads remain working attachments until staff deliberately select them in a [decision review](admissions-decisions.md#select-document-evidence). Case/person-wide holds and working document-retention categories are available below. Formal evidence disposition, further institutional retention rules, backup disposition and deployment acceptance remain separate work. Before approving disposal, the institution must determine whether an original is required by its own retention, verification or hold procedures. This feature does not establish legal compliance.

### Case and person document holds

In **Admissions → application review → Documents**, staff with both `sis:admissions:manage` and `sis:admissions:documents:retain` see **Document preservation**. These controls remain available when new uploads are disabled and before the first file is uploaded.

- **Place application hold** protects admissions files for this application, including later uploads.
- **Place person hold** protects admissions files across every application belonging to this canonical person, including later applications and uploads. It does not place a hold on unrelated transcripts, financial records or other artifact stores.
- Enter a reason, choose **Review hold change**, then **Confirm hold**. **Revise hold change** retains the reason; **Cancel hold change** returns to the document panel. Reasons and confirmation proofs remain only in browser memory. Unfinished hold changes prevent refresh, file-control changes and other application tasks from discarding the review.
- Release each scope separately, with a reason and reviewed confirmation. Releasing a case hold leaves a person hold intact; releasing either scope does not clear per-file holds, shorten retention or release recorded decision evidence. Holds have no automatic expiry.

One active hold is allowed per scope. Each placement has its own ULID record and keeps its placement reason, recorder and UTC time after release; release retains a separate reason, recorder and time. **Hold history** shows twenty records per page with **Earlier holds** and **Newer holds**. It includes this application's holds and the person's shared holds; it excludes other applications' case-only holds. Displayed account names are current labels for the retained recorder identities; unavailable accounts remain identified in protected records.

Holds prevent disposal, not safety scanning. They neither make a file public nor change applicant download expiry. Applicants receive no hold history, reasons or retention controls. Ordinary admissions staff can see that a file is protected without receiving the custodial reasons. Staff who lose custodial authority cannot read or change the hold records.

Both disposal review and confirmation recheck current holds. A hold accepted after disposal approval also stops pending physical cleanup of an artifact still on disk. The prior approval has already removed the filename/access metadata and made that file unavailable; a subsequent hold preserves the remaining encrypted artifact without restoring that metadata or applicant access. Files physically removed before a hold was accepted cannot be restored by this control. Releasing the last applicable scope hold allows previously approved cleanup to continue.

Placement, release and physical deletion share the canonical-person lock. The worker authenticates current hold metadata before deletion and performs the local unlink while that lock is held. Scanning remains outside database locks. Preserved cleanup rows are skipped when choosing work so they cannot block unrelated cleanup or scans; a hold appearing after candidate selection is checked again under the lock. The existing one-to-three-document worker limit remains unchanged.

These holds are operational CRUD with required atomic audit, not a second domain event journal. Metadata and routing are encrypted and authenticated. Twenty-minute reviews bind the actor, application, scope, action, reason and current scope revisions. Confirmation rechecks permissions, parent identity, sources and expiry under shared locks and after audit. A place/release cycle invalidates an earlier unconfirmed review even if no hold is currently active. Matching accepted retries return the original placement/release outcome after later releases, new cycles or proof expiry; they do not reapply the action.

Migration **20261122000001** adds the empty protected hold table and portable indexes/foreign references. It grants no permissions and invents no earlier holds or academic events. Its schema compiles for MySQL, PostgreSQL, SQLite, SQL Server and Oracle; this is not live qualification of all five engines. For a focused real-router check, run the configured PHP interpreter with `tests/Support/ConsoleStatusSmoke.php check-document-holds`. It uses fictional SQLite records and checks CSRF, private/no-store responses, debug suppression, dual authority, applicant privacy, confirmation/release and exact retries.

### Retention categories and file classification

Use **Admissions → Document retention** to configure working admissions-document categories before uploading or reviewing files. This setup requires both `sis:admissions:manage` and `sis:admissions:documents:retain`, independently of the offer/checklist policy permission. Those same two capabilities protect classification and custodial history.

The library supports up to **25 categories**, including retired identities. Each has a stable ULID, an immutable institution-defined code, a name, **1–3,650 days from upload**, and an availability switch. Codes use uppercase letters, digits, periods, underscores or hyphens and start with a letter. Retire an existing category rather than deleting it or repurposing its code. Names and periods can change in a later policy; previously selected definitions retain their original meaning.

Choose an active **Default for new uploads**, or keep **Deployment fallback (uncategorized)**. Enter a reason and choose **Review retention change**, then **Confirm retention change**. The review identifies the proposed default and category definitions. **Revise retention change** preserves the draft; **Discard retention changes** restores the loaded policy. Back/refresh controls preserve unfinished changes. **Earlier policies** shows retained definitions, reasons, UTC recording times and current labels for the actual recorder accounts, twenty publications per page.

A confirmed policy affects subsequent uploads only. Every new owner/staff upload captures its selected policy identity/revision, category definition and days, or the actual deployment fallback used at upload. The initial basis is retained with the upload's actual actor/time in protected operational storage. Upload retries keep the original basis even if today's default changes. Upload availability, scanner review and a valid deployment fallback remain deployment controls; publishing categories does not enable uploads or approve a scanner.

For a retained file, open **Documents → File controls → Document category → Classify document**. Choose a currently available category and give a reason. Review the recorded earlier basis, proposed basis and current/proposed deadline before confirming. Category days run from the **original upload time**, not the classification date. The accepted deadline is the later of the file's existing deadline and the selected category's deadline. A shorter category never shortens an existing deadline or reverses an explicit extension. Classification preserves the applicant download cutoff as well as sharing, scan state, file/scope holds and formal decision-evidence protection. New uploads capture a separate applicant cutoff; for older files that used one expiry for both purposes, classification explicitly preserves that actual earlier cutoff. It never reopens an expired applicant download merely by extending retention. Staff see **Applicant access ends (UTC)** beside a shared file’s retention deadline. Explicit sharing and manual **Extend through** controls retain their earlier ability to extend applicant access, with a separate audit entry when that cutoff changes.

**Classification history** retains initial upload capture and later explicit classifications separately, including earlier/proposed basis, deadline, reason and recorder. Pages contain twenty decisions for that file; no filename or file contents are copied into classification records. Earlier files without recorded policy evidence remain **Earlier retention basis unknown**. The first explicit classification records that unknown earlier basis; it does not invent an earlier category or configuration. Protected history and original receipts survive approved disposal without recreating the file or its access metadata. Ordinary inventory still omits removed files; broader institutional retention/disposition of reference records remains separate.

Both policy publication and file classification use twenty-minute actor/source-bound reviews and exact original-result retries. Configuration writes and uploads share the permission lock; file changes additionally share the canonical-person/document locks with other document controls. Confirmation rechecks the policy, file version, parent, authority and proof expiry through mandatory audit. Document metadata, classification and audit commit together; failed checks roll back all database changes. Successful upload metadata/basis capture and its existing upload audit are also atomic. Replayed receipts do not publish another policy, extend retention again or resurrect a disposed file.

The staff editor preserves classification reviews while other file-control drafts are unfinished. Permission loss clears affected private controls/history, and closed drawers ignore late setup responses. Policy/category reviews support keyboard focus, Bootstrap controls/icons, narrow reflow and enlarged text; confirmation focus waits for layout and remains visible below the drawer navigation. Labels use `trans_html` because translated HTML is not allowed, and browser insertion remains text-only. Applicant document responses contain no custodial classification history, policy basis or private reasons. Ordinary admissions staff can see a file's current recorded basis without gaining custodial history/editing authority.

Migration **20261122000002** creates empty protected policy-definition and file-classification tables. It changes no earlier file metadata, role grants or domain events. These are versioned operational definitions and records, not a second domain event journal. SQLite behavior and five-dialect schema compilation are tested; native MariaDB policy/receipt/history queries have also been checked. Use the configured PHP interpreter with `tests/Support/ConsoleStatusSmoke.php check-document-retention` for the focused fictional real-router/CSRF/private-response check.

Institutional categories describe this working document-retention workflow. They do not certify credentials, establish a legal retention minimum, release formal admission evidence, or replace backup/orphan disposition and scanner deployment acceptance.

### Operator setup

1. Apply migrations **20261117000004**, **20261122000001** and **20261122000002**. The former adds `sis_admission_documents`, ULID references, queue/quota indexes and the protected retention permission; the second adds scoped document holds; the third adds protected retention definitions/classification records. They change no academic/profile events or role grants.
2. Provide PHP `fileinfo`, set `upload_max_filesize` to at least `5M` and `post_max_size` to at least `6M`, and allow the multipart request through your web server’s body limit (at least 6 MiB). Provide private writable `storage/app/admissions/documents` storage, and a supported, updated ClamAV daemon with its `clamdscan` client. Use a restricted local socket. See the [official scanning guide](https://docs.clamav.net/manual/Usage/Scanning.html).
3. Review the daemon's limits and privacy settings before accepting files. Stream/file limits must accept 5 MiB; limit-exceeding and encrypted-document cases must alert rather than pass. Keep signatures current and disable debug/metadata retention and content-logging hooks. Relevant [official configuration options](https://github.com/Cisco-Talos/clamav/blob/main/etc/clamd.conf.sample) include `AlertExceedsMax`, `AlertEncryptedDoc`, `LeaveTemporaryFiles` and `GenerateMetadataJson`. Protect PHP upload temporary storage and ClamAV temporary storage too; HTTP uploads necessarily pass through PHP's temporary upload handling before durable encryption.
4. Set an institution-approved retention period and scanner executable in `config/admission_documents.php` / environment. Defaults keep new uploads off:

   ```dotenv
   SIS_ADMISSION_DOCUMENTS_ENABLED=false
   SIS_ADMISSION_DOCUMENT_RETENTION_DAYS=0
   SIS_ADMISSION_DOCUMENT_SCANNER=/usr/bin/clamdscan
   SIS_ADMISSION_DOCUMENT_SCANNER_REVIEWED=false
   ```

   Choose the retention days deliberately, verify the scanner configuration and real clean/rejected/failed-scan behavior, then set both boolean flags to `true`. `SCANNER_REVIEWED` records operator acknowledgement; it does not inspect the daemon's settings. The `clamdscan` client cannot override daemon engine policy.
5. Run Codefy's existing server scheduler once a minute. It registers **`sis:admissions:documents --apply --limit=1`**, with one instance at a time. For a manual bounded pass, use `php codex sis:admissions:documents --apply --limit=1`. Without `--apply`, the command only shows usage. A client attempt times out after twenty seconds; unavailable scans wait fifteen minutes unless staff request a retry, and abandoned claims can be reclaimed after five minutes. The browser never scans or deletes file contents.

Back up the database, encrypted artifact directory and the institution's application encryption key together. The database alone does not contain the uploaded bytes. Filesystem writes and database commits cannot form one transaction: failed in-transaction uploads attempt cleanup, and unindexed encrypted artifacts left by interrupted commits are never served. Administrators must include orphan-artifact reconciliation and backup disposal in their storage procedures. Broad automated storage cleanup is not enabled by this feature.

Runtime tests use fictional files and injected scanner outcomes. No real scanner is installed or launched by the tests. Schema compilation is checked for the framework's five supported database dialects; deployment scanning acceptance still requires the institution's actual daemon configuration.

## Storage, privacy and setup

Migration **20261117000002** adds `sis_admission_cases` and `sis_admission_checklists` with ULID primary keys, parent references and indexes for assignment, work status, due work and preset scope. Case contents, preset definitions and request fingerprints are encrypted. Only the routing fields needed by the staff queue remain separate indexed columns. Detailed reads verify protected content against its parent and routing metadata; unreadable data is rejected. Applying this additive migration converts or deletes no existing application, profile or event facts.

This is audited CRUD for working case data and mutable setup, consistent with the selective event-sourcing boundary. It does not introduce another event table or manufacture historical events. Case saves lock the canonical person and application, use expected revisions, and recheck the actor and admissions permission after waiting and before completion. Preset saves serialize the author/configuration rows and recheck form-management authority. Audits and changes commit atomically. The existing authoritative admission decision/reason and matriculation histories remain separate; this checklist is not a retained decision-evidence snapshot.

Migration **20261117000003** adds `sis_admission_updates`, with a ULID primary key, an application reference, a unique application/owner pair and an index for owner/date pagination. It stores only identifiers, public-update/read counters and a UTC timestamp. This operational CRUD table is separate from authoritative admissions history. It neither copies private text nor adds an event store. Owner lookups check both the reference owner and the person account currently owning the application. Retention and account-disposition rules still need institutional configuration; there is no automatic purge.

All endpoints retain framework CSRF for browser mutations, private/no-store responses, and suppression of debug collectors. No notes, answers or definitions enter browser storage, logs or queued jobs. Current actor labels are resolved only for authorized staff views. Both case and preset values are redacted from debugging. There is no delete API or destructive rollback; decide institutional retention, legal holds and disposition before production rather than enabling a speculative automatic purge.

Endpoints are:

- Staff `GET`/`POST /sis/applications/{application}/casework/`.
- Staff `GET /sis/admissions/assignees/` with literal `search` and exclusive `after` cursor.
- Staff `GET /sis/admissions/follow-ups/` with `scope`, `due` and exclusive `after` cursor.
- Staff `GET /sis/admissions/checklists/` with `application_id`, `search` and `after`.
- Authorized preset `GET`/`POST /sis/admissions/checklists/{preset}/`.
- Owner-only `GET /portal/api/applications/{application}/next-steps/`.
- Signed-in page `GET /portal/admissions-updates/`.
- Owner-only `GET /portal/api/admissions-updates/` with `include_read`, `after_at` and `after`.
- Owner-only `GET /portal/api/admissions-updates/{id}/` returns notice metadata for an exact link; guidance remains a separate owner-authorized application read.
- Owner-only `POST /portal/api/admissions-updates/{id}/read/` with the displayed `revision`.
- Owner-only `POST /portal/api/admissions-updates/{id}/delete/` with the exact displayed `revision`; missing/foreign references return the same harmless deletion response.

The additive schema compiles for the framework's five database dialects. Runtime checks cover fictional SQLite data and the configured MariaDB installation. Broader driver deployment acceptance remains separate.

Explicit file bindings for acceptance, rejection and outcome correction now use the existing decision journal. Institution-wide retention/disposition policy, optional automatic email/push delivery and any policy that gates an admissions decision remain later work. New uploads remain disabled until the operator completes scanner and retention setup; use established delivery channels until then.

## Applicant withdrawal requests

[Applicant withdrawal](admissions-decisions.md#applicant-withdrawal) has a dedicated **Admissions → Withdrawal requests** worklist beside the ordinary follow-up queue. Opening a request expands its shared owner/staff panel on the application. Staff can approve withdrawal or close the working request under independent withdrawal authority. Institutional **Policies** default to staff approval. Pending requests pause matriculation and offers, while actual withdrawals preserve person/student capabilities and use the existing protected application history.

[Institutional checklist policy](admissions-decisions.md#checklist-policy-before-acceptance) can now require resolution of saved needed items before acceptance. Checklists remain advisory by default. Current working edits do not rewrite earlier outcomes; the actual decision retains the reviewed checklist evidence.


## Education corrections and owner follow-up

With person-management access, the selected application includes the shared educational-background editor and an on-demand **Education update requests** panel. Admissions review authority still controls **Mark reviewed** and **Request changes**. A saved review retains its own feedback and person-history event. Use **Request an education update** to deliberately compose and send a separate owner-visible follow-up; unsaved background/review feedback stays in place until saved or cleared. Instructions and request resolution use existing profile-request authorization, expected versions, encrypted storage and required audit.

The applicant can open the same education-only follow-up on **Portal → Applications**, edit the existing background and reply without discarding an unfinished application. The personal-information page also retains access to the request. A correction, a response, a background review and a request closure are separate actions. None changes the admissions outcome, satisfies a formal checklist, verifies a credential or awards academic credit automatically. See [the complete education follow-up workflow](person-identity-and-accounts.md#education-follow-up-beside-an-application). No notification, report, migration or parallel person record is added.
