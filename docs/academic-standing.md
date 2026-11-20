# Academic standing

Academic standing is an institution's determination for a dated period under one student-program pursuit. Institutions define their own labels: etSIS does not assume that an unreviewed student is in good standing. This workflow brings the decision into the same program workspace as degree progress without creating competing editable GPA or enrollment-load totals.

## Setup and registrar review

In Access management, grant `sis:standing:configure` to the office responsible for the institution's standing definitions. Grant `sis:standing:manage` and `sis:student:read` to staff who record student determinations. Standing history additionally requires `sis:audit:read`. These independent system permissions are protected; the migration does not change any role/user grants. The existing `super` role retains full access. Open **Students → Standing definitions** to maintain the library; the Students workspace also requires student-read access.

1. Add the institution's code and name. For example, a fictional institution could use `GOOD` / Good standing and `REVIEW` / Academic review. These are examples, not seeded policy. Retire a definition when it should no longer be available for new determinations.
2. Open a student, then **Student programs → More → Academic standing**. Changes belong to the current primary, active pursuit. Historical pursuits remain available for authorized review.
3. Add a period, choose a standing, and enter its effective start and optional end. Dates are inclusive and periods cannot overlap. If one period ends August 9, the next can begin August 10. Keep earlier periods when recording the next determination.
4. Optionally record a policy reference, such as the institution's handbook edition and section. Enter the reason for the decision. The institution remains responsible for the underlying assessment; the reference is evidence supplied by staff, not an executable or automatically verified policy.
5. Use **Degree progress** to inspect the existing verified academic review, then return to the standing draft. Its values and reason remain in memory. Staff do not re-enter GPA or earned-credit totals here.
6. Review the complete before/after periods and confirm. A changed draft, changed source, retired/renamed definition or expired twenty-minute review requires another review. Removing a period is an explicit reasoned correction; its earlier accepted facts remain in protected history.

Each pursuit supports up to fifty periods. A retired/renamed definition does not relabel accepted periods. Existing periods can be kept or shortened with their retained labels; extending or replacing them requires choosing a current active definition. Correcting a published label in the library alone never changes a student's standing.

## Student and advisor views

Students see **Academic standing** beside the selected pursuit's degree progress in **My campus → Degree progress**. The presentation shows the accepted label and inclusive dates. Earlier/future periods remain available through **Other recorded periods**. Closed pursuits are explicitly identified as historical. No period for the selected date means no determination is recorded; it does not mean good standing, probation or suspension.

Current assigned primary/additional advisors see the same minimal standing presentation in **My campus → Advising** when opening an advisee's program and degree progress. This uses the existing dated advisor/pursuit gate. It does not grant general student-read or editing permissions. Assignment expiry/removal, loss of advisor eligibility, disabled accounts or pursuit changes remove that scoped access. Student endpoints remain strictly owner-only, including when called by a staff account. Staff reasons and policy references are withheld from both student and advisor responses. Advisor reads record metadata-only access evidence.

A program change keeps the former pursuit's standing under its original identity. It does not copy a determination into the new pursuit. Closing a pursuit does not erase its recorded periods or turn them into a withdrawal from the institution.

## Reporting

In Report Studio, choose **Academic standing periods**. Report access requires `sis:reporting:run`, `sis:student:read` and `sis:standing:manage`. Authorized report authors can select retained standing names/codes, dates, current student names/IDs and the relevant pursuit/program. The ordinary report configuration, private sharing, live/static captures and expiry controls apply.

The source has **one row per currently recorded period**, not one row per student. For a determination effective on August 10, filter **Effective from** on or before that date and **Effective through** either empty or on/after it. For headcounts use a distinct student count; several periods or joined registrations must not inflate a count. Pursuit status can distinguish current from closed programs. Person/program labels are current labels; standing labels are retained from the accepted determination.

This source reads bounded projections and does not decrypt or replay event history. It excludes staff reasons, policy references and raw events. Effective-date filtering describes the currently accepted periods; it is not a reconstruction of what staff knew before a later correction.

## Historical and security boundary

`StudentStandingWasRecorded` decisions use one `StudentStanding` aggregate per pursuit in the existing `student_standing` stream type. The same authoritative `sis_history_events` journal protects actor, reason, confirmation time, correlation, exact dated periods, accepted definition identity/labels, prior operation and before-state fingerprint. Effective periods are explicit event facts; the event context records the confirmation time. Unknown earlier standing remains unknown. Migration creates no historical decisions.

`sis_student_standing` is the reconstructible current projection. `sis_standing_definitions` is an audited, revisioned CRUD library of labels; it is not an academic rule engine. Commands use student/definition locks, expected versions, expiring actor/instruction-bound reviews, bounded replay, required audit and one atomic transaction. Completion-time source, authority, definition, expiry and projection checks roll back failed decisions. Repeating an accepted confirmation returns its original receipt even after later changes or closure, without replacing later facts. Current private reads compare the protected self-contained stream head with its projection; ordinary report/search pages remain bounded projection reads.

Browser mutations keep framework CSRF; responses remain private/no-store with debug collectors suppressed. Drafts/proofs stay in page memory. Delayed responses cannot replace a later pursuit selection. Migration `20261120000008_CreateSisStudentStanding` adds ULID-based reference/projection tables and independent permissions without rewriting the existing journal. Schema compilation covers the five framework dialects; live multi-engine and institutional acceptance remain separate qualification work.

The migration was applied to the configured DDEV application database on November 20, 2026. All twenty recorded events remained intact; before/after checksums matched for the journal, stream heads and operation receipts. Existing role/user permission-grant counts stayed unchanged. The new tables start empty, with no invented standing or default institutional labels. Focused verification passed 66 PHP tests, nine browser scenarios, real-provider HTTP/CSRF/private-response checks and coding standards for the changed code. Browser scenarios ran with one worker and fictional records.

## Remaining policy work

This completes reviewed manual standing, its setup, private display and reporting. Automated proposals based on institutional standing rules, versioned GPA/load thresholds, dated load/classification determinations, formal transcript standing annotations, graduation decisions and closed-pursuit standing corrections remain separate work. Standing itself does not change student activity, account access, registration holds, grades, earned credits, enrollment, charges or graduation. Institutions should not rely on this label alone for automated financial-aid, immigration or other eligibility decisions.
