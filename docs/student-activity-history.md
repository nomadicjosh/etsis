# Student activity decisions

Implemented 2026-11-09. This slice protects the student's active/inactive status. Program assignments, matriculation/admissions transitions, academic standing, load and the complete hiatus case lifecycle still require their own historical conversion. Apply the [event-sourcing architecture](event-sourcing-architecture.md) when extending those paths.

## Registrar workflow

Open **Students → a student → Change student activity**. Choose active or inactive, an effective date no later than today, and a reason. Review shows the previously known status on that date, the current status before the decision, and the resulting current status. Confirming records the decision; reviewing alone changes nothing. A review expires after 20 minutes and must be repeated if the student record or activity stream changes.

Use **Activity history** for a paginated, curated timeline in recording order. Effective dates determine when statuses apply. The screen excludes freeform reasons, actor identifiers, case notes and raw event envelopes. Both history and activity commands require `sis:registration:manage`; ownership and `sis:student:read` alone do not grant access. Existing route authentication, CSRF, private/no-store responses and debug suppression apply.

An activity decision does not drop courses, change program assignments, alter charges, revoke sign-in or change employment. Hiatus and return workflows still review their connected effects separately, then record any chosen activity change through this same ledger in their existing transaction. Their original encrypted case/action receipts remain intact. A retry returns the original accepted result and does not restore an earlier current status.

## Effective dates and observations

For example, a July 1 deactivation followed by an August 5 activation leaves the student active today. Recording a July 20 deactivation later preserves the August 5 activation. Review explicitly shows when a later effective decision continues to control today's status. For two decisions on the same effective date, the later recorded decision takes precedence; both facts remain.

The first accepted activity decision on a record without a stream first records **StudentActivityWasObserved**, identifying the current student/person relationship and observed status. It records what is seen now, with earlier history explicitly unknown. Reads, previews and the migration do not fabricate activity events. This applies to both older rows and new matriculations until the separate admissions/matriculation conversion is implemented. An observation is a fallback, not an invented activation that overrides a genuine backdated decision. Dates earlier than the first observation/decision remain unknown unless an authorized, now-attributed decision explicitly establishes a status for that period.

**StudentActivityWasDecided** retains the new status, business date, preceding recorded decision, and registrar/hiatus/return source with a case reference where applicable. Protected event context retains the trusted actor, reason, correlation and recording/effective times. Reasons stay encrypted in the journal; they are not copied into general audit metadata or ordinary reports.

Hiatus deactivation and return reactivation must actually produce their reviewed target current status. If a later effective activity decision prevents that result, the entire case/drop transaction rolls back. Staff can review the activity timeline, record the case without an activity change, or use the separate activity review. Case receipts must not claim deactivation/reactivation while the projected current status says otherwise.

## Storage and verification

Run the normal migration process for `20261109000006_CreateSisStudentActivity.php`. It creates only `sis_student_activity`, a current-projection checkpoint keyed by student ULID. It neither converts working data nor creates another event table. All activity facts use the existing protected `sis_history_events`, shared stream heads and operation receipts. No existing event bytes or case receipts are rewritten. The new migration compiles with the framework's MySQL, PostgreSQL, SQLite, SQL Server and Oracle compilers; runtime integration checks use isolated SQLite fixtures.

For students with recorded activity history, etsis checks that the current active/inactive status agrees with the status determined by their recorded decisions and effective dates. It also checks that the saved history checkpoint matches those decisions. For example, if the history says the student is inactive but the current student row says active, the records disagree. This disagreement is what “drift” means.

If that happens while opening the student's record, checking a registration, or reviewing a hiatus or return, etsis stops that request and reports an error. This prevents staff or students from continuing with an unreliable status. The mismatch needs investigation; simply opening a record does not automatically change either the current status or its history. Registration holds and other eligibility rules still apply independently.

Internally, `StudentActivity` processes the history in bounded pages and keeps only the state needed to determine the applicable status. This limits memory use as the history grows.

Authorized commands acquire the student lock, recheck permission, validate the reviewed stream version and append/project/audit atomically. Actor/student/purpose-bound authenticated encrypted review tokens stay in memory in the browser. Exact retries resolve the original accepted outcome, even after a later decision, without appending or projecting again. Historically backdated changes that leave today's flag unchanged still advance the checkpoint; projection writes do not depend on database-specific affected-row semantics for unchanged flags.

Fictional tests cover effective-date replay, same-day ordering, unknown earlier periods, stale and expired reviews, exact retries after later changes, authorization, token binding/tampering, private timeline pages, projection/checkpoint drift, and atomic rollback including a conflicting hiatus/drop plan. Isolated route/browser checks exercise the new UI, CSRF, private responses and accessibility. These checks do not establish legal compliance, full student reconstruction, recorded-time as-of reporting or operational projection rebuild/swap tooling. Future scheduled activity changes, explicit decision revocation/retiming and the remaining student/admissions streams are separate stages.
