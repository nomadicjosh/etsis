# Student credit and GPA review

Open **Students → the student → Student programs → Degree requirements → Credits & GPA**. The same action is available from **Academic work**. Choose **Refresh credits & GPA** after a registration or grade correction. **Back to previous screen** returns to the degree or academic-work review you came from.

The review loads only when requested. It uses the student's selected degree publication, including that publication's repeat-credit, repeat-GPA, transfer-GPA and grading references. It does not use today's degree draft, latest published degree or current installation settings. Select published requirements for the pursuit first if no selection exists.

## Reading the totals

- **Earned credits** applies the retained repeat-credit policy to eligible current awards. A course shared across several requirement groups still creates only its actual earned credit. Waivers create no earned credit.
- **GPA** applies the retained repeat-GPA and transfer-GPA choices. It uses attempted credits and retained weighted quality points. A valid GPA of zero, such as an F-only record, is displayed as zero. With no eligible GPA credits it displays **Not applicable**, rather than zero. Missing or conflicting required facts display **Undetermined**, with no partial numerator, denominator or GPA.
- **Institutional-origin credits** shows the institutional portion of eligible earned work. A range preserves alternatives when equal credit could come from institutional or transfer work. It does not establish that a final residency policy is satisfied.

**Policies used for this review** shows the retained choices. **What needs review** explains unavailable grades, different or unknown grading publications, missing repeat chronology/order, ties with different GPA weights or calculation limits. Grades from different publications are not combined through an assumed conversion, even when their displayed point ranges match.

**How current records contribute** explains each current record's credit and GPA treatment. It displays 20 contribution cards at a time from the same bounded result. Next/previous contribution pages do not rerun the calculation. Refresh requests a new current result; results are not saved or cached in browser storage.

## Historical boundaries

This is a current-work review under the pursuit's retained policies. The available current institutional and transfer records form the supplied work set; program-specific exclusions and final residency rules remain future evaluation work. A new grade correction changes a refreshed review. A later degree draft, degree publication or grading publication does not replace the previously selected policy context. If the matriculation cohort changed, a notice asks the registrar to review the retained selection.

Earlier unknown history is identified. Known current facts can still be inspected; a required missing grade blocks its affected total instead of treating it as a zero contribution. This does not reconstruct earlier cumulative totals, certify a complete archive, issue a transcript, change standing, allocate courses to groups, apply minimum reductions or establish graduation eligibility. No academic/financial record or retained evaluation is written.

The degree builder now retains [repeat-attempt ordering](repeat-attempt-ordering.md). New blank drafts default to academic term/instructional period; earlier missing choices stay unknown. The review uses the exact retained section publication’s instructional start date for competing institutional attempts, or actual retained completion dates when that policy was selected. Missing dates or policies leave GPA undetermined. It never substitutes ULIDs, grading timestamps, live term dates or current defaults. [Institutional completion dates](academic-completion-dates.md) can now be explicitly reviewed and recorded; transfer academic-period evidence remains open.

## Privacy, consistency and limits

Access requires the signed-in student owner or `sis:student:read`, and the program assignment must belong to that student. The review holds the student lock while checking the selected requirement stream, retained degree/program policy envelope and [academic input verification](academic-input-verification.md). Course-group and course publication histories are not resolved for these overall totals because they are not used by this calculation. Required section histories are resolved once per distinct section only when competing GPA attempts need retained academic-period dates.

One request shares a limit of 400 history pages and 2,000 events across those reads. Academic discovery is also limited to 200 distinct sources. The API cannot accept larger budgets or alternate policies. A work-limit result or inconsistent source/history returns no partial calculation or contribution list. Failed nested reads abort the surrounding read transaction before the controlled result is returned.

Valid early-drop references are checked internally. Removed records, their identifiers and their counts do not appear in this review or API. The count covers current records, including in-progress work, late drops and voided awards. The public response exposes curated record labels/dispositions, aggregate values and safe policy context; it excludes raw events, actors, reasons and internal removed-source references.

The private POST endpoint is `/sis/students/{student}/programs/{assignment}/degree-requirements/academic-work/calculations/`. It retains framework CSRF protection, private/no-store responses and debug suppression. This change needs no migration, event-shape conversion or new event table.

See the [calculation core](degree-audit-calculations.md) for exact repeat, GPA and arithmetic rules, and the [roadmap](development-roadmap.md) for allocation, institutional policy/chronology integration, reproducible retained evaluations and graduation decisions still to be completed.

## General degree minimums and distinct GPA scopes

Use [Degree progress](student-degree-progress.md) for the combined general-minimum and group-placement review. It shows institutional-only cumulative GPA separately from policy-controlled overall GPA, approved threshold reductions and unresolved resident-credit bounds. The standalone Credits & GPA screen continues showing its existing overall-GPA calculation.
