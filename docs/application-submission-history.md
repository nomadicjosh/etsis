# Application submission history

Implemented 2026-11-09. New form submissions and direct application entry now start the existing admissions stream with **AdmissionApplicationWasSubmitted**. [Acceptance/rejection](admissions-decisions.md) and [matriculation](student-matriculation.md) continue from that genuine creation context. Earlier applications retain their explicit unknown-history boundary.

## Applicant and staff workflow

Applicants continue using **Portal → Apply**: choose a program and entry term, complete the program's enabled form and submit it. Authorized staff can use **Start an application** for a person and complete the same program form. The existing submission identifier stays with that form in browser memory for retries; no private answers or review tokens are saved in browser storage.

The application, encrypted answers, submitted-form reference, actual submission event, stream head, submission-date read model, operation receipt and required audits commit together. A failure leaves no partial application or submission history. This does not create a student, accept the application, assign a cohort or post a charge.

Staff can open **Admissions → an application → Application history** before a decision has been made. It shows when the application was actually submitted, program/term labels as submitted, the selected form revision and whether the person used their own account or admissions staff entered it for them. A later acceptance/rejection appears alongside that submission. These history screens exclude answers, actor identifiers, reasons, fingerprints and raw event envelopes.

The current person owner can privately read their application answers, including answers entered by admissions staff on their behalf. Admissions management authority also permits that read. Another person's account, directory access and student-read permission alone do not. The original submitting actor remains recorded privately; permitting the applicant to read staff-assisted answers does not let them claim that staff member's retry receipt.

Admissions review displays answers under the submitted form's original container titles and columns. Long answers retain their line breaks, and missing answers have a neutral **No answer provided** label. Recorded answers are displayed as text without reapplying current conditional rules or field limits. Later form changes do not reinterpret the earlier submission.

## Applicant access to submitted answers

The connected [admissions workbench](admissions-workbench.md) also supplies on-demand **Next steps** for each owned application, including staff-selected checklist items, instructions and due dates. It preserves unfinished applications and excludes internal tasks, private notes and assignee information. Working progress is separate from retained admissions decisions.

**Your applications** also shows the next step alongside each current status. Submitted applications direct the applicant to watch for a decision or contact admissions; accepted applications direct them to the office for the program's next steps. **Not accepted** uses neutral guidance without exposing a private decision reason. **Student record linked** identifies matriculation and offers **My campus**, including when an existing student record was reused. It does not promise registration eligibility, enrollment or access to another person's records. Unknown statuses show a neutral request to contact admissions.

Use **Refresh status** to check for a decision while keeping an unfinished application on screen. A live status message announces checking, completion or failure; failed refreshes keep the previous results and can be retried. Refresh runs only when requested or after a successful submission, without automatic polling. Late responses from an older refresh cannot replace newer results.

Opening the signed-in application page and refreshing its status both use the owner-only `GET /portal/api/applications/`. Its response is `{person_id, items}`: the canonical person ULID needed for submission and up to 50 application metadata records, using the same list query as the portal overview. The person reference is present even before the first application. It checks current person ownership on every read, including for staff accounts, and keeps responses private/no-store with debug collection suppressed. Neither opening this page nor refreshing its list loads submitted answers, contact details, financial statements or event streams. Answers still require their separate authorized read. Other campus services retain their existing context and authorization.

Open **Portal → Start your application → Your applications → View answers**. Applicants can reopen their original answers, including applications entered by admissions staff. The viewer uses the retained form's name, questions, sections and columns. It is read-only. Program and term names in the application list reflect current catalog setup; they do not replace the retained form or original submission history.

Answers load only when requested. **Close answers**, or selecting the same application again, clears the viewer and returns focus to its opener. Reading an earlier application preserves a new application being edited on the same page. Failed reads show an inline **Try again** action. Switching applications, closing the viewer or leaving the screen prevents late responses from restoring discarded answers. A successful submission refreshes the application list without reloading the page.

The signed-in overview lists at most fifty applications in descending ULID order, including entries with no attached form answers. **No form answers** means no submission artifact is linked; it does not claim an older application was never submitted. This list contains routing metadata and current catalog labels, with an owner-bound query and a unique submission/application join. It does not load definitions, decrypt answers or replay admissions history. Opening answers uses the existing private **GET `/sis/form-submissions/{id}/`** endpoint, which independently rechecks current person ownership or admissions-management authority and verifies the retained submission. Knowing a submission identifier grants no access or ownership of its retry receipt. No new schema or event storage is needed.

## Application form studio

### Completed application-form milestone

The account-based application-form workflow is implemented. It covers:

- A searchable, paged form library with institution-defined academic-level defaults and program overrides.
- Eight question types, visible question labels, hints, required answers, choices and field limits.
- Containers and responsive columns, pointer/keyboard ordering, direct copy/trash controls and bounded Undo/Redo.
- Conditional questions with nested rules, stable references and shared preview/submission behavior.
- Explicit save, preview, reviewed immutable publication and reviewed default removal.
- Signed-in prospect and staff-assisted submission, answer review before sending, protected retries and retained answers.
- Private applicant answer access and admissions review using the original submitted definition.

Question-key edits update column placement and every dependent condition in the working draft. Literal choice values are preserved. Invalid duplicate keys leave the definition untouched. Labels, hints and choices are validated before save, including Unicode character limits, and errors open the affected question. Published definitions and earlier submitted answers retain their original keys.

Document checklists/uploads, office assignments, offers and other admissions case-management features remain separate roadmap items. This milestone describes implemented functionality in alpha, rather than production deployment or legal-compliance certification.

Open **Application forms** to build a draft, preview it, save it and then **Review publication → Confirm publication**. Publication reviews the exact saved revision. Unsaved edits must be saved first; opening a published form creates a new draft revision and preserves the original.

Use **Find a form** to search form names, program codes/titles or the institution's academic-level codes/titles. **Show** filters All forms, Drafts, Published forms or Enabled forms. Published includes earlier revisions; Enabled shows the current application defaults. **Previous** and **Next** browse twenty records at a time, including forms beyond the former hundred-record ceiling. Searches and filters run before that page limit. Results contain current metadata, not questions, layouts, submitted answers or event history. They are a live library, so a later publication can change which form is enabled.

Browsing and retrying a failed search preserve the open draft, its entered values and its collapsed fields. Earlier results remain visible if a request fails; **Find** retries it. **Clear** resets library filters. Opening a different form still asks before discarding unsaved work. **Hide library** gives the editor the full available width; **Show library** restores it. On narrow screens the library starts closed, and choosing a form returns focus to its name in the editor. These view choices stay in memory.

Choose **An academic level** for a reusable default, or **One academic program** for a program override. Academic levels come from **Academic catalog → Curriculum → Departments & levels → Academic levels**. They are institution-defined: Undergraduate and Graduate are examples, not built-in codes or seeded requirements. An installation with no levels can still publish program forms. An authorized catalog administrator can open **Academic levels** directly from the form editor.

Applicants still choose their intended program and entry term. A program-specific form takes precedence; otherwise the program uses the form enabled for its current academic level. Programs without either form do not accept form-based applications. Removing a program override exposes the level default if one is enabled. Removing a level default leaves program overrides enabled. Both actions explain this before confirmation. A saved draft's target stays fixed; create a new draft to change its target.

During a first save, the form's application target stays fixed while questions remain editable. Publication review waits for a pending save to finish. If a first save fails, the target choices become available again. Starting a replacement draft after confirming discard gives it its own target choices; the older save's response cannot attach its identity, overwrite edits or show an unrelated failure on that replacement draft.

Removing a default confirms the exact enabled form shown in the review. If another administrator replaces it first, removal is rejected and **Refresh forms** reloads the library without removing the replacement. The form definition and submitted answers remain intact. Both disable endpoints now require `form_id` in the POST body; the service rechecks publication authority and the binding under the existing target lock, and removal and its required audit commit together.

Field cards display their current question label and type. Expand or collapse individual cards or all cards, and reorder them using drag handles or the keyboard-accessible arrow buttons. **Duplicate field** and **Remove field** are always-visible copy and trash icons beside the other field controls. A **Move to another column** icon appears when containers are in use. The controls have accessible names and hover labels, support keyboard use and restore focus after edits. Each question has a label, optional hint and required-answer setting. Dropdown and **Single choice** radio questions provide one choice per line. **Field settings** holds the unique stable key used by conditions and saved answers. Forms support up to 40 questions, with up to 30 choices per choice question.

### Undo and Redo

**Undo** and **Redo** cover question labels, hints, choices, required answers, field settings, visibility conditions, containers and column placement. Restoring a removed question brings back its stable key and placement, so other questions can refer to it again. Continuous typing in one control is grouped as one edit; a new question or layout edit clears Redo. Form name and application target remain separate from this editing history, and text inputs retain their normal browser editing behavior.

Restored changes are unsaved. Use **Save draft** before reviewing publication, including after undoing a previously saved edit. Undo and Redo send no request and cannot reverse a server save or publication. An in-flight save still retains its returned draft identity and current expected version. Opening another form, starting a new form, confirming discard or successfully publishing starts a fresh editing history. Confirming discard also clears the unsaved working copy rather than leaving it behind on a hidden screen.

Editing history stays in this page's memory, with up to thirty recent changes and a two-MiB serialized-definition budget; larger forms may retain fewer changes. It is cleared when the page is left and never written to browser storage, application tables or the event store. This is an editor convenience, separate from authoritative publication and admissions history.

### Containers and columns

Choose **Add container** to group the questions already in your draft. Give it a **Container title**, an optional **Container introduction** and choose **1 column**, **2 columns** or **3 columns**. Add more containers for subsequent parts of the application. Up to twelve containers are supported; forms without containers keep their original single-column layout.

Each column has its own **Add a field** menu. Drag a question into another column, or use its **Move to another column** icon to choose a destination with a keyboard. Arrow buttons move a question within its column; container arrows and drag handles reorder whole containers. Duplicating a question places the copy beside the original in that same column. Remove a container after moving or removing its questions. Reducing the number of columns moves questions from removed columns into the last remaining column; it never deletes them.

Reading and condition order runs down the first column, then down the next column, then through subsequent containers. Reordering updates that order and checks earlier-question references before save. All columns stack in the same order on narrow screens. Preview uses the same container titles, introductions, columns and field controls as applicant and staff submissions.

### Completing and reviewing an application

Portal applicants and admissions staff use the same **Your answers → Review & submit** flow. **Review answers** checks the visible questions, then displays their answers with the same container and column structure. **Edit answers** returns to the entered controls without losing values. Hidden conditional questions are excluded from this review and the submitted answers. Longer forms offer keyboard-accessible section shortcuts; entirely hidden sections do not leave empty headings or shortcuts.

Only **Submit application** sends the application. Reviewing or editing makes no submission request and creates no event. Answers stay in memory on the open page; closing or reloading it before submission loses them. There is no browser-storage autosave. Staff-assisted intake keeps the selected person, program and entry term visible through review.

While sending, the confirmation and edit buttons are disabled and a status message announces progress. Field errors return to the existing answers with linked, focused errors. A temporary request failure keeps the reviewed answers available for retry with the same submission identifier. Server-side authorization, validation and transactional receipt handling remain authoritative. Responses from a discarded application screen do not replace the next screen.

### Question guidance and validation

Under **Field settings**, short/long text and email questions can have a placeholder and minimum/maximum character counts. Whole-number questions support inclusive minimum/maximum values. Date questions support inclusive earliest/latest dates. The editor rejects reversed ranges and unsupported settings. Limits are rechecked after Undo and other changes that rebuild question cards. Saving reveals a collapsed question with an invalid value, opens its settings and focuses the affected control; its expand/collapse button stays consistent with the visible content. The supported limits remain 191 characters for short text/email, 4,000 for long text and whole numbers from -999,999,999 through 999,999,999. Optional unanswered questions stay optional even when they have limits.

Use **Preview form → Test answers** to exercise required fields, radio choices, character limits and conditional questions without submitting anything. Applicant and staff forms use this same renderer, show field guidance, and provide an error summary linking to the affected controls. Rejected submissions preserve entered answers. Unicode character limits use character counts, including emoji, consistently with server validation. Server validation remains authoritative for direct or modified requests.

**Edit conditions** opens the existing visual QueryBuilder for that question. Combine earlier answers using **All** or **Any**, nested groups, lists and numeric comparisons. **Apply conditions** commits the rule to the draft; **Cancel** leaves it unchanged; **Always show** removes it. Rules can only reference earlier questions. Moving or deleting a source question requires repairing its dependent conditions before saving. Preview evaluates the same bounded rule language as submission validation. Hidden questions do not require answers and their values are omitted from the submitted answers.

Sorting uses self-hosted [SortableJS](https://github.com/SortableJS/Sortable), with reduced-motion support and arrow-button alternatives. The builder and preview keep working values in memory; no applicant answers or drafts are stored in browser storage. Failed saves retain edits, and edits made while a save is pending remain unsaved rather than being overwritten by the response.

Migration **20261116000007** makes the existing form's program reference nullable, adds an optional academic-level reference and introduces the unique level/default binding table. It preserves existing program forms, bindings, protected answers and receipt fingerprints. A shared-form submission additionally binds its actual chosen program into its receipt, preventing the same receipt from being reused for another program. Historical reads and exact retries use the originally retained program/form facts even after current classifications or defaults change. Drafts and bindings remain audited configuration; consequential submissions continue using the existing authoritative event store.

Migration **20261116000008** adds nullable layout storage and widens MySQL's question-definition and encrypted-answer columns to accommodate valid forms exceeding 64 KB. Other supported engines already use large text/LOB types. Existing definitions, encrypted answers, bindings and receipts are preserved. Layout and explicit question settings become part of the retained publication digest; older forms without them keep their original digest and reading order. Changing either later requires a new publication. No additional event table, fabricated history or conversion of earlier answers is introduced. Compiler checks cover the five framework dialects; runtime checks cover SQLite fixtures and the configured MariaDB migration, with broader deployment acceptance still pending.

The paged library needs no schema change. **GET `/sis/forms/`** accepts optional `search` up to 80 characters, `state` as `all`, `draft`, `published` or `enabled`, and an exclusive ULID `after` cursor. Its current response is `{items, next_after}` with at most twenty metadata records. Search terms are bound and SQL wildcards are escaped. Current `sis:forms:manage` authority is required independently of publication or catalog permissions. Private/no-store responses and debug suppression still apply; library reads append no events or audits.

### Lightweight verification

Run `npm run test:forms` for browser-free checks of reference-preserving key edits, placement, restored working definitions, input limits and a forty-question form. This uses one Node process with a 32-MiB heap cap. The focused PHP form/submission suites cover target selection, publication, access, encrypted answers, retries and stale-removal/audit rollback. This completion pass also verifies the removal endpoints through the framework HTTP/CSRF pipeline. Existing browser scenarios cover the interactive builder; they were not rerun in this pass to avoid the reported workstation slowdown.

`node --max-old-space-size=32 tests/JavaScript/portal-application-context.test.cjs` checks the actual portal startup without a browser or network. It verifies the application-only request, empty first-application context, rejection of invalid context and the overview's separate full-context read. Owner-bound PHP checks and the isolated HTTP fixture cover the corresponding response shape and private metadata boundary.

## Preserved facts

The new event retains application/person/program/entry-term ULIDs, initial submitted status/version, program and term labels, form/direct channel and the authorization relationship used at submission. Trusted context records the actor and actual effective/recording times. Names, institutional IDs and answers are not copied into the event.

For forms, the event pins the submitted record/form identities, form revision/name, existing answer fingerprint and a definition digest. The answer and form projections are verified against those protected references before owner answer reads, staff application review, admissions decisions and matriculation use them. Changing both a stored answers value and its current fingerprint does not change the original reference in the journal. Re-encrypting unchanged answers preserves verification. Replacing the active form leaves earlier submitted revisions intact.

The frozen fingerprint reader compares recorded values without executing today's conditional-answer rules again. New commands still validate against their selected form. A later program/term rename can be displayed in today's catalog while the submission retains its original labels; decision-time labels remain a separate retained snapshot.

Answers and published form definitions remain protected retained database artifacts. The event deliberately stores references/digests rather than another copy of their content. Deleted answer/form artifacts cannot be reconstructed from a digest; they require the institution's existing backup, encryption-key and retention controls alongside the journal. Operational rebuild and retention tooling remain staged work.

## Retries and earlier applications

Submitting the same form identifier with the same canonical answers returns the existing application. It remains safe after acceptance/rejection, matriculation or replacement/disablement of the active form. Changing the person, term, form, canonical answers or submitting actor cannot reuse that identifier. A retry never changes the current application/student status or appends another birth event.

The direct application API is restricted to staff with `sis:admissions:manage`; applicants must use their program form and cannot bypass its required answers. **POST `/sis/applications/`** now requires an explicit canonical ULID `application_id` alongside `person_id`, `program_id` and `entry_term_id`. Reuse that application ID only for an exact retry of the same request by the same actor. The original creation result remains submitted even if today's application has since advanced; reload its current review to see the latest status. An earlier untracked application identity cannot be claimed as a new creation receipt. Staff's existing form-based GUI keeps its current submission-ID contract.

An older form receipt can still be retried through its existing actor/fingerprint checks without inventing a creation event. Reads and previews never manufacture submission history. The first reviewed decision on an earlier submitted application still records **AdmissionApplicationWasObserved**, followed by the actual decision. New applications instead append their decision after their genuine submission; they do not receive a fabricated observation or original timestamp.

## Storage and validation

All events continue using **`sis_history_events`**, its existing stream heads and atomic operation receipts. The application status/version is checked against replay, including the recorded matriculation when a tracked acceptance advances. There is no parallel event table. The submission-date read model below has its own additive migration; earlier event payloads and their v1 schema contracts remain unchanged.

Creation locks the person before term/program setup and rechecks the current owner/permission and actor after waiting. Direct entry always rechecks admissions management permission, including when that staff member owns the person record. Retry reads also lock the existing application and recheck authorization. Identifier collisions are translated at the persistence boundary without returning driver details. Browser mutations retain CSRF protection; private responses use no-store and debug collectors stay suppressed. Replay and receipt reads use bounded event pages.

Fictional integration checks cover both channels, ownership and staff assistance, form/answer verification, protected payloads, replay through decisions/matriculation, stale or conflicting identifiers, earlier receipts, re-encryption and complete rollback. Real-provider/router checks exercise creation, owner reads, authorization, CSRF, private responses and retries. Browser checks cover known submission history before/after decisions and the earlier unknown-history path, including keyboard use, accessibility and narrow screens. Those original submission-workflow checks used fictional fixtures. The subsequent reporting migration is described below.

Offers, documents/checklists, further institution-specific approval/reopening policy, complete operational reconstruction and further admissions transitions remain later stages.

[Outcome corrections](admissions-corrections.md) now retain reasoned pre-matriculation changes and original decisions in this same stream.


## Submission-date reporting

Implemented 2026-11-14. Open **Report Studio → Applications & admissions**. **Submitted on** is included in the initial columns. Under **Conditions**, choose that field and **between** to enter a start/end date. Add status, current program, intended entry term or independently authorized student/registration conditions as needed. These use the existing general builder; submission reports are not a separate reporting screen.

The launch screen also offers **Applications with recorded submission dates** and **Applications with unknown submission dates**. An unknown date means the original submission was not recorded in the current journal. It does not mean the application was never submitted. The default optional relationship retains these applications with blank date/channel fields; an inner join deliberately selects only known submissions.

Available fields are:

- **Submitted on**: the actual submission's effective calendar date in its retained timezone. A submission at 11:30 p.m. in Los Angeles belongs to that local day, even when its UTC date is the next day.
- **Submission timezone**: the original timezone or offset, making that calendar-date interpretation visible.
- **Submission recorded on**: the journal's separate UTC recording date.
- **Submission channel**: staff direct entry or an application form. This does not identify the submitting actor or expose form answers.

The intended entry term remains separate. Later acceptance/rejection, correction, matriculation, retries or catalog changes do not alter the original submission date. Date MIN/MAX and grouped application counts use the same typed query engine. All fields require current admissions data authority plus the relevant report duty; adding student-linked fields still requires student-read authority. Sharing, static captures, expiry and private CSV pages retain their existing checks. A report grants no permission to accept or matriculate anyone.

### Storage and alpha migration

**`sis_application_intake`** is a reconstructible read model keyed by the application's existing ULID, not another event store. It contains one authenticated opening-event reference, original effective/UTC recording timestamps, queryable calendar dates, timezone and channel. It contains no names, answers, actor identifiers or decision narratives. The private event reference and full timestamps are not Report Studio fields. Submission and recording date indexes support range filters without decrypting or replaying streams on report loads.

New form and direct-entry commands maintain this row in the same transaction as the submission event, application, answers, receipts and audits. A required audit or projection failure rolls everything back. Repeating projection with identical facts is harmless; conflicting facts are rejected rather than silently overwritten. There is no editable submission-date form.

Migration **`20261114000003_CreateSisApplicationIntake`** creates the portable schema and reconstructs existing rows only from authenticated **AdmissionApplicationWasSubmitted** opening events. It validates that first event through the existing store and aggregate contract; this is not a full-stream health scan. An older observed application remains unknown, including when it has a later acceptance or matriculation event. No date is guessed from a ULID, current status, entry term, decision date or form artifact. Events, receipts and application/academic rows are unchanged.

This alpha conversion is explicitly bounded to **1,000 admissions streams and a 30-second work budget**, with each opening-event read limited to one event. Missing keys, invalid evidence, orphan/conflicting projections or exceeded bounds stop the migration. Data reconstruction is one transaction; a failure rolls those writes back. Engines with nontransactional DDL may retain the newly created empty table; rerunning the migration verifies/reuses it. Larger installations need a controlled offline conversion before this migration can be completed. General operational projection rebuild/retention tooling remains roadmap work, rather than adding another recovery screen for this one index.

Fictional checks cover local/UTC midnight boundaries, retries after decisions/matriculation, form/direct atomic rollback, unknown older history, authenticated reconstruction and rejected corruption, date-range index use, typed grouped dates, source permissions, metadata minimization, saved browser date controls and five supported schema/SELECT dialects. SQLite runs the integration tests; the configured MariaDB database receives a bounded additive migration with journal/application/receipt hashes checked before and after. Other engines still require runtime deployment acceptance.


## Corrections and resubmission

Open an application's **Application answers** panel in Admissions or through **View answers** in the applicant portal. It shows the current answers using the original submitted form. **Answer version** also offers the original submission and each retained resubmission. Replacing or disabling the institution's active form does not change the form used by this application.

### Institutional policy and staff requests

Open **Admissions → Policies → Application revisions**. Choose either:

- **Only after staff request corrections** — the default. Applicants can read their answers but must wait for admissions to open a correction request before revising them.
- **Anytime before an admissions decision** — applicants may revise a submitted application without first receiving a staff request.

The setting has its own expected revision; saving it preserves offer and withdrawal settings and any unsaved choices in those editors. Changes are audited CRUD, not academic events. Changing it invalidates an unconfirmed answer review. An existing open staff request remains usable under either policy.

Staff need `sis:admissions:manage` to review answers and the independent protected `sis:admissions:amend` permission to request corrections, close a request or assist with resubmission. The migration does not grant that additional authority to ordinary admin/admissions roles automatically. Super administrators retain their existing authority. Applicants use exact account ownership, without admissions permissions; staff authority never overrides ownership on a portal endpoint.

For a submitted application, staff choose **Request corrections**, enter the instructions the applicant will see, review them, then **Send request**. The existing private admissions inbox receives an application reference; no automatic email is sent. The application remains submitted, but admissions cannot accept or reject it while corrections are pending. Staff may **Close request** with a reason if the corrections are no longer needed. Closing it preserves every answer set.

### Revise and submit

The applicant chooses **Revise answers**. The same accessible form renderer restores text, choices, confirmations and numbers, with the original containers, columns, conditions and validation. The applicant supplies a reason, chooses **Review changes**, checks the revised answers, then **Resubmit answers**. **Edit changes** returns to the populated form. Staff-assisted resubmission uses this same editor and is explicitly attributed to staff.

A resubmission retains a separate protected answer artifact, closes its correction request when present, advances the application version and appends one `ApplicationAnswersWereResubmitted` event in the existing application stream. It leaves the original answers, original intake/submission date, person, program, planned term and student records unchanged. It does not issue a fresh application or admissions outcome. Admissions reviews the current answers next. Acceptance/rejection and outcome-correction confirmations explicitly show the latest revised answers when present; changing them after a review requires a new decision review.

Accepted, rejected, withdrawn and matriculated applications are closed to answer revisions. Pending applicant withdrawal also pauses correction requests and resubmission. This feature does not silently reopen an earlier outcome. Existing formal withdrawal/return, deferment and institutional student/program workflows remain separate. There is a bounded limit of twenty retained online resubmissions per application; further corrections require institutional review rather than unbounded online replay.

### History, ownership and storage

`sis_application_revisions` combines ordinary operational correction requests with the protected answer artifact produced when a request is fulfilled. A submission permitted without a request creates its own completed row. Unfulfilled requests/cancellations are audited CRUD and append no domain events. Submitted artifacts are immutable through the repository. The single journal binds each submitted artifact's identity and canonical digest, original submission/form, form-definition digest, account/channel, retained revision policy and actual submission time. Events contain references, not duplicated answers.

The original opening event and every earlier event remain intact. The application aggregate verifies the version sequence and original form lineage; selected reads, decisions and matriculation verify retained artifact content against journal proofs. Re-encryption and JSON object key order do not change the canonical digest. Changed answers, reasons, request evidence or missing artifacts stop verification rather than silently replacing earlier facts. Artifacts and original forms require the institution's protected backup/key/retention controls; a digest cannot reconstruct lost answer text. This stage provides no automatic artifact purge or separate recovery workflow.

A request belongs to the person account addressed at creation. A replacement account does not inherit its private instructions or permission to fulfill it; staff must close that request and issue fresh instructions. Retained revision views also enforce their account scope. If the current revised answer set belongs to the former account, staff privately review it and assist the first submission for the replacement account; the replacement account cannot open that earlier private revision. Earlier applications without a genuine recorded submission keep read-only access to their verified existing artifact, with unknown original intake/history left explicit; this workflow does not invent a birth event to make them editable.

Browser mutations retain framework CSRF protection, private/no-store responses and debug suppression. Reviews expire after twenty minutes and bind the actor, owner/staff channel, application/head, person ownership, current policy and operational correction/withdrawal counters. A completed confirmation returns its original result even after later revisions, decisions, request cycles or matriculation. Current authority still applies. A stale unconfirmed review cannot overwrite another answer set or become valid again after a request was opened and closed. Artifacts, formal resubmission, application projection, required audit and private inbox reference commit atomically.

Apply migration `20261117000009_CreateSisApplicationRevisions`. It adds the protected revision/request table, operational application counter, independent institutional policy fields and protected permission. It preserves earlier settings and recorded facts, supports bounded rerun after additive setup, and introduces no parallel event journal. Compiler checks cover the framework's five database dialects; SQLite and the configured MariaDB receive runtime checks. Other databases still require deployment acceptance.

Fictional tests cover ownership/staff permissions, both policies, original/retained answers, replacement/disabled forms, unknown earlier history, pending/stale decision protection, expiry/policy changes, request cycles, immutable receipts after later matriculation, tampered artifacts, audit/authority rollback, schema portability and the real CSRF/private HTTP pipeline. Browser-free JavaScript checks exercise restoration of typed/conditional answers, shared editing/review, versions, retained inputs and confirmation retry. Browser visual/accessibility acceptance remains outstanding; workstation-safe verification does not start Chromium or an antivirus scanner.
