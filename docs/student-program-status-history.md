# Closing and reopening student programs

Implemented 2026-11-09. This stage preserves active/closed status decisions for an individual student-program assignment. New assignment creation, matriculation, graduation, standing and broader program changes still have separate work remaining. [Primary-program selection](student-primary-program-history.md) now has its own reviewed history. It does not introduce another event store.

## Staff workflow

Open **Students → a student → Student programs**. For a program that is not primary, choose **Close assignment** or **Reopen assignment**. Enter a reason, choose **Review program status**, then check the program, entry term and before/after status before confirming. Reviewing alone changes nothing.

The change takes effect when confirmed. The screen shows the institution's current date; a review must be repeated if it crosses into another day. Reviews also expire after 20 minutes and become invalid if the assignment history or relevant student context changes. Backdated and future program-status corrections are not part of this stage. This is deliberately different from the separately implemented effective-date student activity editor.

Closing an assignment preserves its identity and entry term, its curriculum and degree-requirement selections, earned credits and financial history. It does not graduate the student, withdraw them from courses or deactivate their overall student record. **Select another primary program before closing the current primary assignment.** Reopening is blocked if the student already has another active assignment to that program, including one created after the reopening review.

Each program row has **Program status history**, including the primary program. The timeline shows observations and decisions, their statuses, and their effective and recording times. It excludes protected reasons, actor identifiers and raw event details. Managing these decisions and reading their timeline requires `sis:registration:manage`; student ownership and `sis:student:read` alone do not grant those permissions. Students retain their existing authorized access to their program list.

## What is retained

The first accepted status change on an assignment without a stream records **StudentProgramWasObserved**. This captures its current student, program, entry term and status at the observation time. Earlier status decisions remain explicitly unknown. Neither opening a record nor reviewing a change manufactures earlier history. This rule also applies to newly created assignments until their creation workflows receive their own historical conversion.

**StudentProgramStatusWasChanged** then records the chosen active/closed status and preceding recorded decision. The protected context retains the trusted staff actor, entered reason, operation identity and actual confirmation time. Earlier events and existing curriculum/requirement histories remain intact. Reasons are encrypted in the shared journal and are not copied into general audit metadata or the ordinary timeline.

A repeat confirmation returns its original accepted outcome. For example, retrying an earlier closure after the program was reopened returns the earlier closure receipt and leaves the program active. A different review made before that close/reopen cycle is rejected even if the current status has returned to its original value.

## Record checks and migration

Apply `20261109000007_CreateSisProgramStatusStreams.php` through the normal migration process. It creates `sis_program_status_streams`, a small checkpoint table keyed by assignment ULID. All authoritative facts remain in the existing protected `sis_history_events` with the shared stream heads and operation receipts. The migration does not alter working student rows, invent events or rewrite earlier evidence. It compiles with the framework's MySQL, PostgreSQL, SQLite, SQL Server and Oracle compilers; runtime tests use isolated SQLite fixtures.

Before displaying a program list, selecting a primary assignment, or reviewing/confirming a status change, etsis checks tracked assignments against their recorded history. Duplicate-program checks also verify the other assignments to that program. If the current status, assignment identity or checkpoint disagrees with recorded decisions, etsis stops the request and reports an error. The records need investigation; opening a screen does not automatically change either copy. Ordinary reporting and other still-unconverted workflows retain their documented scope.

The student lock serializes assignment changes. Current registrar permission is checked again after acquiring it. Reviewed history versions prevent stale decisions, and the event append, checkpoint, status update and required audit either commit together or all roll back. Replay reads bounded pages into constant-size state and performs no academic, billing or notification side effects. Browser review tokens are authenticated, encrypted, bound to the actor/student/assignment/purpose and held only in memory.

Fictional tests cover deterministic replay, stale reviews and close/reopen cycles, exact retries, ownership and current permission checks, token binding, missing reasons, expiry, primary/duplicate rules, conflicting saved records, bounded frozen history pages, encrypted reasons, and complete rollback on audit failure. The workspace browser check covers the review, confirmation and timeline with keyboard/focus, accessibility and narrow-screen checks. Full program reconstruction, backdated primary-program intervals and other backdated corrections and operational rebuild/swap tooling remain later stages.
