# Academic input verification

Open **Students → the student → Student programs → Degree requirements → Academic work → Verify academic inputs**. This on-demand check compares available registration, academic-credit and transfer references, then returns only work applicable to the selected pursuit. It is available before a degree selection and remains available for a closed pursuit to the signed-in owner or someone with `sis:student:read`.

The ordinary academic-work list still loads 20 records at a time. Verification is a separate bounded operation over the available sources; it does not combine independent list pages into an apparent complete audit.

## Which pursuit can use the work?

Prerequisites, academic-work candidates, requirement matching, credit/GPA calculations, allocations, combined degree/specialization progress and temporary audit tests use the **selected student-program pursuit**. Coursework from another pursuit, including an earlier attempted, completed or closed program, is excluded unless a registrar has explicitly approved reuse of that particular record for the receiving pursuit. Majors, minors, specializations or an institution's combined dual-degree program can be components of one pursuit; they are not automatically separate pursuit records. This boundary does not add those curriculum features where they are still unfinished.

New registrations, whether entered by the student or by staff, record their current primary pursuit with the genuine academic-credit creation. New transfer awards do the same. An accepted registration/award retry preserves its original attribution. Changing the primary program or closing the former pursuit does not move its academic work. The grading-policy contexts captured at registration answer a different question and do not establish coursework ownership.

Older work with no recorded pursuit stops a complete audit or prerequisite check with an actionable records-office message. It is not silently assigned to today's primary program or treated as zero earned credit. Attribution alone does not establish an unknown grade, course publication or earlier award history.

### Registrar review

Open **Students → the student → Student programs → Degree requirements → Work attribution & reuse**, or choose **Review earlier coursework** immediately after confirming a program change. The latter opens the new pursuit directly, even before degree requirements are published or assigned. This office-only workbench identifies the receiving pursuit and lists current work across the student's pursuits, including unknown attribution. It does not expose restricted early-drop records.

1. For **Pursuit not recorded**, choose **Attribute this work**, select the original pursuit using institutional evidence, explain the decision, review and confirm. Earlier closed pursuits remain selectable; there is no automatic original-pursuit selection.
2. Use **Correct attribution** only when evidence shows the original pursuit was recorded incorrectly. The previous attribution remains in history; correcting it does not automatically approve reuse by the former owner.
3. Select records owned by earlier pursuits, then choose **Review selected coursework**. Select up to 20 institutional or transfer records across pages; the visible selection and entered reason survive paging and review revisions. Enter one reason, review the receiving pursuit and every selected record's original pursuit, then **Confirm selected reuse approvals** once. The individual **Approve reuse for this pursuit** remains available. New approvals require an active receiving pursuit and affect future prerequisite checks and audits without awarding, charging or registering a course again. Published curriculum and grading rules still determine what can count.
4. **Revoke reuse for this pursuit** removes that future applicability while preserving the original work and approval history. A retry of an earlier accepted confirmation returns its original receipt without restoring revoked reuse.

Each command requires both `sis:academic-work:scope` and `sis:student:read`. The new scope permission is protected and independent: ordinary registration, faculty, student-read or broad `sis` access does not imply it. A super administrator can grant it to an appropriate registrar role or individual. Student-facing reads expose neither these approval controls nor the protected reasons/actors.

Reviews expire after 20 minutes and are bound to their staff member, student, selected academic records, exact academic evidence and expected attribution versions. Confirmation checks them again under the student lock. A changed record, expired review, lost permission or failed audit rolls back the entire selection: none of its new approvals remain. Events, projections, existing per-record receipts and required audits commit together in the single protected journal. The grouped command shares the individual command's validation and receipt handling; it collects academic evidence once per review and once per first confirmation, with one shared replay budget. It adds no batch table or separate event store.

If a confirmation is interrupted, retrying that same accepted review returns its original results without reapplying approvals. This remains true after a later revocation, pursuit closure or review expiry. A new selection requires a fresh review. Selections and reasons live only in the open workspace's memory, not browser storage. Reads never create attribution or approve reuse.

### Attribution and reuse history

Each work card now offers **Attribution & reuse history**. It shows original attribution, corrections, reuse approvals and revocations in recorded decision order, with effective and recorded times. An empty history says that no pursuit decisions were recorded; opening it never assigns older work or invents missing decisions. Closed pursuits remain identifiable, and revoked approvals remain visible.

Pages contain at most 20 decisions. The first page fixes the history boundary for subsequent pages; **Refresh decision history** starts a new review that includes later decisions. **Back to work selection** preserves selected records and the entered shared reason. A response arriving after the review was closed or replaced cannot reopen it. History is fetched only when requested and is never stored in browser storage.

Program names, entry terms and statuses identify today's verified pursuit records. The screen explicitly distinguishes these current labels from the recorded actions and times: earlier decisions did not retain program-name snapshots. It does not present a renamed program as its historical name.

The same two independent registrar permissions apply on every page. Current academic work, source ownership, pursuit decisions and projections are verified under one student lock and shared replay allowance. A changed or missing projection, invalid continuation, exhausted allowance or lost authority stops the read without partial results or repairs. Another student's work and restricted early removals remain unavailable. Successful reads require a metadata-only access audit; a failed audit or final authority check rolls it back. No events, receipts, academic projections, grades, charges or reuse approvals are created by this view.

The private browser POST route is `/sis/students/{student}/academic-work-pursuits/{credit}/history/`. It retains framework CSRF protection, private/no-store responses and debug suppression. Responses contain curated decision references, current pursuit labels and times; protected reasons, staff identities, payloads, operation references and source hashes are excluded. This adds no schema or event format.

## Understanding the result

- **Available references checked:** current records agree with the available history and routing references. The count includes current institutional and transfer records, including in-progress work, late drops and voided awards. It is not a count of passed courses.
- **Earlier history is unknown:** available current records and references agree, but some records predate recorded history or were first recorded as an observation of existing data. The system does not invent earlier events or substitute today's grading definitions.
- **Verification reached its work limit:** no complete input set was produced. No partial count or partial calculation is returned. This is not a failed academic requirement; a larger controlled review remains to be implemented.
- **Records could not be verified:** available sources contradict each other, a required linked record is missing, or recorded history fails its integrity checks. The current work screen remains visible with an accessible error. A registrar must arrange investigation; verification does not repair or overwrite records.

None of these outcomes calculates degree completion, applies courses to groups, calculates GPA or establishes graduation eligibility. Unknown history also does not automatically invalidate the student's academic work.

## What is checked

The collector reads routing fields from six student-scoped sources: current academic credits, academic-credit stream references, registration stream references, current enrollments, transfer stream references and current transfer awards. Taking their union catches missing current rows when another reference survives. The sources must agree on record, student, section and term links where present, current/removal status and whether recorded history exists.

One internal `AcademicInputVerifier` checks the surviving sources; authorized audit and registration services share it. The existing ledgers replay each discovered stream and verify its current projection and retained version references. A separate bounded attribution replay verifies ownership/reuse against its reconstructible projection before the selected pursuit receives any inputs. Registration checks also verify its retained decision indexes. Transfers must have a matching award and credit; a void remains a current historical record with no restored prior grade. Current catalog labels are not used as immutable academic evidence.

A valid early drop has retained academic and registration history but no current credit or enrollment projection. It is checked internally and omitted from current work and the visible count. The API returns only a status and current-record count, or a safe error. It never returns early-removal identifiers, removed-record counts, raw events, actors or reasons. Internal snapshots redact JSON and debug serialization.

## Bounds and consistency

Each source query uses existing student-leading indexes and fetches at most 201 routing rows. More than 200 distinct source records, including restricted removed sources, stops the check. A shared per-request replay budget permits at most 400 page reads and 2,000 returned events, with at most 20 events per page. Empty streams also spend a page allowance; attribution streams share this allowance, so the replay cap can stop a verification before its 200-source ceiling. Limits apply across all records, rather than resetting for every stream; no snapshot, page counter or replay result is cached across requests.

The collection runs under the existing student lock and transaction, with authorization before collection, after locking and before returning. It writes no events, receipts, baseline observations, repairs or saved audit results. A failed history read rolls back before the public failure result is returned. Internal callers joining another transaction must treat a failed collection as unusable and abort that operation; a nested transaction failure may make that outer transaction rollback-only.

An internal successful snapshot includes current curated facts and protected source/stream-version references. Its validity belongs to that transaction. A future evaluation must hold the same boundary while resolving retained requirements and policies; it must not reuse a returned snapshot in a later request as if the student were still locked.

## Coverage limits and next work

This checks **available surviving sources**, not absolute completeness of the historical archive. If both current rows and every student-routing reference for a stream are lost, a student-scoped query cannot discover that orphaned stream. Broader protected reconciliation and recovery must detect that case; ordinary verification does not scan the global event journal or certify that every historical record exists.

The separate [Credits & GPA review](student-credit-gpa-review.md) consumes verified current inputs and selected degree policies under one lock/budget. The combined [Degree progress review](student-degree-progress.md) now uses one collection to compare approved general minimums, distinguish institutional cumulative GPA from policy-controlled overall GPA, and propose structural course-group placement. The [allocation core](degree-audit-allocation.md) and [repeat-credit/GPA core](degree-audit-calculations.md) remain distinct calculation components within that review. Remaining completion/transfer chronology, grading compatibility policy, wider degree constraints and reproducible retained result boundaries are still unfinished. These current reviews do not establish a completed degree audit or graduation eligibility.

Private POST endpoint: `/sis/students/{student}/programs/{assignment}/degree-requirements/academic-work/verify/`. Browser requests retain framework CSRF protection, private/no-store responses and debug suppression. `20261114000001_CreateSisAcademicWorkPursuits` adds `sis_work_pursuit_scopes`, an indexed current attribution/reuse projection, and the independent protected permission. Actual decisions append `AcademicWorkPursuitWasRecorded` to the existing single protected journal. The migration performs no attribution backfill and creates no parallel event table. A known attribution whose projection is missing or inconsistent stops verification.

Registration uses the same verified, pursuit-filtered academic evidence for simple course prerequisites. A retained passing grade qualifies, including a zero-credit passing course; current grade-table rows alone do not prove the prerequisite. Courses with no prerequisites skip academic collection. Staff batch confirmations share a bounded prerequisite replay allowance and recheck each new registration atomically. New accepted registrations now [retain their exact prerequisite support](registration-history.md#retained-prerequisite-evidence) in the existing protected acceptance event. Group reviews show readiness and require another review when the selected evidence changes. Earlier unrecorded references remain unknown. Complete versioned prerequisite/co-requisite expressions remain later work.

## Trusted operator comparison

The existing [history verification command](event-history-foundation.md#selected-student-academic-verification) now offers `--student=ULID` for minimal read-only comparison across all available pursuits. It shares the internal six-source verifier but uses observed routing/head boundaries instead of write locks, discarding changed results. It returns no academic records or restricted removal references and makes no repairs. This does not replace the authorized, locked pursuit-specific review or verify attribution/reuse. Earlier gaps, empty observations and work limits remain explicit non-certifying outcomes.
