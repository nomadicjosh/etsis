# Academic work for degree-audit review

Open **Students → the student → Student programs → Degree requirements → Academic work**. The review is available even when no degree requirements have been selected. It shows the student's current institutional and transfer work; being listed does **not** mean a course applies to the selected program.

The screen loads only when opened, with up to 20 records per page. **Next page**, **Previous page** and **Refresh academic work** keep the review bounded. Each request rechecks the signed-in owner or `sis:student:read` authority and verifies that the program assignment belongs to this student. Catalog, transfer or degree-authoring permissions alone do not grant access. Closing a pursuit does not erase access to its student's retained academic work.

## Reading the records

Each card shows retained course code/title, attempted and awarded credits, source type, current status and the retained grade definition when known. A grade's points and its credit-weighted quality points are different: a three-credit course with a grade worth 4 points has 12 quality points. The review displays both without imposing a maximum grade scale or calculating overall GPA.

- An ungraded registration has no final grade and no earned credit.
- An early drop removes its current academic-credit projection. It does not appear in this list; its restricted history remains protected.
- A late drop remains visible with its institutional marker and, when recorded, its retained grade definition. The default W marker can exist without a grade-definition identity. It does not earn credit.
- A voided transfer award remains visible as voided with zero earned credit and no current grade. Its former grade is not presented as current.
- Older records without recorded history display observed current facts and an explicit earlier-history warning. An existing grade ID alone does not establish its historical points, passing treatment or GPA treatment. Today's grade definitions are never used to fill that gap.

Course and section publication references remain known or unknown as actually retained. A history verification does not invent missing publication references. Transfer completion dates and posting-term labels use retained source facts when available. Other term labels are marked **current label**; they are navigation context, not immutable academic definitions.

## History, paging and privacy

`AcademicWorkService` authorizes and locks the student, then asks the existing institutional and transfer ledgers to replay and verify only the requested records. Existing event integrity, predecessor and projection checks remain in force. A mismatch rejects the request instead of displaying inconsistent recorded facts. No events, repairs, operation receipts, enrollment changes or requirement selections are created by this read.

The SQL adapter uses the existing `(student_id, term_id, id)` credit index, retrieves at most 21 routing rows and returns 20 verified records with a next-page reference. The extra row establishes whether another page exists; its stream is not replayed. Page references are scoped to this student. A removed or foreign anchor requires restarting from the first page. No new table, migration or parallel event store is needed.

Paging order is by term reference and credit reference, **not academic chronology**. It must not be used to choose a student's latest repeat attempt. Separate requests do not freeze an institution-wide snapshot: concurrent additions or corrections can change later pages. Refresh when reviewing an actively changing record.

The private POST endpoint is `/sis/students/{student}/programs/{assignment}/degree-requirements/academic-work/`, with an optional `after` ULID in its body. It preserves framework CSRF checks, private/no-store responses and debug suppression. Only curated academic facts and publication references are returned, not raw events, protected reasons or actor details. Student data stays out of browser storage.

## Scope and verification

This is a current-work inspection tool, not a completed degree audit or proof that every historical credit has a current projection. Each returned row is verified; detecting omitted or missing rows across an entire student history requires the broader reconciliation/evaluation work. Do not infer graduation eligibility or historical completeness from this list.

Fictional SQLite tests cover retained grades after current catalog changes, per-grade versus weighted points, unknown earlier institutional/transfer history, voids, early and late drops, projection mismatches, owner/office checks, foreign assignment/cursor rejection, revoked access, paging across terms and no read-side writes. Provider/router checks cover dependency resolution, private responses, CSRF and permission denial. Browser checks exercise on-demand loading, paging, escaped titles, failures, mobile reflow and accessibility.

Next are deterministic allocation across retained requirements, repeat and transfer-GPA policies, approved exceptions and explained applied/unused work. Overall credits and GPA must count each eligible attempt once regardless of the retained policy allowing a course to satisfy one or multiple groups. Any retained evaluation will also need consistent, complete input/version boundaries; paging through this review alone does not provide them.
