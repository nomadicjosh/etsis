# Academic advising

Advising belongs to a student's program pursuit. One primary advisor may be assigned for any date, with additional advisors where needed. Assignments use the advisor's existing canonical person and sign-in account; staff, faculty and student capabilities continue to coexist under that same person. Appointments, academic standing, load classifications and degree-audit authoring remain separate workflows.

## Registrar setup

Grant the responsible staff **`sis:advising:manage`** and **`sis:student:read`** through Access management. The new system permission is protected. The upgrade preserves existing role and user grants; an administrator can use a custom office role or an extra user permission. The `super` role retains its existing full access. **Assignment history** additionally requires `sis:audit:read`.

Eligible advisors have an enabled linked sign-in account and either active employment or the faculty role. An ordinary student/prospect account alone is not an advisor candidate. Use the existing person record when adding employment or faculty capability. No advisor assignment grants broad student-read, editing, admissions, financial or registrar authority.

1. Open a student in **Students**, then the appropriate program under **Student programs → More → Academic advisors**.
2. Choose **Add advisor**, search the bounded faculty/staff list, and enter the advisor type and dates. Dates are inclusive: if the first primary ends August 9, the next may start August 10.
3. Keep at most one primary advisor at a time. Different additional advisors may overlap, but the same person cannot overlap themselves. Future assignments are supported.
4. Enter a reason, review the before/after list and confirm. Failed reviews keep the draft available for correction. A review expires after twenty minutes, and changed sources or authority require a fresh review.

Each pursuit supports up to fifty dated assignment rows. End a relationship with an end date. An advisor who has lost employment/faculty eligibility can still have an existing period shortened or ended; extending it or assigning a new period requires current eligibility. Removing a saved row is an explicit reasoned correction, preserving its earlier facts in protected history. New changes belong to the current primary, active program pursuit. Earlier pursuits remain available for authorized historical review.

## Advisor and student experience

In **My campus → Advising**, an active assigned advisor can search **My advisees** by student name, institutional ID or program. Results use twenty-row pages. **Program and degree progress** runs the existing canonical degree-progress reader on demand and presents the curated program, requirements and progress information. An unselected degree, incomplete history or bounded review outcome remains explicit; advising never confers a degree or fabricates a completed audit.

This read access follows the current dated assignment and current primary pursuit. Both primary and additional advisors receive it. Ending/removing an assignment, disabling the account, losing staff/faculty eligibility or changing/closing the pursuit removes this scoped access. Registrar editing remains independently authorized. Other permissions already granted to an account continue to follow their own rules.

Students select a pursuit in **My campus → Degree progress** to see their recorded advisors beside the academic review. The student endpoint is strictly owner-only. It does not return staff reasons, review tokens, receipt hashes or raw history. Current person names are labels; the retained relationships keep stable person identities and dates.

A program change leaves the old advisor assignments under the old pursuit and does not copy them into the new one. The registrar deliberately assigns advisors for the new pursuit. An advisor cannot use the old relationship to read the new program or another student's audit. Advising does not expand which earlier coursework may support a pursuit; existing approved-reuse rules still apply.

## Historical and privacy safeguards

Each pursuit has a `student_advisors` stream, identified by its existing pursuit ULID, in the same protected journal as other domain events. The current `AdvisorHistory` model retains attributed `StudentAdvisorsWereAssigned` decisions, the previous operation, before-state fingerprint and dated assignments. Context protects the reason, authenticated recorder, confirmation time and correlation identity. Earlier advising is unknown until real decisions are recorded; no historical assignments are inferred during migration.

`sis_student_advisors` is a reconstructible current projection containing only student/pursuit/person identities, primary/additional type and dates. It is not another journal. Commands use expected versions, actor-bound review proofs, original-result retries, student/person locks, current pursuit/source checks and one transaction for event, projection and required audit. Final failures roll back the whole decision. Repeating an accepted confirmation after later changes or pursuit closure returns the original receipt without replacing those later facts.

Candidate and advisee searches use bounded SQL projections. Advisor authorization verifies the protected self-contained current assignment against its projection, together with the current pursuit and current account eligibility; it does not replay every advisor decision for every list row. Selected maintenance commands replay under the existing shared history limits. Private degree reviews recheck scope before returning and record metadata-only access evidence. No financial, registration, academic-award or external notification side effect is caused by viewing a result.

Browser/API reads remain private/no-store, browser POSTs retain framework CSRF, and debug collectors remain suppressed. Drafts and review proofs stay in page memory. The editor and portal discard delayed responses after navigation. Migration `20261120000007_CreateSisAdvising` adds the indexed ULID projection and independent protected permission without converting old records or introducing a second event table. Compiler coverage spans the five framework database dialects; live multi-engine, load and institutional acceptance remain separate qualification work.

The migration was applied to the configured DDEV application database on November 20, 2026. All twenty recorded events remained intact, and before/after checksums matched for `sis_history_events`, `sis_history_streams` and `sis_history_operations`. The new advisor projection starts empty; the protected permission was added without changing role or user grants. Focused verification passed 119 PHP tests, seven browser scenarios, the framework HTTP/CSRF smoke checks and scoped coding-standard checks. Browser checks used one worker and fictional records.

## Working check-ins

A completed check-in can now carry student-visible next steps, an optional follow-up date and an optional private advising note. This operational record does not assign an advisor, book an appointment, send a notification or change registration, standing, degree requirements, awarded work or any other authoritative academic fact. Do not use private notes to collect unrelated sensitive information.

Grant **`sis:advising:checkin`** independently through Access management. Staff also need `sis:student:read`; portal advisors need a current eligible, dated assignment to the student's current primary, active pursuit. Advisor-assignment management alone does not grant this duty, and the upgrade assigns no new role/user grants. Super administrators retain existing implicit authority. Both current primary and additional advisors with the duty can manage the team's pursuit-specific check-ins; private notes are available to those authorized staff and advisors only.

- Staff: open the selected student's program actions, then **Advising check-ins**.
- Advisor: **My campus → Advising → Program and degree progress → Advising check-ins**.
- Student: **My campus → Degree progress → Review progress → Advising check-ins**.

Check-ins load on demand, independently of degree-review availability. **Record a check-in** requires the actual held date (today or earlier), a next step of 1–1,000 characters and an explicit optional due-date choice. A due date cannot precede the held date. The optional private note is limited to 1,000 characters. Creation is limited to twenty active check-ins per pursuit; active and finished pages contain ten items, with cursor navigation. Earlier held dates represent when the actual check-in occurred, separately from its UTC recording timestamp.

## Student follow-up and closure

Students can respond once to an open check-in. Staff/advisors can close open or responded follow-up with a student-visible outcome, or cancel it with a reason. Closure is follow-up completion, never academic approval. Original next steps, dates, private notes, accepted responses and attributed action receipts remain retained. There is no overwrite/delete action: correct an original check-in by cancelling it with an explanation and recording a replacement when appropriate.

Student reads are strictly owner-only even for privileged accounts. Responses and outcomes remain available for historical pursuits through the existing student progress selection, and independently authorized staff can finish old follow-up; new check-ins require the current primary, active pursuit. Ending an advisor relationship, losing account/role eligibility or changing the primary pursuit revokes that advisor's scope. A changed canonical person/account link never transfers an earlier conversation to the replacement account: earlier owners lose access, replacement owners see only records pinned to them, and authorized staff/advisors can only cancel earlier-account records. There is no email or background messaging side effect.

The operational table `sis_advising_checkins` holds ULID routing/status/version fields and one authenticated encrypted envelope, bounded to 64 KiB including encryption encoding. Original facts and at most three actor-bound intent/result receipts are reconstructed and validated when opened; indexed fields must match the sealed facts. These receipts support idempotency within this working workflow, not a second authoritative event journal or an independently anchored archive. Writes serialize permission/person/student context and atomically save the expected version with metadata-only audit. Accepted retries return their original result without replacing later responses or closure; changed payloads, wrong actors, stale versions and damaged envelopes fail closed. Current authority and account links are rechecked before returning. Notes never enter ordinary reports or logs.

Private routes retain CSRF/no-store/debug protections. Drafts stay in page memory, stale errors preserve them for explicit refresh/review, and denied access clears displayed check-in details/forms. Detached responses cannot replace another selected program. Portal controls use the campus theme's accessible contrast; student, advisor and staff flows have narrow-screen and accessibility coverage.

Migration `20261121000002_CreateSisAdvisingCheckIns` adds this working table and the independent protected permission without changing recorded academic events or existing grants. Rollback refuses to destroy conversations. Institutional retention/disposition, appointment scheduling, automated reminders and cross-pursuit transfer policies remain separate work; do not infer them from follow-up dates.

Validation completed 2026-11-21: **45 focused PHP tests / 268 assertions**, separate real-provider advising and enrollment-load HTTP/CSRF/privacy checks, **nine browser scenarios** (five new check-in scenarios and four adjacent advising/student-progress regressions), five schema compilers and clean scoped new/changed PHP coding standards. Browser coverage includes private-note exclusion, 320-pixel reflow/accessibility, stale drafts, denied-access clearing, independent degree-review failure and delayed-save navigation. PHP coverage includes real program-change preservation, original receipts, role/owner/advisor boundaries, active caps/paging, Unicode bounds, ciphertext/index drift and atomic audit/final-authority failures. Existing unrelated formatting warnings in shared route/config files were preserved. All checks ran sequentially with low priority, bounded time/memory and one browser worker.

Applied **`20261121000002_CreateSisAdvisingCheckIns`** to the inspected `nomadicjosh_sis` MariaDB development database in **0.52 seconds**. All nine journal/support/student/advisor/audit/grant checksums matched before/after; twenty original journal events remain. The new working table is empty and the independent permission is protected, with existing grants unchanged. Compiler coverage is not live multi-engine/load/retention acceptance.
