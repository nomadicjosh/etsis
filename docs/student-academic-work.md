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

## Checking requirement matches

Choose **Check requirement matches** from Academic work. This is an explicit read: the ordinary academic-work page does not resolve all degree requirements merely to list a student's records. **View academic work only** returns to the plain record review. Paging and refresh retain the selected mode.

The match view uses the pursuit's retained degree selection and its verified applicable exceptions, together with the requested academic-work page under one student lock. It shows the degree name, academic year and publication number. A missing selection produces setup guidance; changed-cohort and conflicting-approval notices remain visible. Publishing a new degree, course or grade definition does not silently change the selected requirements or historical work.

Each listed course can show its potential uses across groups, including an approved alternative for another named course. Matching uses stable course identities, never equal-looking course codes or today's catalog labels. The original course remains visible when an approved alternative is allowed. Alternatives use the exact approved replacement publication. Waived targets are marked waived without changing the student's earned work or reducing group quantities.

The explanations distinguish ungraded work, unknown grade history, non-passing grades, dropped courses, voided awards and positive-credit attempts without an earned award. A passing, completed zero-credit course can still be a candidate for a named course requirement; it contributes no invented credits. An optional **positive** per-course point floor compares the retained grade's per-grade points, not credit-weighted quality points, using the selected degree's retained grading publication and approved group reduction. A blank or explicitly reduced zero floor adds no numerical grade comparison; passing facts are still required. Unknown grading policies stay unknown, and differing grading publications require review rather than implicit conversion. The setup display's 4.0 fallback is not used as evidence for this comparison.

Missing or differing course publications are also flagged for equivalence review. This is a conservative inspection result, not a new institutional equivalence policy or a permanent rejection. Broad equivalence and grade-conversion decisions still need explicit institutional rules before the final evaluator can apply them.

**Candidate for allocation** does not mean applied, completed or eligible to graduate. The view does not choose among overlapping candidates, rank repeats, enforce sharing, satisfy group quantities, calculate residency/GPA or decide how unused work affects overall totals. An unlisted course can still be relevant to overall credits or GPA. Waivers create no earned work. A future allocator must inspect complete consistent inputs rather than combine independent match pages.

The pure matcher indexes the verified requirement references once per request, then returns at most 25 target matches per academic record, or 500 for a 20-record page. A visible notice identifies any truncated match list; it must not be interpreted as exhaustive. Existing publication-size and replay limits remain in force. No shared cache, stored evaluation, event or migration is introduced.

Matching uses the private `academic-work/matches/` POST endpoint with the same optional `after` body reference and CSRF protection. Authorization is checked before and after the combined read. Scope-only authorization no longer replays the entire selected catalog before the locked exception preview replays it again. With five distinct one-page baseline course histories and no alternatives, that preview now reads five course-history pages rather than ten. Approved alternatives can require additional publication checks. Every new request verifies history again; this is not a cross-request cache or a claim about total queries/elapsed time.

## History, paging and privacy

`AcademicWorkService` authorizes and locks the student, then asks the existing institutional and transfer ledgers to replay and verify only the requested records. Existing event integrity, predecessor and projection checks remain in force. A mismatch rejects the request instead of displaying inconsistent recorded facts. No events, repairs, operation receipts, enrollment changes or requirement selections are created by this read.

The SQL adapter uses the existing `(student_id, term_id, id)` credit index, retrieves at most 21 routing rows and returns 20 verified records with a next-page reference. The extra row establishes whether another page exists; its stream is not replayed. Page references are scoped to this student. A removed or foreign anchor requires restarting from the first page. No new table, migration or parallel event store is needed.

Paging order is by term reference and credit reference, **not academic chronology**. It must not be used to choose a student's latest repeat attempt. Separate requests do not freeze an institution-wide snapshot: concurrent additions or corrections can change later pages. Refresh when reviewing an actively changing record.

The private POST endpoint is `/sis/students/{student}/programs/{assignment}/degree-requirements/academic-work/`, with an optional `after` ULID in its body. It preserves framework CSRF checks, private/no-store responses and debug suppression. Only curated academic facts and publication references are returned, not raw events, protected reasons or actor details. Student data stays out of browser storage.

## Scope and verification

This is a current-work inspection tool, not a completed degree audit or proof that every historical credit has a current projection. Each returned row is verified; detecting omitted or missing rows across an entire student history requires the broader reconciliation/evaluation work. Do not infer graduation eligibility or historical completeness from this list.

Fictional SQLite tests cover retained grades after current catalog changes, per-grade versus weighted points, unknown earlier institutional/transfer history, voids, early and late drops, projection mismatches, owner/office checks, foreign assignment/cursor rejection, revoked access, paging across terms and no read-side writes. Provider/router checks cover dependency resolution, private responses, CSRF and permission denial. Browser checks exercise on-demand loading, paging, matching mode, escaped titles, cohort/conflict and setup notices, failures, mobile reflow and accessibility. Pure matcher tests cover exact version references, approved alternatives, grade floors, zero-credit courses, bounded output and deterministic rechecks. Integration checks verify retained selections, waiver revocation, private access, no read-side writes and fresh authority after publication reads.

Per-course matching, the [structural allocation core](degree-audit-allocation.md) and [repeat-credit/GPA calculations](degree-audit-calculations.md) are now available as separate components; next are complete student-input verification, integration of allocation across retained requirements, repeat and transfer-GPA policies, approved exceptions and explained applied/unused work. Overall credits and GPA must count each eligible attempt once regardless of the retained policy allowing a course to satisfy one or multiple groups. Any retained evaluation will also need consistent, complete input/version boundaries; paging through this review alone does not provide them.
