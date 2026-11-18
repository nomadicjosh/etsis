# Transfer academic dates

Open **Transfers → Equivalencies → Review award → Academic dates**, or open the connected award from the student's academic record. One registrar review covers both the sending institution's actual **External completion date** and optional **Source period start**. It requires both `sis:transfer:manage` and `sis:student:read`.

## Review and confirm

Enter the dates supported by the sending institution's evidence, an effective date and a reason. The period start must be on or before completion; neither source date can be in the future. Completion is required. A blank period start explicitly marks that fact **Unknown**. The correction's effective date defaults to today and must be between the proposed completion date and today. An unchanged pair of source dates is rejected.

**Review dates** shows both earlier and proposed dates, the effective date and reason. **Revise dates** preserves the draft. **Refresh dates** reloads the award's current revision while preserving entered values, so staff can review them against an intervening change. Reviews expire after 20 minutes. A competing award/date/course decision requires a fresh review. An interrupted confirmation retries the same protected proof; a previously accepted confirmation returns its original receipt without reapplying old dates.

Credits, grade, quality points, accepted course publication, program pursuit, local posting term and charges stay unchanged. A corrected source completion date can affect repeat-course comparisons; it does not silently recalculate the original equivalency or re-award credits under today's rules. The registrar must review the institution's award policy when correcting that date. Replacing the receiving course identity or local posting term remains separate, unfinished work.

A voided award must first be reinstated. For an older untracked award, preview changes nothing. Confirmation observes its current facts at the actual confirmation time, then records the real date decision atomically. Earlier decision times, actors and source periods remain unknown; this does not invent an original award event.

## Understand the dates

| Date | Meaning |
| --- | --- |
| Source period start | When this particular external course attempt's instructional period began. Optional, supported by sending-institution evidence. |
| External completion | When that external course attempt actually finished. Required; distinct from the local term. |
| Effective date | When this correction applies under institutional policy. It does not establish when staff first knew the fact. |
| Recorded time | When etSIS durably recorded the accepted decision. Assigned by the server. |
| Local posting term | Where the receiving institution posts the credit. This workflow does not change it. |

Do not infer source dates from a posting timestamp, today's local calendar, equivalency dates or another attempt. A one-day source period may start on its completion date, but only explicit evidence establishes that fact.

## Academic calculations and documents

The selected degree's **Academic term / instructional period** repeat policy uses the verified source period start for eligible transfer attempts. Institutional attempts use their retained instructional start. **Completion date** ordering uses actual verified completion dates. Neither policy substitutes a local posting term for missing source evidence. Missing required evidence leaves that comparison undetermined.

Credit/grade amendments, voids and reinstatements preserve corrected chronology. Voided awards contribute no current earned credit or GPA. Dates alone do not establish GPA eligibility, compatible grading policies or course equivalence. Earned-credit repeat policy remains independent from GPA ordering.

Each accepted date decision increments the existing academic credit revision. Earlier transcript preparation reviews must be refreshed; prepared documents require the existing current-record checks before delivery. Previously issued document bytes and earlier returned results are not rewritten.

## Date history and storage

**More → Date history** provides bounded, frozen pages of before/after source dates, effective time and recorded time. Other award/credit/course decisions appear for context with no date change. Protected reasons, actor identities, tokens, raw events and academic payloads are omitted. **Correction history** remains the credit/grade/void receipt view; it is not a second event store.

`TransferAcademicDatesWereRecorded` appends to the existing `transfer_credit` stream in `sis_history_events`. The protected payload retains decision lineage and both earlier/proposed dates; trusted actor, reason and effective date remain in encrypted context. Existing `TransferAcademicPeriodWasRecorded` events replay unchanged. Earlier accepted period proofs can return their verified original receipt; an unaccepted earlier proof must be reviewed through the current editor.

Migration `20261118000004` adds nullable `sis_transfer_awards.corrected_completed_on`. The existing `completed_on` column remains the original award request identity. The effective current completion is `COALESCE(corrected_completed_on, completed_on)`, checked against the selected stream. This preserves retries of an original award request after a correction. Another award for the same student/equivalency cannot reuse either an existing original date or its current corrected date, including a voided award. Dates from superseded corrections remain historical facts rather than permanently reserving every past typo.

Student/award locks serialize decisions; expected stream/credit versions prevent lost updates. Coupled observations, events, projections, required audit and receipts commit together or roll back. Actor/authority and expiry are checked through confirmation. Selected verification shares the existing 400-page/2,000-event replay allowance; this is not a global inventory/rebuild certification. The existing student/equivalency index narrows duplicate checks without scanning all awards.

Browser mutations retain CSRF protection, private/no-store responses and debug suppression. Review proofs remain in memory, with no browser storage. Working lists use the current date projection; consequential selected reads verify the stream. No event conversion, historical backfill, new journal or additional source-date stream is introduced.

Fictional-data integration tests cover combined corrections, original retries, duplicate prevention, rollback, selected projection drift, curated history, preserved earlier receipts and stale transcript preparation. Migration SQL is compiled for the framework's five supported dialects and exercised on SQLite; compilation alone does not certify every PDO driver. Browser/accessibility review and broader operational rebuild certification remain separate checks.

The configured DDEV application database was inspected and migration `20261118000004` applied. All 20 existing event rows and the selected academic, profile and permission rows compared unchanged before/after; no existing dates were filled in.
