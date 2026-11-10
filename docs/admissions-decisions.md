# Admissions acceptance and rejection

Implemented 2026-11-09. Acceptance and rejection now use reviewed, attributed decisions in the existing protected event journal. They precede the separate [initial matriculation workflow](student-matriculation.md). This stage tracks actual decisions made from now on; it does not reconstruct earlier decisions or original submission dates.

## Staff workflow

Open **Admissions → an application** and review the submitted answers and program. For a submitted application, choose **Accept application** or **Reject application**, enter a reason and select **Review admissions decision**.

The review shows the person and current institutional ID, proposed outcome, program, entry term and original submitted form name/revision, when attached. A directly entered application is identified explicitly. **Revise admissions decision** preserves the reason. Reviewing creates no decision event and changes no status.

Choose **Confirm acceptance** or **Confirm rejection**. The application status/version, observation, actual decision events, operation receipts and required audit commit together. A failure rolls everything back. Acceptance does not create a student, assign a cohort or charge tuition; **Matriculate student** remains a separate reviewed action. Rejection creates no student record.

Only a currently submitted application can receive this decision. An application already accepted, rejected or matriculated must be reviewed through its history; this stage does not offer reopening or correction by overwriting its status. Those policies and attributed correction workflows remain subsequent work.

Reviews expire after 20 minutes and must be repeated after the institutional date changes. Changes to the application/version, person identity, program/term labels or attached submission context also require another review. Reviews are authenticated encrypted tokens bound to actor, application, purpose, chosen outcome and context; they stay in browser memory.

## Historical facts and retries

The first confirmation records **AdmissionApplicationWasObserved** for the submitted application as seen at confirmation. Its person/program/entry-term identities, status and version are retained, with earlier history explicitly unknown. This is an observation, not an invented original submission event or date.

**AdmissionWasDecided** then records acceptance or rejection, the preceding recorded decision identity, resulting application version and retained decision context. Protected context carries the trusted actor, reason, operation correlation and effective/recording times. The source retains program/term labels and, where available, the immutable submitted form reference/revision and existing submission fingerprint. It does not duplicate names, institutional IDs or application answers into the event payload.

**Admissions decision history** shows the actual outcome, effective/recording times, retained labels and form revision, plus today's application status. Reasons, actor details, submission fingerprints, answers and raw event envelopes are excluded. Today's catalog renames or replacing the active application form do not rewrite these retained facts. Answers remain in their existing protected submission record.

Resending the same completed confirmation returns its original outcome and version. For example, confirming acceptance, matriculating the student and then resending that acceptance returns the earlier acceptance result while the application remains matriculated. It creates no duplicate events or student and does not undo later student/program changes. Another review cannot overwrite the winning decision; permission is still required for an exact retry.

Existing accepted/rejected applications remain earlier records with unknown decision history. Reads, previews and matriculation do not invent earlier acceptance/rejection events. Matriculation now verifies any recorded acceptance and identifies whether that preceding decision is known. Its earlier immutable creation events are unchanged.

## Authorization, consistency and storage

Review, confirmation and curated history require **`sis:admissions:manage`**. Person ownership, student-read authority and registrar permissions alone do not grant these admissions actions. Permission and actor identity are rechecked after waiting for locks. Browser writes retain CSRF protection; responses remain private/no-store with debug collectors suppressed.

Decision commands lock the person and application, then the relevant term/program for a new decision. Form submissions now lock the person before the program and recheck ownership/actor identity after waiting; direct application creation also locks/rechecks its person. This keeps these workflows' lock order consistent without changing submission semantics.

Protected replay verifies tracked application identities, status and version. A tracked acceptance can advance to matriculated only with a matching recorded matriculation. If saved application fields conflict with recorded decisions, staff application review, decision history and matriculation stop and report an error; opening a screen does not silently repair either copy.

Both new event schemas use **`sis_history_events`** and its existing stream heads and atomic operation receipts. The application row's status/version supplies the projection check. No additional event/checkpoint table, migration, conversion loop or backfill is required. Existing events and matriculation schemas remain unchanged. Required historical storage migrations must already be installed.

Fictional integration checks cover both outcomes, replay, submitted-form references, protected payloads, stale/expired reviews, permission and owner rechecks, complete rollback, competing confirmations, changed projections and retries after matriculation. Real-provider/router checks cover CSRF, private responses and admissions authorization. Browser checks cover revision, confirmation, history, focus, accessibility and narrow screens. No working database was migrated or used for these fixtures.

Original submission/offer/document transitions, completeness checklists, institutional approval/correction policies, operational rebuild tooling and the full admissions pipeline remain subsequent stages. Event history supports evidence; it does not establish legal compliance by itself.
