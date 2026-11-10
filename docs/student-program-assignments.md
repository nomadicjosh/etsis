# Additional student-program assignments

Implemented 2026-11-09. Additional programs now start their history when staff create the assignment. [Closing/reopening](student-program-status-history.md) continues in the same stream, and [primary-program selection](student-primary-program-history.md) remains a separate reviewed action. The first assignment created during admissions matriculation still needs its own historical conversion; this stage does not invent creation events for existing rows.

## Staff workflow

Open **Students → a student → Assign an additional program**. Select the academic program and its entry term, enter a reason, then choose **Review program assignment**. The review shows the program, entry term and cohort derived from the academic year linked to that term. There is no separate catalog-year field to enter.

If an applicable published curriculum revision exists, the review identifies it. If one must be created by the existing cohort setup workflow, the review says it will be created on confirmation. Merely reviewing does not create a curriculum, assignment, cohort binding or event. **Revise assignment** preserves your entered choices and reason.

If the entry term is not linked to an academic year, the review clearly says that calendar setup needs attention. Staff can confirm an assignment without a cohort and review its calendar setup separately. etsis does not guess a year from overlapping dates or silently invent curriculum requirements. Degree-audit requirements are selected through their own reviewed workflow.

**Confirm program assignment** creates an active additional assignment. The student's primary program, original institutional entry term, existing assignments, earned credits and posted charges remain connected to their existing records. Use **Make primary** separately if appropriate. This action takes effect on confirmation; it does not establish a backdated program start or record graduation or withdrawal.

An existing active assignment to the same program blocks another one. A closed assignment with the same program and entry term must be reviewed for reopening instead of recreated. Reviews expire after 20 minutes and must be repeated if they cross into another institutional date or if the relevant student, program labels, term/cohort mapping or curriculum proposal changes.

## Creation and later history

**StudentProgramWasAssigned** is the first event for a genuinely new additional assignment. It retains its identity, student/program/entry-term references, starting status, program and term labels, and the cohort/curriculum reference actually assigned, when one is available. The protected context retains the trusted actor, entered reason, operation identity and actual confirmation time.

**Program status history** distinguishes **Program assigned** from an observation of an older record. New assignments retain their creation context through later closure or reopening. The history shows the original program/term/cohort information as assigned even if today's labels or cohort assignment later change. It excludes protected reasons, actor details and raw event envelopes. Earlier untracked assignments still show **Observed current assignment**, with earlier history explicitly unknown.

If Biology is added and later closed, resending the original add confirmation does not add another Biology assignment or reopen it. etsis recognizes the completed request and returns its earlier creation result while the current assignment stays closed. The workspace reloads the current record after confirmation.

## Authorization, storage and checks

Assignment review and confirmation require `sis:registration:manage`; student ownership and `sis:student:read` alone do not grant creation authority. The command locks the student, term and program before reading the decision context and rechecks the staff actor's permission after waiting for those locks. Existing activity, primary-selection and program-status histories are verified where used. Browser mutations retain CSRF protection, private/no-store responses and suppressed debug collection. Review tokens stay in browser memory, with authenticated encryption and student/actor/purpose binding.

The assignment, derived curriculum if needed, cohort binding, creation event, checkpoint, operation receipt and required audit commit together. A failed audit or a cohort result that differs from the reviewed proposal rolls back the whole operation. The existing program-status checkpoint supports the creation event without a database schema change. All authoritative events continue to use `sis_history_events`; existing events, receipts and older observation schemas remain unchanged. No working database was migrated during this work.

Fictional integration checks cover write-free previews, existing and newly created curriculum references, explicitly unlinked terms, replay from creation, primary selection, closure and exact retries, duplicate and stale reviews, changed calendar/curriculum setup, ownership and permission rechecks, protected reasons, invalid event shapes and complete rollback. The workspace browser check covers review, revision, confirmation and history with keyboard/focus, accessibility and narrow-screen checks.

Initial matriculation and its first program assignment, complete lifecycle/standing decisions, backdated program corrections and operational projection rebuild/swap tooling remain later stages. An initial cohort snapshot records what was assigned at creation; later cohort corrections keep their separate existing history rather than rewriting that event.
