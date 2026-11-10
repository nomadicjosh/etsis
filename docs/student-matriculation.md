# Initial student matriculation

Implemented 2026-11-09. A newly confirmed matriculation now records the student's creation, first program assignment, initial active status and initial primary program together in the existing protected journal. It complements [additional program assignments](student-program-assignments.md), [activity decisions](student-activity-history.md) and [primary-program decisions](student-primary-program-history.md).

## Staff workflow

Open **Admissions → an accepted application → Matriculate student**. Enter a reason and choose **Review matriculation**. The review identifies the person and current institutional ID, program, entry term, derived academic-year cohort and proposed curriculum revision. **Revise matriculation** preserves the entered reason. Reviewing creates no student, assignment, curriculum, cohort binding or event.

Choose **Confirm matriculation** to create one student record connected to the existing person. The application becomes matriculated, its program becomes the student's first active and primary pursuit, and student activity starts on the confirmation date. The original institutional entry term is retained separately from later pursuits' entry terms. This does not create a sign-in account, change account permissions, charge tuition or assign degree-audit requirements.

The cohort comes from the academic year explicitly linked to the entry term. An existing applicable curriculum revision is retained; if the existing cohort workflow must create one, the review says so. An unlinked term is clearly identified and can be confirmed without assigning a cohort; staff must review calendar setup separately. etsis does not infer a cohort from overlapping dates or invent degree requirements. Curriculum and degree-requirement corrections remain their separate reviewed workflows.

A person can have only one student record. Another accepted application for that person cannot create a second student; staff should review existing student programs and the appropriate readmission/additional-program workflow instead. Submitted, rejected and already matriculated applications cannot start a new matriculation review.

Reviews expire after 20 minutes and must be repeated after an institutional date changes or relevant application, person identity, program labels, entry-term/cohort mapping or curriculum proposal changes. Confirmation checks the reviewed context under locks. If it cannot safely apply, the request reports an error and creates no partial student.

After confirmation, **Matriculation history** in the application displays the original program/term labels, cohort and confirmation time. Today's catalog renames and later program changes do not rewrite these facts. Protected reasons, actor details and raw event envelopes are excluded.

## Recorded facts and retries

Four actual creation events share a trusted operation correlation:

- **StudentWasMatriculated** retains the application, person, student, initial assignment, program and entry-term ULIDs and the accepted application version observed at confirmation.
- **StudentProgramWasAssigned** starts the first assignment's existing program-status stream, retaining its labels and the cohort/curriculum actually bound. Closure and reopening continue that stream.
- **StudentActivityWasStarted** begins the student's activity stream with a genuine activation on confirmation. Later effective-dated activity decisions keep their existing rules. A correction dated before this known activation does not silently undo it.
- **PrimaryProgramWasEstablished** begins the primary-program stream with the exact initial pursuit and retained labels. Later primary changes append selections.

The protected context retains the authorized actor, entered reason, operation identity and effective/recording times. Application-form answers remain in their existing protected submission records linked by application; no answers, names or institutional IDs are duplicated into the matriculation event. Acceptance is an observed prerequisite at this stage, **not** a fabricated earlier acceptance event. Submission, acceptance/rejection, offers and the full admissions pipeline still need their own consequential-transition conversion.

Resending the same completed confirmation returns the original student and assignment identities. It does not create another student, reactivate an inactive student, make the first program primary again, reopen it or reassign its cohort. For example, after matriculating into Computing, selecting Arts as primary and closing Computing, retrying the original Computing matriculation leaves those later decisions intact. A different review cannot claim the completed matriculation.

Older matriculated rows remain earlier records with unknown creation history. Opening history or reviewing an older student never invents matriculation, first-program or activation events. Their later activity/primary changes retain the explicit observation behavior documented for older records.

## Authorization and storage

Review, confirmation and curated history require `sis:admissions:manage`. Admissions staff do not need registrar program-management authority merely to perform initial matriculation. Person ownership, student-read access and registrar permission alone do not grant this admissions action. Permissions and actor identity are rechecked after waiting for locks. Browser mutations retain CSRF protection; responses remain private/no-store with debug collection suppressed. Authenticated encrypted review tokens stay in browser memory, bound to actor, application, purpose and reviewed context.

The person lock serializes competing applications for one person; application, term and program locks protect their decision context. The existing unique person-to-student constraint remains a second guard. Student/current-program checks verify tracked projections when resolving an earlier receipt or history, while allowing legitimate later changes.

Application transition, student and assignment rows, any derived curriculum, cohort binding, four journal appends, existing checkpoints/operation receipts and the required audit commit together or all roll back. Events continue using **`sis_history_events`**, with the existing stream heads and receipts. The application status/version and recorded identities supply the matriculation projection check; no additional event table, checkpoint table, backfill or migration is needed. Existing events and older observation schemas are unchanged. Previously required historical/checkpoint migrations must already be installed.

Fictional integration checks cover write-free reviews, cohort derivation, original labels, replay, duplicate applications, stale/expired reviews, actor and permission checks, protected reasons, complete rollback and retries after later activity/program changes. Real-provider/router checks exercise CSRF, private responses and admissions authorization. Browser checks cover review/revision/confirmation/history, keyboard focus, accessibility and narrow screens. These checks use isolated fixtures; no working database was migrated.

Full student reconstruction, operational projection rebuild/swap tooling, backdated matriculation corrections, standing, graduation and complete admissions/hiatus lifecycle history remain subsequent stages. This implementation does not establish legal compliance by itself.
