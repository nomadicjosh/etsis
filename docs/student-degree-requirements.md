# Assigning degree requirements to a student program

The [course group library and degree builder](degree-audit-builder.md) publish reusable requirements. A student program must then retain **one explicit published degree version**. A new catalog publication never changes that selection automatically. The selected degree also retains its course-sharing policy: one group by default for new compositions, or multiple groups when the institution publishes that choice. Later policy edits do not change an earlier selection.

This stage records and displays selected requirements. **Preview with approvals** combines the retained baseline with verified student exceptions and allows comparison without changing the selection. It does not evaluate completed courses, calculate eligibility or grant a credential. [Student exceptions](student-degree-exceptions.md) now separately retain course waivers and minimum reductions. The [development roadmap](development-roadmap.md#3-student-degree-audit-evaluation) describes those later stages.

## Setup

Apply `20261108000007_CreateSisStudentRequirements` after the existing calendar, history, degree-template and course-group migrations. It creates `sis_student_requirements`, a reconstructible current-selection projection, and registers the protected `sis:degree-audit:assign` permission for upgraded installations. No student backfill or second event table is created. Development tooling has not applied this migration to a working database.

The permission is independent of the broad `sis` grant. Existing regular admin, staff, catalog and registration grants are not expanded. A protected `super` administrator already has authority; an access administrator can grant the new permission to a custom registrar role or directly to an authorized user. The assignee also needs `sis:student:read` and staff-workspace access. Default/system roles and permissions remain protected against editing/deletion. Runtime authorization continues to use the database, not `config/rbac.php`.

The migration is repeatable and preserves existing grants. A preexisting custom permission with the reserved name must be reviewed rather than silently made into a system permission. Rolling back the projection table does not delete authoritative decisions or revoke office grants.

## Registrar workflow

1. Configure the student's program assignment and its matriculation cohort. Publish a degree composition for that same program and academic year.
2. Open **Students → the student → Student programs → Degree requirements** on the relevant program row. Requirements are selected separately for each program pursuit; there is no guess based on which degree is primary or most advanced.
3. Choose **Assign published requirements**. Available versions come from the current cohort's program/year template. Choose an exact version using its radio card. No latest-version default is selected. Earlier inline schema-1/2 versions must be updated and published as reusable-group compositions before assignment.
4. Enter the **actual program pursuit start date** and a reason. The date is not inferred from entry-term dates, the person's institutional entry term or today's program settings. Future pursuit dates are rejected by this current-pursuit workflow.
5. Choose **Review requirement assignment**. Review shows the retained groups, courses, general minimums, policies and anticipated completion date. **Change selection** returns to the entered fields. Nothing is assigned until **Assign reviewed requirements** succeeds.
6. The selected version remains in place when catalog staff publish new degrees, groups or courses. Replacing it or correcting the pursuit date requires another reviewed decision and reason; the earlier decision remains in history.
7. **Academic work** opens a [paged review of current institutional and transfer work](student-academic-work.md). It verifies retained facts without applying them to requirements or calculating completion.
8. **Assignment history** lists curated decision/version references and effective/recorded dates. Protected reasons and actor details are not returned through ordinary student reads.

Missing cohorts and missing publications produce setup guidance, not invented requirements. Closing a program prevents new selections until it is reopened through the existing program workflow. A later cohort correction leaves the old selection intact and shows a review notice. Changing the requirement selection does not change the cohort, program assignment, courses, grades, finance or enrollment.

The anticipated completion date uses the **selected publication's** length in months and the explicitly recorded pursuit date. Month-end dates clamp to the last valid day, including leap years. An unknown length stays unknown. This estimate is not a credential decision or a promise of completion.

## Access and storage

Assignment/review/choice endpoints require both `sis:degree-audit:assign` and `sis:student:read`. Catalog or registration authority alone is insufficient. Private reads require the signed-in student owner or student-read authority, then verify that the program assignment belongs to that student. Owner reads need no catalog-editing permission. Existing faculty/advisor relationship authorization remains a separate implementation stage; this feature grants no institution-wide student-read permission to faculty.

Routes live under `/sis/students/{student}/programs/{assignment}/degree-requirements/`: a private GET reads the selection; protected POST endpoints provide `choices/`, `review/`, `assign/`, `history/` and the read-only `academic-work/` page. Browser mutations preserve framework CSRF protection, no-store/private responses and debug suppression. Tokens and entered student data stay out of browser storage.

`StudentRequirementsWereAssigned` schema 1 uses the existing authoritative `sis_history_events` table. Its stream is keyed by the student-program assignment ULID. Each event records references to the student, program, academic year, cohort binding/revision, degree template/version, explicit pursuit date and predecessor decision. Actor, protected reason, trusted effective time and operation identity are in the protected context. No student names, institutional IDs, transcript rows or duplicated group definitions are copied into the event.

The effective timestamp comes from the trusted review context; the journal supplies recorded time. This first command applies a current reviewed selection and does not offer arbitrary future scheduling. Historical pursuit-date corrections append another decision. Full lifecycle/grade/registration event conversion remains separate work.

The student row lock serializes selection decisions with current program/cohort changes. The encrypted review binds its actor, student, assignment, exact publication, program/cohort snapshot and expected stream version. Changed cohorts/statuses, conflicting decisions, tampered tokens and revoked authority fail closed. Event, stream head, operation receipt, current projection and audit commit together. An accepted exact retry returns its original receipt after later decisions without reverting the current selection.

The read model is checked against bounded, verified stream replay; missing or altered projection data fails closed. Root replay enforces predecessor continuity and stable student/program ownership. The internal immutable reader resolves exact degree/group/course publications independently of catalog-authoring permissions and today's catalog labels. History and choice pages are capped at ten entries with scoped authenticated cursors. It exposes curated requirements, not raw event envelopes or protected reasons.

## Shared course-history reads

A retained degree can contain several groups that refer to the same course, sometimes using different published versions. Resolving that degree now gathers those references first and verifies each unique course stream once per invocation. Each group still receives its own exact version's title and credits. For example, ten groups sharing five one-page course histories require five course-history page reads rather than fifty. Longer histories still use as many bounded pages as verification requires; this is not a claim about total database queries or elapsed response time.

Verification continues through each stream's frozen upper boundary, including later publications that were not selected. Missing references, repeated selected version identities and invalid predecessor chains are rejected. Only requested versions are retained while replaying; unrelated course definitions are discarded. Response fields, group ordering and course ordering remain unchanged.

The temporary lookup exists only inside one degree resolution. A later read, assignment review or confirmation verifies history again, even when the same reader service instance is reused. No shared/disposable cache, new projection, migration or event conversion is introduced. Owner/office authorization and command locks remain in the calling workflows. Directory searches continue using their existing bounded read models; they do not load full degree histories.

## Verification and remaining work

Fictional SQLite tests cover explicit selection, no publication backfill, owner/staff permissions, immutable version references, cohort/status conflicts, exact retries, protected payloads, atomic rollback, Codefy replay/reconstructed projections, projection corruption, scoped paging, permission migration and month-end dates. Actual provider/router checks cover DI, CSRF, private reads, retry receipts and write/read permission separation. Browser checks exercise the program-row entry point, explicit version selection, paging, preserved review fields, mobile accessibility, stale-review errors, cohort notices and read-only access. The schema compiles for all five framework dialects; live locking/LOB checks on other engines remain outstanding.

Next are retained academic/grade input histories, student course evaluation and explainable allocation, what-if comparisons, advising relationships, and specialization exclusions. Course waivers, substitutions and minimum exceptions now have their own reviewed workflow. Selecting requirements alone does not complete the degree-audit engine.

New schema-4 degree publications retain the resolved global/program grading scale alongside the schema-3 composition. Assignments can select schema-3 or schema-4 compositions; earlier unknown scale inputs remain unknown. [Student exceptions](student-degree-exceptions.md) include exact-publication substitutions, and [grading setup](grading-scale-setup.md) explains the remaining academic calculation integration.
