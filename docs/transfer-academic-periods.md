# Transfer academic details

Open **Transfers → Equivalencies → Review award → Academic details**, or open the connected award from the student's academic record. One registrar review covers the receiving institution's **Posting term**, the sending institution's actual **External completion date** and optional **Source period start**. It requires both `sis:transfer:manage` and `sis:student:read`.

## Review and confirm

Enter the dates supported by the sending institution's evidence, an effective date and a reason. The period start must be on or before completion; neither source date can be in the future. Completion is required. A blank period start explicitly marks that fact **Unknown**. The correction's effective date defaults to today and must be between the proposed completion date and today. Change at least one of these three facts; an unchanged review is rejected.

**Review details** shows the earlier and proposed posting term and dates, the effective date and reason. **Revise details** preserves the draft. **Refresh details** reloads the award's current revision while preserving entered values, so staff can review them against an intervening change. Reviews expire after 20 minutes. A competing award/date/course decision requires a fresh review. An interrupted confirmation retries the same protected proof; a previously accepted confirmation returns its original receipt without reapplying old dates.

Credits, grade, quality points, accepted course publication, program pursuit and charges stay unchanged. The posting term changes only when the registrar explicitly selects a different term. A corrected source completion date can affect repeat-course comparisons; it does not silently recalculate the original equivalency or re-award credits under today's rules. The registrar must review the institution's award policy when correcting that date. Receiving-course changes use the connected [accepted-course review](transfer-course-publications.md#review-an-existing-awards-accepted-course), preserving these date and placement facts.

A voided award must first be reinstated. For an older untracked award, preview changes nothing. Confirmation observes its current facts at the actual confirmation time, then records the real date decision atomically. Earlier decision times, actors and source periods remain unknown; this does not invent an original award event.

## Understand the dates

| Date | Meaning |
| --- | --- |
| Source period start | When this particular external course attempt's instructional period began. Optional, supported by sending-institution evidence. |
| External completion | When that external course attempt actually finished. Required; distinct from the local term. |
| Effective date | When this correction applies under institutional policy. It does not establish when staff first knew the fact. |
| Recorded time | When etSIS durably recorded the accepted decision. Assigned by the server. |
| Local posting term | Where the receiving institution posts the credit. Select an existing term; its registration window does not determine whether the transfer can be posted there. |

Do not infer source dates from a posting timestamp, today's local calendar, equivalency dates or another attempt. A one-day source period may start on its completion date, but only explicit evidence establishes that fact.

## Academic calculations and documents

The selected degree's **Academic term / instructional period** repeat policy uses the verified source period start for eligible transfer attempts. Institutional attempts use their retained instructional start. **Completion date** ordering uses actual verified completion dates. Neither policy substitutes a local posting term for missing source evidence. Missing required evidence leaves that comparison undetermined.

Credit/grade amendments, voids and reinstatements preserve corrected chronology and posting placement. Voided awards contribute no current earned credit or GPA. Dates alone do not establish GPA eligibility, compatible grading policies or course equivalence. Earned-credit repeat policy remains independent from GPA ordering.

Each accepted academic-details decision increments the existing academic credit revision. Earlier transcript preparation reviews must be refreshed; prepared documents require the existing current-record checks before delivery. Previously issued document bytes and earlier returned results are not rewritten.

## Academic history and storage

**More → Academic history** provides bounded, frozen pages of before/after posting-term labels and source dates, effective time and recorded time. Other award/credit/course decisions appear for context with no date change. Protected reasons, actor identities, tokens, raw events and academic payloads are omitted. **Correction history** remains the credit/grade/void receipt view; it is not a second event store.

Date-only corrections use `TransferAcademicDatesWereRecorded` in the existing `transfer_credit` stream in `sis_history_events`. The protected payload retains decision lineage and both earlier/proposed dates; trusted actor, reason and effective date remain in encrypted context. A posting-term correction uses `TransferPostingTermWasChanged` in that same stream, retaining the earlier/new term IDs and captured codes, plus the source dates reviewed in the same confirmation. A combined correction is one decision and one credit revision. A term-only correction preserves source dates, so it cannot manufacture instructional chronology from the local calendar. Existing `TransferAcademicPeriodWasRecorded` events replay unchanged. Earlier accepted period proofs can return their verified original receipt; an unaccepted earlier proof must be reviewed through the current editor.

Migration `20261118000004` adds nullable `sis_transfer_awards.corrected_completed_on`. The existing `completed_on` column remains the original award request identity. The effective current completion is `COALESCE(corrected_completed_on, completed_on)`, checked against the selected stream. This preserves retries of an original award request after a correction. Another award for the same student/equivalency cannot reuse either an existing original date or its current corrected date, including a voided award. Dates from superseded corrections remain historical facts rather than permanently reserving every past typo.

Student/award locks serialize decisions; the selected replacement term is locked and its reviewed definition checked through the required audit. Changed term setup requires a fresh review. Expected stream/credit versions prevent lost updates. Coupled observations, events, projections, required audit and receipts commit together or roll back. Actor/authority and expiry are checked through confirmation. Selected verification shares the existing 400-page/2,000-event replay allowance; this is not a global inventory/rebuild certification. The existing student/equivalency index narrows duplicate checks without scanning all awards.

Browser mutations retain CSRF protection, private/no-store responses and debug suppression. Review proofs remain in memory, with no browser storage. Working lists use the current date projection; consequential selected reads verify the stream. No event conversion, historical backfill, new journal or additional source-date stream is introduced.

Fictional-data integration tests cover combined corrections, original retries, duplicate prevention, rollback, selected projection drift, curated history, preserved earlier receipts, verified degree inputs and stale transcript preparation. Two focused, single-worker browser checks also passed: the shared review/confirmation flow at narrow width and combined term/completion correction, with automated WCAG-tagged accessibility scans of the editor and confirmation. Broader manual accessibility review and operational rebuild certification remain separate checks.

Migration `20261118000004` was previously applied for corrected completion dates. Posting-term corrections require no further schema change: the existing award and academic-credit `term_id` projections change atomically, while the opening event retains the original request's term. A repeated initial request must still name that original term and returns the same active credit without moving the corrected placement. Later reference-label changes do not rewrite the captured posting-term label.

The configured application database was inspected before this extension: migration `20261118000004`, 20 recorded events, four terms and no transfer awards/streams. No live rows were changed for this extension. Shared `TransferAcademicDetailsService` replaces the earlier period-only service rather than adding another correction workflow. Accepted date/period proofs retain their original event and receipt contracts. The connected accepted-course review also shares bounded observation replay and now rechecks expiry after its required audit.
