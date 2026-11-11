# Academic input verification

Open **Students → the student → Student programs → Degree requirements → Academic work → Verify academic inputs**. This on-demand check compares the student's current work with available registration, academic-credit and transfer references. It is available before a degree selection and remains available for a closed pursuit to the signed-in owner or someone with `sis:student:read`.

The ordinary academic-work list still loads 20 records at a time. Verification is a separate bounded operation over the available sources; it does not combine independent list pages into an apparent complete audit.

## Understanding the result

- **Available references checked:** current records agree with the available history and routing references. The count includes current institutional and transfer records, including in-progress work, late drops and voided awards. It is not a count of passed courses.
- **Earlier history is unknown:** available current records and references agree, but some records predate recorded history or were first recorded as an observation of existing data. The system does not invent earlier events or substitute today's grading definitions.
- **Verification reached its work limit:** no complete input set was produced. No partial count or partial calculation is returned. This is not a failed academic requirement; a larger controlled review remains to be implemented.
- **Records could not be verified:** available sources contradict each other, a required linked record is missing, or recorded history fails its integrity checks. The current work screen remains visible with an accessible error. A registrar must arrange investigation; verification does not repair or overwrite records.

None of these outcomes calculates degree completion, applies courses to groups, calculates GPA or establishes graduation eligibility. Unknown history also does not automatically invalidate the student's academic work.

## What is checked

The collector reads routing fields from six student-scoped sources: current academic credits, academic-credit stream references, registration stream references, current enrollments, transfer stream references and current transfer awards. Taking their union catches missing current rows when another reference survives. The sources must agree on record, student, section and term links where present, current/removal status and whether recorded history exists.

The existing ledgers replay each discovered stream and verify its current projection and retained version references. Registration checks also verify its retained decision indexes. Transfers must have a matching award and credit; a void remains a current historical record with no restored prior grade. Current catalog labels are not used as immutable academic evidence.

A valid early drop has retained academic and registration history but no current credit or enrollment projection. It is checked internally and omitted from current work and the visible count. The API returns only a status and current-record count, or a safe error. It never returns early-removal identifiers, removed-record counts, raw events, actors or reasons. Internal snapshots redact JSON and debug serialization.

## Bounds and consistency

Each source query uses existing student-leading indexes and fetches at most 201 routing rows. More than 200 distinct source records, including restricted removed sources, stops the check. A shared per-request replay budget permits at most 400 page reads and 2,000 returned events, with at most 20 events per page. Empty streams also spend a page allowance. Limits apply across all records, rather than resetting for every stream; no snapshot, page counter or replay result is cached across requests.

The collection runs under the existing student lock and transaction, with authorization before collection, after locking and before returning. It writes no events, receipts, baseline observations, repairs or saved audit results. A failed history read rolls back before the public failure result is returned. Internal callers joining another transaction must treat a failed collection as unusable and abort that operation; a nested transaction failure may make that outer transaction rollback-only.

An internal successful snapshot includes current curated facts and protected source/stream-version references. Its validity belongs to that transaction. A future evaluation must hold the same boundary while resolving retained requirements and policies; it must not reuse a returned snapshot in a later request as if the student were still locked.

## Coverage limits and next work

This checks **available surviving sources**, not absolute completeness of the historical archive. If both current rows and every student-routing reference for a stream are lost, a student-scoped query cannot discover that orphaned stream. Broader protected reconciliation and recovery must detect that case; ordinary verification does not scan the global event journal or certify that every historical record exists.

The separate [Credits & GPA review](student-credit-gpa-review.md) now consumes verified current inputs and the selected degree policies under one lock/budget. The complete degree evaluator is still unfinished: it needs remaining completion/transfer chronology, grading compatibility policy, allocation across overlapping requirements, approved exceptions and reproducible retained result boundaries. The [allocation core](degree-audit-allocation.md) and [repeat-credit/GPA core](degree-audit-calculations.md) remain distinct calculation components; the GPA core now has a bounded current-work review. Verification and that review do not present partial work as a completed degree audit.

Private POST endpoint: `/sis/students/{student}/programs/{assignment}/degree-requirements/academic-work/verify/`. Browser requests retain framework CSRF protection, private/no-store responses and debug suppression. No migration or event-schema change is required.
