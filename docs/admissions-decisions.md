# Admissions acceptance and rejection

Implemented 2026-11-09. Acceptance and rejection now use reviewed, attributed decisions in the existing protected event journal. They precede the separate [initial matriculation workflow](student-matriculation.md). This stage tracks actual decisions made from now on; it does not reconstruct earlier decisions or original submission dates.

## Staff workflow

Open **Admissions → an application** and review the submitted answers and program. For a submitted application, choose **Accept application** or **Reject application**, enter a reason and select **Review admissions decision**.

The review shows the person and current institutional ID, proposed outcome, program, entry term and original submitted form name/revision, when attached. A directly entered application is identified explicitly. **Revise admissions decision** preserves the reason. Reviewing creates no decision event and changes no status.

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

Institutional retention categories, approved formal-evidence disposition, case/person-wide holds, backups and real scanner/browser acceptance remain production work. This safeguard does not declare a universal retention period or establish legal compliance. Uploaded documents do not automatically satisfy checklists or become official transcripts/transfer awards.

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

New submissions and deliberately selected document evidence now retain their actual history. Offers, applicant responses, institutional decision-gating policies, approved evidence disposition, reopening and operational rebuild tooling remain subsequent stages. Event history supports evidence; it does not establish legal compliance by itself.
