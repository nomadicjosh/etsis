# Admissions workbench

Admissions staff keep application responses, educational background, the working checklist, office routing and follow-up together in the existing application review. Formal decisions and matriculation continue through their own reviewed, retained workflows. Working casework does not accept/reject an application, create a student, award credit or change registration eligibility.

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

## Work the follow-up queue

From **Admissions**, choose **Follow-up queue**. Filter **All open**, **Assigned to me** or **Unassigned**, optionally check **Due today or earlier**, then refresh. Open/waiting cases appear in pages of twenty, ordered by case creation identity with exclusive cursors. Private notes, instructions and encrypted case payloads are not loaded into this queue.

The follow-up date is the earliest of the case's explicit follow-up date and its unfinished checklist item dates. Completed or not-needed items contribute no deadline. Done cases leave the queue but remain accessible through their applications. Dates use the institution's configured clock; the queue displays the institution date used for the comparison. This queue tracks work; it does not send automatic messages or make admissions decisions.

## Applicant next steps

Applicants open **Start your application → Your applications → Next steps** for the relevant program/term. The read happens on demand and preserves a new application being edited on the same page. It shows the office, applicant next step and only the checklist items staff marked visible, including instructions, progress and due dates. It excludes private notes, internal tasks, assignee identities, work status and revision metadata. Staff privileges do not override ownership on this portal endpoint.

If staff have not added guidance, the portal directs the applicant to contact admissions. Checklist progress alone is not an admission approval or registration confirmation. Reading next steps neither changes the application nor writes an event/audit. Closing it or refreshing the application list prevents detached viewers from restoring late results.

Withdrawn applications are paused in the follow-up queue without changing their working case. Returning one to review restores its unfinished tasks; this is separate from changing a student record or closing a person account. See [application withdrawal](admissions-decisions.md#withdraw-an-application-or-return-it-to-review).

## In-portal admissions updates

Applicants can choose **Admissions updates** from their application toolbar or campus-services navigation. This page is available to a signed-in person before they have a student record. It loads only update metadata, without requesting the full student overview. Updates show the application program and entry term, the update time and whether they are unread. Use **Read update** to open the current next steps. **Include read updates** and explicit refresh let applicants revisit earlier guidance; lists contain twenty records per page.

Saving a change to applicant-visible office, next step or checklist guidance automatically creates an unread update for the application's current owner. Staff-only notes, internal tasks, assignments and working status do not create an update. The update and case save commit together. Existing cases receive no fabricated backfill; their next visible change creates the first update.

Each application/owner pair has one reference with a public-update counter. Repeated changes coalesce into that reference, so it is an inbox of current guidance, not a timeline of earlier wording or an extra event journal. It contains no copied checklist, note or message body. Private case revisions do not advance its public counter. Changing the person account owner makes earlier-owner references inaccessible; staff authority cannot override current ownership.

Loading the list does not mark updates read. After successfully opening guidance, the page submits an explicit, CSRF-protected read confirmation for the update it displayed. A delayed confirmation cannot hide a newer update, and repeating an older confirmation cannot undo a later read. Closing the viewer before guidance arrives prevents that detached request from confirming the update. Read-state changes require audit and commit atomically.

These are in-portal updates. This workflow sends no email or push messages and does not start a background delivery job. Institutions should continue using their established delivery channels for time-sensitive communication.

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

Uploads remain working attachments until staff deliberately select them in a [decision review](admissions-decisions.md#select-document-evidence). Institutional record categories, case/person-wide holds, formal evidence disposition, backup disposition and deployment acceptance remain separate work. Before approving disposal, the institution must determine whether an original is required by its own retention, verification or hold procedures. This feature does not establish legal compliance.

### Operator setup

1. Apply migration **20261117000004**. It adds `sis_admission_documents`, ULID references, queue/quota indexes and the protected retention permission without changing academic/profile events or granting roles.
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
- Owner-only `POST /portal/api/admissions-updates/{id}/read/` with the displayed `revision`.

The additive schema compiles for the framework's five database dialects. Runtime checks cover fictional SQLite data and the configured MariaDB installation. Broader driver deployment acceptance remains separate.

Explicit file bindings for acceptance, rejection and outcome correction now use the existing decision journal. Institution-wide retention/disposition policy, optional automatic email/push delivery and any policy that gates an admissions decision remain later work. New uploads remain disabled until the operator completes scanner and retention setup; use established delivery channels until then.

## Applicant withdrawal requests

[Applicant withdrawal](admissions-decisions.md#applicant-withdrawal) has a dedicated **Admissions → Withdrawal requests** worklist beside the ordinary follow-up queue. Opening a request expands its shared owner/staff panel on the application. Staff can approve withdrawal or close the working request under independent withdrawal authority. Institutional **Policies** default to staff approval. Pending requests pause matriculation and offers, while actual withdrawals preserve person/student capabilities and use the existing protected application history.
