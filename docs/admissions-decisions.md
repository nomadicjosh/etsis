# Admissions acceptance and rejection

Implemented 2026-11-09. Acceptance and rejection now use reviewed, attributed decisions in the existing protected event journal. They precede the separate [initial matriculation workflow](student-matriculation.md). This stage tracks actual decisions made from now on; it does not reconstruct earlier decisions or original submission dates.

## Staff workflow

Open **Admissions → an application** and review the submitted answers and program. For a submitted application, choose **Accept application** or **Reject application**, enter a reason and select **Review admissions decision**.

The review shows the person and current institutional ID, proposed outcome, program, entry term and original submitted form name/revision, when attached. A directly entered application is identified explicitly. [Withdrawal and return to review](#withdraw-an-application-or-return-it-to-review) are separate pre-matriculation actions. **Revise admissions decision** preserves the reason. Reviewing creates no decision event and changes no status.

Choose **Confirm acceptance** or **Confirm rejection**. The application status/version, observation, actual decision events, operation receipts and required audit commit together. A failure rolls everything back. Acceptance does not create a student, assign a cohort or charge tuition; **Matriculate student** remains a separate reviewed action. Rejection creates no student record.

Only a currently submitted application can receive this decision. Accepted/rejected applications now have a separate [reviewed outcome correction](admissions-corrections.md) requiring independent authority. Original outcomes are retained. Matriculated applications and reopening for edits remain outside that correction action.

Reviews expire after 20 minutes and must be repeated after the institutional date changes. Changes to the application/version, person identity, program/term labels or attached submission context also require another review. Reviews are authenticated encrypted tokens bound to actor, application, purpose, chosen outcome and context; they stay in browser memory.

## Select document evidence

Acceptance, rejection and pre-matriculation correction share **Choose evidence** beside the reason. It opens an on-demand, labeled checklist of application files. Select up to ten available, unexpired files that informed this decision; use **Download** to read a file privately. Quarantined, rejected, expired and removal-pending files cannot support a new decision. A clean safety scan does not verify a credential's authenticity. Selection uses the existing admissions decision authority and grants no general retention or applicant-sharing powers. Files are optional here; no institution-specific completeness or approval policy is inferred.

The confirmation displays the selected filenames before its final button. **Revise** preserves the reason and selection. If file access, holds, deadline or scan state changed, clear and reselect the file to review its current controls. The server checks current revisions, parent application/person, protected scan state and actual encrypted file integrity at both review and confirmation. Review changes no file, application, event or hold. Editing is disabled while the review request finishes; a detached form cannot reopen itself with a late response.

Confirmation retains each selected file's identity, reviewed revision, filename, size, content digest and scan time inside authenticated encrypted metadata on the existing decision event. The original bytes remain in private encrypted storage; they are not copied into event payloads or jobs. A small protected marker on the existing file row is updated in the same transaction as the outcome, event, receipt and required audit. No new table, event type, permission grant, migration or historical conversion is introduced.

**Application history** shows filenames for each recorded decision/correction, without hashes, reasons, raw envelopes or applicant access changes. Earlier events without this metadata show **Earlier document evidence was not recorded**; new decisions selecting no files explicitly show **No uploaded files were selected for this decision**. Never infer evidence for an earlier outcome. Corrections select their own evidence and retain the original outcome's references, even when they select different files or none.

Selected evidence is protected from ordinary expired-file disposal independently of a manually editable hold. Disposal verifies the application's authoritative decision history, so a missing marker cannot authorize deletion. Rechecking a file can still quarantine it immediately for safety; the original decision's file digest/scan facts remain unchanged. Evidence selection does not share a staff-only file with its applicant. Ordinary portal responses expose no decision-evidence references. Unconfirmed reviews from before this update must be repeated; completed earlier confirmation tokens still return their recorded receipt. Completed confirmation retries return their original outcome after later decisions, file-control changes or expiry; they do not retain another marker or recreate a file.

Each decision verification/disposal history read is bounded to 500 events in 25 pages. Ordinary document inventories remain bounded projection reads and do not replay decision history. An over-limit or inconsistent history requires institutional review; it never permits partial evidence retention or disposal. Operational reconstruction can derive markers from genuine decision metadata; general rebuild tooling remains outstanding.

Case/person-wide [document holds](admissions-workbench.md#case-and-person-document-holds) now complement independent file holds and recorded decision-evidence protection. Institutional retention categories, approved formal-evidence disposition, backups and actual scanner deployment acceptance remain production work. This safeguard does not declare a universal retention period or establish legal compliance. Uploaded documents do not automatically satisfy checklists or become official transcripts/transfer awards.

## Withdraw an application or return it to review

This closes an **application**, not the person's campus account, an existing student record, employment or a student-program pursuit. It is available only before that application is matriculated. A person who is already a student can still have a separate application withdrawn; their existing student history stays unchanged.

In **Admissions → application → More**, choose **Withdraw application**. This works for submitted, accepted or rejected applications. Enter a reason and choose **Review change**. The confirmation identifies the applicant, program, term, previous/new status and institution date. **Revise change** preserves the reason. **Confirm withdrawal** closes current review; it does not delete the application, answers, working notes, documents or original outcomes. Unfinished casework, documents and educational-background edits must be saved or cleared before changing tasks.

A withdrawn application offers **Return to review**. Use the same reason → review → confirmation flow. It returns to **Submitted** for a fresh admissions decision. It does not restore an earlier acceptance, open the original submitted answers for editing, create another person/application, or enroll a student. If an earlier matriculation review was prepared before withdrawal, a later acceptance still requires a new matriculation review.

Withdrawn applications are paused in the follow-up queue. No checklist, assignment, due date or working status is reset; unfinished work appears again when the application returns to review. Staff can still find withdrawn applications in Admissions/the person record, read their authorized history and manage retained documents. The portal shows **Withdrawn** with an instruction to contact admissions. Report Studio offers the same status as a filter and dashboard counts include it. Applicants do not receive the staff reason or a self-service withdrawal/reopening action.

Apply migration **20261117000005**. It registers the protected **`sis:admissions:withdraw`** permission and its self-implication only, without changing application rows or granting roles/users. Both this permission and `sis:admissions:manage` are required for review and confirmation. Delegate deliberately through Roles & access; an ordinary admin's broad SIS grant does not include it. Super keeps its existing unrestricted authority. The migration uses the existing portable SQL/transaction abstraction and adds no table or column; rollback cannot delete a protected system permission.

**AdmissionReviewStateWasChanged** records the actual previous/new status, preceding recorded decision identity, resulting application version and retained program/term/submitted-form context in the same protected admissions stream. Actor, reason and effective/recorded times are protected. An untracked older submitted/accepted/rejected application gets an explicit current observation at confirmation; no original submission or decision date is invented. An untracked withdrawn row is rejected as inconsistent rather than silently treated as recorded history.

Review changes no facts. The actor-bound encrypted review expires after twenty minutes and across institution-date changes; changed identity, application/history version or source context requires another review. Current authority is checked after waiting and before completion. Permission, person, application and catalog-context locks follow a consistent order. Projection CAS, event, stream head, retry receipt and required audit commit together. A competing decision or matriculation invalidates a pending withdrawal, and a withdrawn application cannot be matriculated.

Exact completed confirmations return their original receipt after later withdrawal/review/acceptance cycles or matriculation, without reapplying that earlier action. Earlier decisions, submitted answers and selected evidence stay retained; ordinary file disposal still verifies all recorded evidence references. New withdrawals and returns after matriculation are blocked. Application history distinguishes **Application withdrawn** and **Returned to review** from acceptance/rejection and shows their actual recording/effective times without exposing reasons or raw events.

Offers, applicant responses, applicant-initiated withdrawal policy, editing/resubmission and post-matriculation rescission remain separate work. Do not use this action for a student's course drop, institutional withdrawal or registrar program change.

## Historical facts and retries

Newly [submitted applications](application-submission-history.md) now start with their actual creation event. For an earlier application without that event, the first decision confirmation records **AdmissionApplicationWasObserved** for the submitted application as seen at confirmation. Its person/program/entry-term identities, status and version are retained, with earlier history explicitly unknown. This is an observation, not an invented original submission event or date.

**AdmissionWasDecided** then records acceptance or rejection, the preceding recorded decision identity, resulting application version and retained decision context. Protected context carries the trusted actor, reason, operation correlation and effective/recording times. The source retains program/term labels and, where available, the immutable submitted form reference/revision and existing submission fingerprint. It does not duplicate person names, institutional IDs or application answers into the event payload.

**Application history** shows a paged list of original outcomes and corrections, effective/recording times, retained labels and form revision, plus today's application status. Reasons, actor details, submission fingerprints, answers and raw event envelopes are excluded. Today's catalog renames or replacing the active application form do not rewrite these retained facts. Answers remain in their existing protected submission record.

Resending the same completed confirmation returns its original outcome and version. For example, confirming acceptance, matriculating the student and then resending that acceptance returns the earlier acceptance result while the application remains matriculated. It creates no duplicate events or student and does not undo later student/program changes. Another review cannot overwrite the winning decision; permission is still required for an exact retry.

Existing accepted/rejected applications remain earlier records with unknown decision history. Reads, previews and matriculation do not invent earlier acceptance/rejection events. Matriculation now verifies any recorded acceptance and identifies whether that preceding decision is known. Its earlier immutable creation events are unchanged.

## Authorization, consistency and storage

Review, confirmation and curated history require **`sis:admissions:manage`**. Person ownership, student-read authority and registrar permissions alone do not grant these admissions actions. Permission and actor identity are rechecked after waiting for locks. Browser writes retain CSRF protection; responses remain private/no-store with debug collectors suppressed.

Decision commands lock the person and application, then the relevant term/program for a new decision. Form submissions now lock the person before the program and recheck ownership/actor identity after waiting; direct application creation also locks/rechecks its person. This keeps these workflows' lock order consistent without changing submission semantics.

Protected replay verifies tracked application identities, status and version. A tracked acceptance can advance to matriculated only with a matching recorded matriculation. If saved application fields conflict with recorded decisions, staff application review, decision history and matriculation stop and report an error; opening a screen does not silently repair either copy.

Both new event schemas use **`sis_history_events`** and its existing stream heads and atomic operation receipts. The application row's status/version supplies the projection check. No additional event/checkpoint table, migration, conversion loop or backfill is required. Existing events and matriculation schemas remain unchanged. Required historical storage migrations must already be installed.

Fictional integration checks cover both outcomes, replay, submitted-form references, protected payloads, stale/expired reviews, permission and owner rechecks, complete rollback, competing confirmations, changed projections and retries after matriculation. Real-provider/router checks cover CSRF, private responses and admissions authorization. Browser checks cover revision, confirmation, history, focus, accessibility and narrow screens. No working database was migrated or used for these fixtures.

New submissions and deliberately selected document evidence now retain their actual history. Private offers, applicant responses and their institutional acceptance policy now share this application history. Approved evidence disposition and operational rebuild tooling remain subsequent stages. Event history supports evidence; it does not establish legal compliance by itself.

## Offers after an admissions acceptance

The [offer workflow](admissions-offers.md) separates the institution’s acceptance decision from the applicant’s response to a private letter. Institutional policy requires applicant acceptance by default. Staff can configure that policy, issue or replace a letter and record documented responses in the same application history. Matriculation checks the retained offer policy; cancelling an offer does not withdraw an application or close a student record.

## Applicant withdrawal

This workflow withdraws an **application before matriculation**. It is separate from institutional withdrawal, academic leave, a program change or ending employment. It never closes the person, sign-in account, existing student record or program pursuits.

### Institutional setup

Open **Admissions → Policies**. The shared screen contains offer acceptance and applicant withdrawal settings, with independent revisions so saving one does not discard or invalidate unrelated work in the other.

Choose one applicant withdrawal policy:

- **Staff approval required** is the default. An applicant submits a reason and confirms a request. Their application remains open while admissions reviews it.
- **Applicants may withdraw directly** permits the current owner to review and confirm withdrawal of a submitted or accepted application.
- **Staff-only withdrawal** directs applicants to contact admissions. It prevents new owner requests and direct withdrawals. Owners can still cancel their own existing pending requests.

Reading setup requires `sis:admissions:manage`; changing it also requires the protected `sis:admissions:policy` permission. The migration consolidates the earlier offer setting into `sis_admission_policy`, verifies its value and revision before removing the superseded configuration table, and defaults only the new withdrawal setting to staff approval. Issued offer wording and retained offer policies remain unchanged.

### Applicant and staff flow

Applicants open **Withdrawal** beside their portal application. Under the default policy, they choose **Request withdrawal**, enter a reason, review it and confirm. They may cancel a pending request through the same review/confirmation flow. A pending request pauses matriculation, new offers and offer responses until it is resolved; it does not itself change the admissions decision. Staff may still cancel an offer.

Staff open **Admissions → Withdrawal requests**. This paged worklist shows applicant names and application status without loading private reasons or replaying event streams. **Review application** opens the application with its withdrawal panel expanded. Staff can **Approve withdrawal** or **Close request** after entering the response the applicant will see, reviewing the proposed action and confirming it. Approval records the formal application withdrawal. Closing the request leaves the application's current status unchanged. The latter is also available when another legitimate action has already closed or matriculated an older application.

Approval and closure require both `sis:admissions:manage` and the independent `sis:admissions:withdraw` permission. A direct staff withdrawal cannot bypass a pending applicant request; resolve the request first. Returning a withdrawn application to review remains a separate staff action that requires a new admissions decision.

Applicants see the response in their request panel and receive a reference in the existing private admissions inbox when staff resolve their request. No email is sent. Direct withdrawal updates their visible application status after confirmation. A person/account relink does not transfer earlier private request wording or response authority to another account; staff must close the former owner's pending request and obtain fresh instructions from the current owner.

### Working records and formal history

`sis_admission_withdrawal_requests` contains encrypted operational requests, their original wording, one resolution and bounded confirmation receipts. It is an ordinary working-case table, not an additional domain event journal. Its routing/status fields support indexed, bounded worklists; private wording and responses are excluded from those lists. Pending requests and earlier resolved requests remain separate records.

Only an actual approved or direct withdrawal appends the existing `AdmissionReviewStateWasChanged` event in the application's current stream. Protected provenance identifies the applicant/staff channel, request, original requester/time and the policy that permitted the request. An approval retains the original applicant wording and staff response in protected decision context. Earlier decisions, offers, submitted answers and evidence remain intact.

The application's `withdrawal_revision` is an operational concurrency counter. It is not a journal playhead, an academic record version or a claim about earlier requests. It prevents an old unconfirmed review from becoming valid again after a request-and-cancellation cycle. Formal application versions continue to advance only with their recorded application transitions. Working requests and responses follow institutional case retention; this feature does not automatically purge them or erase their independently retained formal withdrawal facts.

Every mutation uses an expiring review bound to the current actor, route, owner/staff channel, application, request state and relevant policy. Confirmation rechecks authority under locks and commits the working record, any formal outcome, audit and inbox reference atomically. Repeating a completed confirmation returns its original result, even after later request resolutions or return to review, without applying the old action again. Current authority still applies. A new or changed request requires a new review.

Apply migration `20261117000007_CreateSisAdmissionWithdrawalRequests`. Fictional checks cover both policies and owner/staff boundaries, unchanged person/student facts, cancellation cycles, stale/expired reviews, policy isolation, required-audit rollback, request encryption, existing offer behavior, original retries, and the real private/CSRF HTTP pipeline. Browser regression coverage is prepared separately; it is not run during workstation-safe checks.


## Defer an accepted application

Deferment changes the planned intake of an accepted application before matriculation. It keeps the same application under the same person, retains the original submitted intake and answers, and records a reasoned before/after approval. It does not withdraw the applicant from the institution, create another person or student, or assign a cohort early.

Open **Admissions → application → Entry term**:

1. Choose **Choose later intake**, search by term code or name, and select an intake. Choices are paged and must belong to an academic year. The new intake must start later than the earlier approved intake and must not have started.
2. Enter the reason, choose **Review intake**, check the person and both term/date/year summaries, then **Approve intake**.
3. Open **Admission offer** and issue a fresh letter. Earlier letters remain in history but cannot authorize the new intake. The new letter uses the institution's existing offer policy: acceptance is required when that policy requires it, and optional otherwise. A fresh letter is required after deferment even where ordinary initial matriculation does not require one.
4. Matriculate through the usual reviewed workflow. The new entry term determines the academic-year/cohort proposal and the program requirements available at that time. The original submitted intake remains a separate reporting fact.

Staff need both `sis:admissions:manage` and the independent protected `sis:admissions:defer` permission. The migration grants neither regular administrators nor admissions roles this additional authority automatically; a super administrator can assign it deliberately. Resolve a pending applicant withdrawal request before approving deferment. Applicants contact admissions to request a different intake; this stage does not add a second applicant request queue.

If the calendar dates, code, name or academic-year context change after an intake approval, the application shows **Review current intake**. Staff review the changed definition, supply a reason and approve it, then issue a fresh offer. Offer responses and matriculation are paused until this is resolved. Earlier approvals retain their exact term and year context; a current calendar edit never rewrites them.

### Retained facts and confirmation safety

`AdmissionEntryTermWasApproved` appends to the application's existing `admission_decision` stream in the single protected journal. It retains the previous approval reference, application revision, original form reference and both term/year snapshots. Attribution, reason and effective time use the existing protected event context. Deferment keeps the application accepted; it advances the application revision and current offer context instead of adding a misleading second acceptance or a separate deferred status.

The original form's answers and fingerprint remain tied to its submitted intake. Staff and the applicant see that original intake beside retained answers, while their application list shows the planned intake. Reports can select **Submitted intake** separately from the current entry-term relation. Earlier applications without a genuine submission event remain explicitly unknown; the migration does not infer a historical submission from current rows.

Reviews expire after 20 minutes and bind the actor, person, application, selected calendar definitions, original answer artifact and withdrawal-request revision. Confirmation rechecks authority under locks and commits the current projection, event, required audit and private inbox reference together. A completed confirmation returns its original result on retry, including after later deferment or matriculation, without moving the person back to an earlier intake. Current authority is still required. An unconfirmed old review cannot overtake a later action, even after a withdrawal request was created and cancelled.

Apply migration `20261117000008_CreateSisAdmissionDeferral`. It adds nullable original-intake fields to the existing submission index and the independent permission. The bounded existing projection upgrader fills those fields only from authenticated opening submission events, verifies existing indexed facts, and leaves unknown origins blank. It never changes recorded journal events. Fresh installations create the current index shape directly.

Fictional tests cover original answers and intake, new offer/acceptance policy, new matriculation cohort, calendar reapproval, permission and actor boundaries, competing/expired confirmations, withdrawal-request cycles, audit/authority rollback, exact retries after later actions, bounded search, authentic index reconstruction, unknown earlier decisions, and the actual CSRF/private HTTP pipeline. JavaScript checks cover term selection, review/revise, preserved input, retry tokens, pending requests and detached responses. Browser accessibility/layout verification remains a separate acceptance check; no browser is started by the workstation-safe checks.

[Application answer corrections and resubmission](application-submission-history.md#corrections-and-resubmission) now preserve separate answer versions under the same application. Open correction requests pause decisions; acceptance/rejection and outcome-correction previews show the current revised answers when present.


## Checklist policy before acceptance

Open **Admissions → Policies → Acceptance checklist**. Check **Require checklist completion before acceptance** to enforce the application's needed checklist items. It is **off by default**, leaving checklists advisory. Saving requires both `sis:admissions:manage` and the existing protected `sis:admissions:policy` authority. The setting has an independent revision; saving it preserves offer, withdrawal and answer-revision policies and their unsaved screen choices.

The gate uses the application's saved **Checklist & follow-up**, including items copied from reusable global/program presets and any authorized case-specific changes. A reusable preset is a library entry, not an automatically imposed requirement on every application. Staff continue to determine which items apply to a particular case under their existing admissions authority. There is no additional academic-waiver or exception role in this workflow.

When checklist completion is required:

- Needed items marked **Pending** or **Received** block acceptance. Received means the material still needs review.
- **Complete** and **Not needed** resolve a needed item. Not needed is the staff determination that this working checklist item does not apply; it is not a course waiver or academic-credit decision.
- Optional items do not block acceptance. Casework may remain Open or Waiting for separate follow-up when its needed items are resolved.
- A missing checklist does not silently count as complete. If no items are needed, review the case and save **Casework status → Done** to record that determination. If follow-up must remain open, a completed needed application-review item can record the review without closing the working case.
- Rejection remains available. A rejected-to-accepted outcome correction must satisfy the current checklist policy, just like a first acceptance.

Open an application, expand **Checklist & follow-up**, resolve its needed items and **Save casework**. The saved-readiness summary lists remaining items and distinguishes required/advisory policy. **Casework status** is alongside the checklist, while routing and private notes remain in their existing panel. Return to the application's acceptance action after saving; its confirmation displays the exact saved checklist summary used for that review. Under advisory policy, staff see unresolved items but may still decide.

This is a working-case quality gate. Admissions staff can edit applicability, labels and progress under existing audited casework authority; it is not a separately published institutional curriculum or immutable prerequisite catalog. The formal admissions decision retains what was actually reviewed. Adding a working task never changes an earlier acceptance.

### Retained checklist evidence

New acceptance/rejection and outcome-correction events retain `admission_checklist` metadata in the existing protected application stream. It records the effective setting/revision, saved case revision/status, and the needed item references, labels, progress and applicant visibility seen for that decision. Private notes, instructions, routing and assignee details are excluded. The usual trusted decision actor, reason and effective/recording time remain in protected context. Working checklist saves and configuration changes stay audited CRUD; there is no new event type or journal table.

The application aggregate and shared store validate this frozen evidence. A required checklist cannot claim an accepted outcome while needed items remain unresolved. Ordinary history pages show retained counts and the recorded required/advisory setting, without item names or private task details. Missing evidence on an earlier event remains explicitly unknown; the migration does not infer its checklist from today's casework.

Reviews bind a compact digest of the saved checklist and policy. A changed case revision or policy revision requires a fresh review, including an advisory → required → advisory cycle. Confirmation reads current case/policy state under the application's existing lock order and records the event, projection, receipt and required audit atomically. The review token contains the digest rather than another copy of checklist labels.

An already completed confirmation returns its original result despite later checklist/policy edits or matriculation. Earlier accepted decisions remain effective; enabling this setting does not retroactively revoke an acceptance, offer or matriculation. Pending reviews from before checklist capture was added need a new review, while previously completed receipts remain readable with current authority.

### Applicant guidance and setup

Applicants' **Next steps** explain whether they should finish visible requested items or wait for admissions review. Hidden staff tasks, their names/counts, private notes, assignee identities and policy/case revisions stay private. Guidance is shown while an application is submitted; later policy changes do not present an already accepted or matriculated applicant as needing a new admissions decision. Checklist completion itself is never presented as an admission approval. A casework save that changes this public readiness message coalesces an update in the existing private admissions inbox; other private-only edits remain silent. Changing institution-wide policy does not fan out a notification to every applicant.

Apply migration **`20261117000010_AddSisAdmissionChecklistPolicy`**. It adds only `require_checklist` and `checklist_revision` to the existing institutional policy row, defaulting to advisory/revision zero. It changes no existing decisions, casework, answers, grants or other policy values, and is safe to rerun after partial additive DDL. Compiler checks cover the framework's five database dialects; SQLite and the configured MariaDB receive runtime checks.

Fictional checks cover required/advisory behavior, received-versus-complete states, explicit empty-case review, optional/not-needed items, first and corrected acceptance, immutable summaries, stale policy/case cycles, exact earlier receipts, independent setting authority, atomic rollback, owner privacy and the real Codefy CSRF/private HTTP pipeline. Browser visual/accessibility acceptance remains outstanding.

For a focused low-memory HTTP check, run the configured interpreter against `tests/Support/ConsoleStatusSmoke.php check-admission-readiness`. It uses a fresh fictional database and the shared admissions HTTP fixture, with no browser, scanner, mailbox or institutional connection. The shared request-body reader now reads bounded 8 KiB chunks instead of preallocating its full 2/6 MiB allowance on small requests. Exact JSON/form size limits and form stream positions are unchanged; boundary tests cover acceptance and rejection at those limits.
