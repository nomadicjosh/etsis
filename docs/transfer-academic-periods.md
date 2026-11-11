# Sending-institution academic periods

Open **Transfer workbench → an equivalency → Review award → Review source academic period**. The award is also available from the student's transfer credit record. This action requires both `sis:transfer:manage` and `sis:student:read`.

Enter the sending institution's actual academic-period start for this particular course attempt, together with a reason explaining the source evidence. Review the previous/proposed date and confirm. Leave the date blank only to explicitly change a known start to **Unknown**. An unchanged date, invalid date, future start or start after the external completion date is rejected.

Use evidence from the sending institution. Do not infer this date from the local posting term, equivalency effective dates, a grade-posting timestamp or the completion date itself. A one-day instructional period can legitimately start on the completion date, but only explicit source evidence establishes that fact.

## Three different facts

| Fact | Meaning |
| --- | --- |
| Source academic-period start | When this course's sending-institution instructional period began. This is the new reviewed fact. |
| External completion date | The source completion date already retained with the award. This workflow does not change it. |
| Local posting term | Where the receiving institution posted the accepted credit. Its dates do not establish the sending institution's chronology. |

A source-period review does not change awarded credits, grade, quality points, equivalency or posting term. It expires after 20 minutes. An intervening credit, grade, void/reinstatement or source-period decision requires a new review. **Revise source period** preserves entered fields; a rejected review leaves the form available for correction.

The action is available for an active award with recorded transfer history. A voided award must first be reinstated through its existing authorized workflow. An older untracked award needs its current facts established through the existing reviewed correction workflow before source-period capture; this feature never fabricates its original award or period. Source history remains inspectable when earlier facts are unknown.

## Repeat-GPA ordering

The selected degree's **Academic term / instructional period** ordering now uses the retained source-period start for GPA-eligible transfer attempts. Institutional attempts continue using the exact section publication's retained instructional start. Transfer completion dates and local posting terms are not substituted for missing period evidence.

Missing required chronology leaves the latest-attempt comparison undetermined. Recording a supported source period can resolve that comparison; a reasoned correction can change which eligible attempt is latest. Explicitly clearing necessary evidence makes it unknown again. Transfer exclusion, grading-publication compatibility and other retained policies still apply. A source date alone does not make a grade GPA-eligible, establish course equivalence or complete a degree audit.

Completion-date ordering continues using actual completion dates. Grade/credit amendments, voids and reinstatements preserve the source-period fact; voided awards still contribute no GPA or earned credit. Earned-credit repeat policy remains independent from GPA ordering. Refresh a current review to see the corrected facts; an earlier returned result or issued document is not mutated.

The academic-work view displays **Sending period began** when known. Credits & GPA identifies a transfer ordering date as **Retained source period start**, distinct from a retained institutional instructional start.

## Source period history

**Source period history** shows bounded, frozen journal pages. Award establishment and credit/grade corrections appear as context with **No period change**. Explicit period decisions show the prior and proposed dates, effective time and recorded time. Other decisions preserve the previously retained period; a contextual row with no period change does not claim the date became unknown.

Earlier observations and untracked sources stay explicitly unknown. Pages omit protected reasons, actor identities, review tokens, raw events and academic payloads. Next/previous navigation reuses a cursor whose captured head excludes later appends. Existing **Correction history** remains the separate view for credit/grade/void decisions and their protected receipts.

## Storage, concurrency and access

`TransferAcademicPeriodWasRecorded` appends to the existing `transfer_credit` stream in the single protected journal. It retains decision lineage and prior/proposed nullable period dates. Trusted actor, reason, correlation and effective time remain in the encrypted event context. A dated fact takes effect at the supplied period start in the configured institutional timezone; an explicit clear takes effect at the trusted review time. Journal recording time remains the trusted confirmation time.

Student/award locks, expected stream versions and the existing 400-page/2,000-event replay allowance bound each operation. A successful decision increments the existing academic credit revision without changing its values, invalidating stale credit/grade reviews. Event, projection, required audit and operation receipt commit together or roll back. An original retry returns its original receipt after later corrections, voids or clears, including after review expiry; it never reapplies an earlier date.

Browser mutations retain CSRF protection, private/no-store responses and debug suppression. Permission and actor are rechecked after locks and before returning. Forms keep review tokens in memory, with no browser storage. The date comes from the award stream already verified for current academic inputs; it adds no chronology lookup, calendar query or cross-request cache to ordinary work/calculation reads.

No migration, event conversion, historical backfill or parallel event table is needed. Earlier event shapes remain unchanged and earlier source periods remain unknown. The configured application database was unreachable during implementation; no live rows were changed. Isolated fictional-data tests verify replay, ordering, scope, rollback, retries and history privacy. Accepted-course publication/equivalence capture, broader grading compatibility and retained final evaluations remain separate roadmap work.
