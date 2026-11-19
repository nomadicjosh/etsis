# Institutional completion and academic-period dates

Open **Students → the student → View transcript → Review final grade → Review academic dates**. The same grade record is available through **My teaching**. The date action requires both `sis:gradebook:amend` and `sis:student:read`; being the section's instructor or having ordinary grade-entry access is insufficient.

Enter the actual completion date and, for an older attempt without a retained offering, its source academic-period start. Both fields share one reason, **Review dates**, and **Confirm dates**. The student name and institutional ID remain visible. **Revise dates** preserves both entries and the reason. A blank editable date explicitly changes a known date to **Unknown**. At least one date must change; an unchanged field is preserved without adding an event. The review expires after 20 minutes; a changed grade or another completion decision requires a fresh review.

This applies to a verified posted grade on an active institutional academic record, including a non-passing attempt. Entering a date cannot turn an ungraded enrollment into completed work, award credits or alter charges. Older records without a verified posted-grade stream must first use the existing grade-history review; this workflow does not fabricate their original completion decision.

## Dates have different meanings

- **Actual completion date:** explicitly confirmed source evidence for this student attempt. It may precede administrative entry into the system. It must be a real date no later than today.
- **Instructional end date:** the offering's retained schedule. It is not automatically a student's actual completion date.
- **Recorded time:** the trusted journal time when confirmation was accepted. Posting or correcting a grade later does not move the actual completion date.
- **Effective time:** when completion changes, the coordinated correction uses the proposed completion date, or trusted review time if it is cleared. When only the source period changes, it uses that proposed start date, or review time when cleared. Both facts share the correction context in the institutional timezone; their distinct source dates remain explicit in their event payloads. Prior decisions keep their original context.

Grade amendments preserve the known completion date. Correct or clear it separately when its source evidence changes. The system never fills it from today's calendar, a section's end date, an old grade-posting timestamp or a current-row observation.

## Audit and history behavior

The selected degree's retained **Actual completion date** repeat-ordering policy now consumes this date in both Credits & GPA and combined Degree progress. An older attempt whose grade is corrected later stays earlier if its actual completion date stays earlier. A reasoned date correction can change which competing attempt is latest; clearing required chronology leaves that comparison unknown rather than selecting a winner.

Academic-period ordering uses the exact retained instructional start date when a published offering exists, or an explicitly reviewed source period for an older institutional record without that reference. Recording actual completion does not replace that policy, change the student's selected degree publication or confer graduation eligibility. Earned-credit policy remains independent from repeat-GPA ordering.

The academic-work view displays the retained completion date. Curated grade history shows completion-date decisions and their prior/proposed dates with effective and recorded timestamps. It omits protected reasons and actors. Previously returned current reviews and issued documents are not mutated; refresh a current calculation to review the new facts.

## Source academic-period start

For an older institutional attempt with no retained published offering, use the same **Review final grade → Review academic dates** screen. The action requires a verified posted grade, an active academic record, `sis:gradebook:amend` and `sis:student:read`. A known published offering keeps its own instructional dates; this action cannot replace them. Untracked records need verified academic history before a date decision can be made.

Enter the academic-period start supported by institutional source records, explain the evidence, review and confirm. **Revise dates** preserves the entered values. A blank date explicitly clears a previously known source date; it never substitutes the posting term, current term dates, registration time, grade-posting time or actual completion date. Future/invalid dates are rejected. When both facts are known, the source period cannot start after actual completion; completion corrections must preserve the same constraint.

A later correction or clear appends a new `InstitutionalAcademicPeriodWasRecorded` decision in the same academic-credit stream, retaining the previous date and decision lineage. Curated grade history shows prior/new dates and effective/recorded times, withholding reasons and actors. Grade corrections and course-publication acceptances preserve the date. The existing row revision protects competing grade, course and both date reviews. Original accepted retries keep their original result after later decisions and expiry.

The verified internal work projection exposes this explicit date. The shared repeat-ordering reader passes it into the existing policy evaluator without querying today's calendar or replaying another stream. Under retained **academic-period** ordering, recording or correcting the date can resolve a formerly unknown comparison or change the latest GPA attempt. Clearing needed chronology makes the comparison unknown again. Under **actual-completion** ordering, this source period cannot choose the winner; real completion facts remain necessary. Credit awards, grade definitions and charges do not change.

The combined screen uses the existing `AcademicDateService` and typed date kinds. It checks the proposed pair together, so staff can move an entire interval earlier or later without a temporary invalid-date error. Confirmation appends only changed facts, in an order that also keeps intermediate replay states valid. Two changed dates share one operation receipt and transaction; both events, their audits and the academic projection commit together. A failure leaves neither correction saved. Existing individual date commands use this same implementation, and their earlier accepted confirmations retain their original results.

`POST /sis/sections/{id}/students/{student}/academic-dates/review/` requires explicit nullable `academic_on` and `completed_on`, the enrollment ID, expected row version and reason. An omitted date is rejected rather than silently cleared. A retained offering's source date cannot be replaced; the browser omits that editable control and submits its existing value. The `/commit/` companion accepts only the protected combined review token. Tokens remain bound to actor, student, section and command; an individual-date proof cannot authorize a combined confirmation or vice versa.

## Storage and privacy

`AcademicCompletionWasRecorded` appends to the existing `academic_credit` stream in the single protected journal. Its payload retains the previous completion date, proposed nullable date and decision lineage; actor, reason, effective time and correlation remain in the protected event context. Each changed fact increments the existing academic projection version without changing the grade, credit values or money. A two-date confirmation therefore advances the academic row version twice and saves its final projection once. Grade and date reviews therefore share stale-record protection.

Student and section locks, expected stream versions, the existing 400-page/2,000-event replay allowance, actor/permission rechecks, atomic projection/audit/receipt writes and original retry behavior apply. An original confirmation retried after another correction or an explicit clear returns its original receipt without replacing later facts, including after its review expires. Failed new decisions roll back their event and receipt as well as their projection.

Browser actions retain CSRF, private/no-store responses and debug suppression. Tokens remain in the open form's memory; no date, reason or review payload enters browser storage. Ordinary work reads already replay the academic stream, so exposing the retained date adds no stream lookup or calendar query.

No table migration, event conversion, parallel event table or historical backfill is required. Earlier events retain their exact formats and unknown completion dates. This consolidation changes no live rows; verification uses isolated fictional-data fixtures, including original-result retries, expiry and stale-state rollback, a failure on the second audit, authority loss, protected offering dates, real provider/CSRF/privacy boundaries, mobile reflow and automated accessibility. Transfer awards keep their [combined academic-details workflow](transfer-academic-periods.md), which additionally handles the local posting term. Institutional enrollment and billing terms are unchanged by this date-only correction.
