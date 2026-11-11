# Student degree progress review

Open **Students → the student → Student programs → Degree requirements → Degree progress**. This on-demand review brings the general degree minimums and proposed course-group placement into one workspace. Select a published degree for the pursuit first if none is assigned.

All comparisons use the same verified current-work set, selected publication and applicable approved exceptions within one student lock and read transaction. This is a current review, not a saved evaluation, complete degree audit, academic-standing determination or graduation decision.

## General minimums

The review compares four effective thresholds with the retained calculations:

| Minimum | Facts used |
| --- | --- |
| Total earned credits | Eligible institutional and transfer awards after the retained repeat-credit policy. Group sharing and waivers create no additional earned credit. |
| Resident credits | Institutional-origin earned-credit bounds from that same award calculation. A tied institutional/transfer award remains a range instead of choosing whichever origin would satisfy the minimum. Additional residency exclusions are outside this check. |
| Cumulative GPA | Institutional work only. Transfers are excluded **before** applying the retained repeat-GPA policy. |
| Overall GPA | The retained repeat-GPA and transfer-GPA choices, including only accepted transfer grades explicitly eligible for GPA when inclusion is selected. |

These scopes follow the [degree-audit specification](degree-audit-plan.md). They do not substitute a new policy for the student's selected publication. An unknown overall transfer-GPA choice can prevent overall GPA while a supported institutional-only GPA remains available. Unknown transfer grades can also prevent a complete earned-credit total without erasing independently known cumulative GPA.

GPA uses retained attempted credits and weighted quality points. It requires the applicable grading-publication context; matching display maxima or today's 4.0 default never establishes compatibility. A positive GPA denominator with zero quality points is a defined GPA of zero. No eligible GPA credits means GPA is not yet established. Missing/conflicting inputs remain unavailable, with no partial GPA or substituted zero.

GPA is compared at the supported three-decimal threshold precision without rounding upward. For example, 2.999999 does not meet a 3.000 minimum.

## Understanding the cards

- **Minimum met:** the supported known value meets the effective threshold. For a resident-credit range, its lower bound must meet the minimum.
- **Below minimum:** the supported known value is below the threshold. For a resident-credit range, even its upper bound is below the minimum.
- **Needs review:** required facts are unavailable or a resident-credit interval spans the threshold. A required GPA with no denominator instead displays **GPA not yet established** and explains why.
- **No minimum required:** the threshold is blank or zero. This creates no extra condition requiring a GPA denominator and never displays an undefined GPA as zero.

An approved reduction shows the effective minimum and the original **published minimum** from the selected version. A later draft or publication cannot replace those displayed facts. Minimum exceptions remain specific to the approved degree version and pursuit; publishing another degree does not silently copy an exception.

Minimum checks are independent components. A known cumulative GPA can be displayed alongside an unavailable overall GPA or earned-credit total. There is no aggregate “eligible to graduate” result. Meeting these minimums does not establish course-group, specialization, residency-policy or final award completion.

## Inspecting the same result

**Inspect credit and GPA contributions** shows each current record's earned-credit, cumulative-GPA and overall-GPA treatment. Transfer exclusion in the cumulative column describes the institutional-only scope; it does not claim that the institution's overall transfer policy excludes transfers. Contribution cards page 20 records at a time.

**Inspect course-group placement** opens the [existing placement review](student-course-placement.md) from this same captured result. Staff can inspect group quantities, retained named-course lists, waivers, approved substitutions, grade floors, repeat/sharing treatment and equivalent evidence alternatives. Returning to degree progress does not rerun the read.

The placement review also [explains clear shortfalls](student-course-placement.md#understanding-shortfalls) in eligible credits, distinct-course quantities and named/choose evidence. These optimistic evidence bounds do not assign partial placements or declare groups complete. Missing/conflicting inputs remain needs-review outcomes.

**Refresh degree progress** requests a fresh review after registration, grade or approval changes. A grade correction changes refreshed current calculations; it does not mutate a previously returned snapshot. The snapshots are not retained audit evidence and must not be reused for a later command as if the student were still locked.

Earlier unknown history and a changed matriculation cohort have explicit notices. The review checks available surviving sources, not absolute completeness of the historical archive. Missing historical equivalence, unsupported group rules and incomplete search results remain visible. A group search limit can leave independent minimum comparisons available, while returning no speculative group placement.

## Consistency, bounds and privacy

The workflow verifies owner or `sis:student:read` access and the student-program scope before collection and before returning. Selected requirements, approved exceptions, academic inputs and required chronology share the existing 400-page/2,000-event replay allowance. Academic input discovery remains capped at 200 distinct sources; placement preparation/search retain their existing fixed bounds. The two GPA scopes use the same already-resolved attempts, so they add no second academic discovery or chronology replay.

A read failure or preparation/replay limit returns no partial minimum cards, contribution records or group plan. A corrupt/mismatched source produces a safe registrar-review error after the read transaction aborts. Requests cannot supply alternate minimums, GPA scopes, selected policies or larger work budgets.

Browser requests retain CSRF protection, private/no-store responses and debug suppression. Early-drop references are checked internally and omitted from current counts, contribution records and returned placement evidence. Raw events, actors, reasons and internal source references are not returned. Drilldowns use memory in the open review, with no browser storage or persistent result cache.

Private POST endpoint: `/sis/students/{student}/programs/{assignment}/degree-requirements/progress/`.

No migration, stored policy/event conversion, new event table, academic write or retained evaluation is introduced. Full historical equivalence/chronology coverage, specialization/exclusion rules, controlled retained evaluations and graduation decisions remain on the [roadmap](development-roadmap.md).
