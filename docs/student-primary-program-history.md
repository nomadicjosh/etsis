# Primary-program decisions

Implemented 2026-11-09. This stage retains changes to the student's primary program, including the exact student-program assignment selected. It complements the separate [program closure/reopening history](student-program-status-history.md). [Initial matriculation](student-matriculation.md) now establishes the first primary pursuit. Standing, graduation and backdated program corrections remain separate work. [Additional assignments](student-program-assignments.md) now begin with their own creation history.

## Staff workflow

Open **Students → a student → Student programs**. Choose **Make primary** on an active assignment, enter a reason, then choose **Review primary selection**. Review the previous and proposed pursuits and their entry terms before confirming. **Revise primary decision** preserves the entered reason while you revise it. Reviewing alone changes nothing.

The selection takes effect on confirmation. A review expires after 20 minutes and must be repeated if it crosses into another institutional date. Changes to the student context, primary-selection history, target assignment status/history or proposed labels also require another review. Selecting a closed assignment, another student's assignment, an already-primary assignment or an ambiguous duplicate active pursuit is rejected.

Changing primary preserves the student's identity and original institutional entry term. Both pursuits keep their identities, entry terms, curriculum/cohort references and degree-requirement selections. Other assignments remain active. Grades, credits and posted charges are not rewritten. Future billing actions that use the primary program use the verified current selection under the existing billing rules; the selection itself does not post, reverse or recalculate money.

Use **Primary-program history** in the student's action buttons for a private, paginated timeline. It shows the exact assignment and program identities, program labels and pursuit entry-term label retained at each observation/selection, plus effective and recording times. Today's catalog renames do not change those historical labels. The ordinary student record may still display today's program label. The timeline excludes protected reasons, actor identifiers and raw event envelopes.

Primary decisions and their timeline require `sis:registration:manage`. Ownership and `sis:student:read` alone retain their existing student-record access and do not grant primary-program management. Browser mutations retain authentication and CSRF checks; responses remain private/no-store and debug collection is suppressed.

## Earlier history and retries

The first accepted primary change on a student without a stream records **PrimaryProgramWasObserved**. It captures the selection seen now and its student/person/original-entry-term relationship, then **PrimaryProgramWasSelected** records the new pursuit and preceding recorded decision. Protected context retains the trusted actor, entered reason, actual confirmation time and operation identity. Earlier primary selections remain explicitly unknown. Reads, previews and the migration do not manufacture earlier events. Newly confirmed matriculations instead begin with **PrimaryProgramWasEstablished**; their later changes append selections without inventing an observation.

The existing primary pointer identifies a program, so etsis resolves its exact active assignment before observing it. If no active assignment exists, or multiple active assignments make the choice ambiguous, etsis stops and reports an error. It does not guess an assignment or repair the records while reading them.

A repeated confirmation returns its original accepted result without changing today's selection. For example, after a student moves from program A to B and later back to A, retrying the earlier B confirmation leaves A primary. This also works if B has since been closed. A different review made before that A → B → A cycle is rejected even though the visible student fields have returned to their original values.

## Record checks and migration

Apply `20261109000008_CreateSisPrimaryProgramStreams.php` through the normal migration process. It creates only `sis_primary_program_streams`, a small checkpoint table keyed by student ULID. Both new event types use the existing authoritative `sis_history_events`, shared stream heads and operation receipts. The migration neither alters current student rows nor rewrites existing events or receipts. The schema compiles with the framework's MySQL, PostgreSQL, SQLite, SQL Server and Oracle compilers; runtime checks use isolated SQLite fixtures.

Student-record/program-list reads, program-status reviews and registration verify the current primary selection against its recorded decisions. The billing adapter verifies it before selecting program-specific fee rules. Tracked assignment statuses are checked too. If the student row, selected assignment or saved checkpoint disagrees with the protected history, etsis stops the request and reports an error. The records need investigation; opening a screen does not automatically change either copy. Other reporting and still-unconverted workflows keep their documented boundaries.

The student lock serializes primary/status decisions and the existing registration/billing workflows. Current registrar permission is checked after acquiring it. History versions and authenticated encrypted actor/student/assignment/purpose-bound review tokens protect confirmation. Event append, checkpoint, primary-pointer update and required audit either commit together or all roll back. Reasons stay encrypted in the journal, out of general audit metadata and browser storage. Replay reads bounded pages into constant-size state, without charging accounts or changing another academic record.

Fictional tests cover replay, retained labels, ambiguous existing assignments, expiry and midnight, stale student/primary/target reviews, actor and ownership checks, protected reasons, atomic rollback, exact retries after later selection/closure, conflicting projections, frozen timeline pages, preserved earlier charges and later program-specific billing. The workspace browser check covers review, revision, confirmation, history, keyboard focus, accessibility and narrow-screen use. Backdated primary intervals, full student reconstruction and operational rebuild/swap tooling remain later stages.
