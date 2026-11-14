# Registration decisions and section order

Accepted registrations and early/late drops now have their own replayable enrollment history in **`sis_history_events`**. Each enrollment ULID identifies one `EnrollmentHistory` stream. [Academic-credit decisions](academic-credit-history.md) and finance remain separate domain facts, saved in the same transaction. There is one authoritative event table.

## Registration and staff workflow

Self-service, staff registration and reviewed batch registration use the same acceptance service. It acquires student/person/section locks before consistent eligibility reads, checks active status, prerequisites, applicable restrictions, registration window, web-registration policy, term course limit and actual remaining capacity, then records the accepted decision. Existing active registrations are verified and returned without another seat or charge. A closed late-drop enrollment requires a separate replacement/re-enrollment decision; an early retake receives a new enrollment and academic-credit identity.

The accepted event retains the enrollment timestamps/status, selected course publication when known, section/term codes, delivery mode, assigned faculty reference, capacity and fee/currency in use. It also retains the evaluated prerequisite identities, accepted result, registration window, course limit and staff/self-service context. This is a snapshot of the admission basis, not a claim that complete immutable section definitions, every supporting prerequisite-grade identity or all historical eligibility policy versions are already implemented.

In **Students → Student record → Registration history**, authorized registrars can review recorded enrollment decisions, including restricted early drops. The screen groups decisions by enrollment and shows effective/recorded times and section order. It has bounded pages, keyboard controls and narrow-screen reflow. Ordinary student ownership, teaching access and `sis:student:read` do not grant this restricted history: `sis:registration:manage` is required and rechecked after acquiring the student lock. Responses remain private/no-store and browser reads retain CSRF protection. Raw events, reasons, eligibility payloads, other students' identifiers and cross-enrollment predecessor references are withheld.

Early add/drop still deletes current enrollment/academic-credit projections and reverses the original course charge. Restricted enrollment and academic events remain. Late drop retains attempted credit, the institution's selected grade/GPA rules and the charge while releasing the seat. Hiatus closures delegate to the same drop service. Previously saved drop/hiatus receipts remain unchanged; their earlier contents are evidence, not a second canonical registration stream. Previously issued transcript documents remain independent retained evidence.

## Retained prerequisite evidence

New registrations retain the current pursuit and exact supporting academic-record, award/enrollment and pursuit-applicability versions as protected `prerequisite_evidence` metadata on the existing acceptance event. The evidence contains only the required course identities and one verified passing record per course. It does not duplicate grade bodies, student profiles, reasons or raw historical events. Institutional and transfer work keep their different stream identities; an earlier untracked enrollment version remains explicitly zero. A recorded passing zero-credit course can satisfy a course prerequisite.

The command evaluates available verified work under its student lock, filters it to the current pursuit or explicitly approved reuse, then accepts the seat, credit, charge, evidence and required audit atomically. If several eligible records satisfy one prerequisite, it consistently selects the lowest credit ULID; source iteration order does not change the witness. This is a deterministic evidence choice, not a repeat-credit/GPA policy. Courses without prerequisites skip academic collection while retaining the actual empty requirement set and pursuit.

**Registration history** shows **Prerequisites at acceptance**, with required-course and supporting-record counts. It reads the retained acceptance decision, so a later grade correction, reuse revocation or program change does not rewrite why the student was registered. An accepted retry keeps its original result. Older acceptance events and observed earlier enrollments show **Supporting prerequisite references were not recorded**; the system never manufactures earlier evidence. These references are protected historical facts; ordinary browser/report responses expose only the curated status/counts.

### Group registration review

**Register a group → Review selected group** now shows a **Prerequisites** column: verified for this pursuit, missing prerequisites, no course prerequisites, or an existing registration whose earlier eligibility is kept. Missing prerequisites disable confirmation in the browser and are also refused by the backend. **Change selection** preserves the selected students.

The reviewed context includes a fingerprint of each new registration's selected evidence. Grade or reuse decisions that change that support require a fresh review before confirming; a still-passing grade amendment changes its academic version and therefore also invalidates that pending review. Already-completed batch retries return their original receipt before checking current prerequisites. Review creates no academic decisions, charges or audit-result records.

All new students' preview checks share the existing 400-page/2,000-event academic replay allowance. Confirmation uses one allowance across its context verification and registration rechecks; it does not cache earlier request results or reset the allowance for each student. An oversized or inconsistent history stops the operation with a private records-office notice, without a partial batch. Current actor/permission are checked after the history reads and before returning or committing. Restricted registration-history pages also share a bounded replay allowance across their twenty entries.

This extends the existing acceptance event with explicitly optional historical evidence: earlier events remain valid with unknown references. It adds no event type, schema migration, parallel journal, permission or inferred attribution. The shared journal's operator verifier now resolves both directions of the `work_pursuit` stream mapping, including its protected receipts; the isolated debugger run confirmed the missing `WorkPursuit` aggregate mapping before the repair.

Full versioned prerequisite/co-requisite expressions, minimum-grade prerequisite policies and complete eligibility-policy publication remain separate roadmap work. The current prerequisite contract requires all listed courses.

## Accepted server decision order

A section's locked allocation boundary assigns a monotonically increasing **section decision sequence** to each accepted allocation/release. The event retains that sequence, previous accepted decision reference and used-seat count before/after the decision. The unique section/sequence projection index guards duplicate ordinals. A failed registration, interrupted rolled-back batch, repeated active registration or exact completed-drop retry does not consume another committed sequence.

This order records accepted committed server decisions under the section lock. It is not browser-click order, request arrival order, a denied-request log or a waitlist/fairness policy. A batch commits its section decisions together in its deterministic processing order. Its partial decisions never become visible after rollback.

The first sequence begins when instrumentation accepts its first real action for that section. Its used-seat count can include older untracked enrollments; their earlier order is unknown. No migration manufactures their registrations. A current-state enrollment is observed only when a real reviewed closure needs its baseline; the observation has the current actor/time and no invented original admission basis. Merely reading history or retrying an existing older active registration creates no baseline event.

The next decision checks the retained index head against its protected enrollment stream, verifies routing/index consistency and compares the last committed used-seat count with current active enrollments. Altered counts, missing routing or inconsistent decision indexes stop the operation. A reduced capacity can prevent another allocation while still permitting a release. Current capacity/fee/code edits do not rewrite values retained by an earlier accepted decision.

This design replays a short stream for the relevant enrollment and latest section decision, rather than replaying every student's enrollment history for each seat. Capacity/course-load reads use indexed SQL counts rather than materializing a section's rows in PHP. Full reconciliation still needs controlled replay: as with the shared journal itself, database-wide rollback or coordinated corruption requires the documented external anchors and operational controls, not an unsupported claim of complete tamper proofing.

## Storage, deployment and replay

Apply `20261109000005_CreateSisRegistrationHistory` before deploying this write path. It creates two reconstructible support projections:

- `sis_registration_streams`: enrollment/student/section/term routing and latest section sequence. Routing survives deletion of the current early-drop row.
- `sis_registration_decisions`: operation/enrollment/section/playhead pointers and unique section sequence, with no event bodies or academic/eligibility payloads.

All primary identities are ULIDs; the sequence is an ordering value. Neither table is another event store. The migration also adds retry-safe section/status and student/term/status indexes for capacity and course-load counts. There are no synthetic-event backfills or live-data conversions. Down migration refuses to discard retained routing/order. Older recorded event schemas, bytes and operation receipts are preserved.

The new version-1 contracts are `enrollment-was-established` and `enrollment-was-closed`. Establishment distinguishes genuine acceptance from an observation of earlier current-state facts. Closure retains the actual early/late outcome and reviewed grace/policy reference. Expected-version append, protected context, projection/index changes, academic events, charges/reversals, receipts and audit share the unit of work. New accepted academic-credit events use the enrollment as their correlation/causation reference; earlier event context remains unchanged. Existing framework financial events retain their earlier attribution limitations and their enrollment operation references.

Replay reconstructs enrollment routing, order indexes and current enrollment rows without evaluating today's rules, assigning another seat, charging again, sending messages or recreating removed early-drop rows. A staging check reconstructs a removed enrollment's routing/order while leaving its current row absent. Production rebuild/swap tooling is still outstanding; web requests never repair corrupt projections automatically. Enrollment-row timestamps retain their existing UTC second precision, while event context retains its supplied effective instant/timezone and the journal's recorded time. Sequence provides ordering without relying on timestamp precision.

## Verification and remaining boundaries

Fictional SQLite checks cover deterministic replay, registration/release/retake order, known versus observed history, retained capacity/fees, late-drop charge preservation, independent registrar history access, bounded paging, projection/index/count tamper rejection, atomic financial/audit failure and side-effect-free staged reconstruction. Two isolated PHP workers, each limited to 96 MB and a short timeout, verify competing final-seat requests and same-student retries against separate connections to one disposable database. Keys pass only through private process input; database artifacts are removed afterwards. This is runtime SQLite evidence, not live locking verification for every engine. All 97 SIS table definitions compile through the five installed schema compilers.

Provider/router checks exercise real dependency wiring, CSRF and private responses; browser checks cover restricted-history visibility, literal text escaping, paging, mobile reflow and automated accessibility scans. The working database has not been migrated by development tooling.

Waitlists, durable denied-request/request-arrival histories, full immutable section/eligibility definitions, closed-enrollment replacement and student program/lifecycle/admissions conversions remain staged work. Attendance and assignment scores retain their earlier student/section storage and need a separate attempt-binding review. Operational rebuild coordination, retention/key lifecycle and external integrity anchors remain production acceptance work.
