# Actual institutional completion dates

Open **Students → the student → View transcript → Review final grade → Review actual completion date**. The same grade record is available through **My teaching**. The date action requires both `sis:gradebook:amend` and `sis:student:read`; being the section's instructor or having ordinary grade-entry access is insufficient.

Enter the date this particular attempt actually completed and a reason. Review the original and proposed dates, then confirm. A blank date explicitly changes a previously known date to **Unknown**. An unchanged date is rejected. The review expires after 20 minutes; a changed grade or another completion decision requires a fresh review.

This applies to a verified posted grade on an active institutional academic record, including a non-passing attempt. Entering a date cannot turn an ungraded enrollment into completed work, award credits or alter charges. Older records without a verified posted-grade stream must first use the existing grade-history review; this workflow does not fabricate their original completion decision.

## Dates have different meanings

- **Actual completion date:** explicitly confirmed source evidence for this student attempt. It may precede administrative entry into the system. It must be a real date no later than today.
- **Instructional end date:** the offering's retained schedule. It is not automatically a student's actual completion date.
- **Recorded time:** the trusted journal time when confirmation was accepted. Posting or correcting a grade later does not move the actual completion date.
- **Effective time:** a dated completion fact takes effect at the supplied completion date in the configured institutional timezone. Explicitly clearing the date takes effect at the trusted review time. Both the prior decision and its original effective/recorded context remain retained.

Grade amendments preserve the known completion date. Correct or clear it separately when its source evidence changes. The system never fills it from today's calendar, a section's end date, an old grade-posting timestamp or a current-row observation.

## Audit and history behavior

The selected degree's retained **Actual completion date** repeat-ordering policy now consumes this date in both Credits & GPA and combined Degree progress. An older attempt whose grade is corrected later stays earlier if its actual completion date stays earlier. A reasoned date correction can change which competing attempt is latest; clearing required chronology leaves that comparison unknown rather than selecting a winner.

Academic-period ordering continues using the exact retained instructional start date. Recording actual completion does not replace that policy, change the student's selected degree publication or confer graduation eligibility. Earned-credit policy remains independent from repeat-GPA ordering.

The academic-work view displays the retained completion date. Curated grade history shows completion-date decisions and their prior/proposed dates with effective and recorded timestamps. It omits protected reasons and actors. Previously returned current reviews and issued documents are not mutated; refresh a current calculation to review the new facts.

## Storage and privacy

`AcademicCompletionWasRecorded` appends to the existing `academic_credit` stream in the single protected journal. Its payload retains the previous completion date, proposed nullable date and decision lineage; actor, reason, effective time and correlation remain in the protected event context. A successful decision increments the existing academic projection version without changing the grade, credit values or money. Grade and date reviews therefore share stale-record protection.

Student and section locks, expected stream versions, the existing 400-page/2,000-event replay allowance, actor/permission rechecks, atomic projection/audit/receipt writes and original retry behavior apply. An original confirmation retried after another correction or an explicit clear returns its original receipt without replacing later facts, including after its review expires. Failed new decisions roll back their event and receipt as well as their projection.

Browser actions retain CSRF, private/no-store responses and debug suppression. Tokens remain in the open form's memory; no date, reason or review payload enters browser storage. Ordinary work reads already replay the academic stream, so exposing the retained date adds no stream lookup or calendar query.

No table migration, event conversion, parallel event table or historical backfill is required. Earlier events retain their exact formats and unknown completion dates. The configured application's database was unreachable during development; no live rows were changed, and verification used isolated fictional-data fixtures. Transfer awards keep their existing source completion dates; separate transfer academic-period capture remains unfinished.
