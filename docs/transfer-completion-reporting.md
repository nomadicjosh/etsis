# Transfer completion dates in existing reports

The existing **Academic credits & final grades** source provides **Academic credit reference**, **Current transfer completion date** and **Original transfer completion date**. The dates are also available through **Recorded academic credits → Transfer completion dates** in the existing student and application sources, subject to their independent data permissions. This adds fields to Report Studio; it does not create another report type or template.

## Choose the date that answers the question

**Current transfer completion date** uses the accepted corrected date when one exists, otherwise the date recorded on the original transfer award. **Original transfer completion date** always reads that original award value. A later [transfer date correction](transfer-academic-periods.md) can change a fresh dynamic report without rewriting an earlier static capture or the original award date.

These are course-completion dates, not the receiving term's start/end, the day staff posted the award, a graduation date or a CEU completion date. No date is inferred from an academic term. The exact award, academic-credit reference, student and transfer origin must match. Missing/unmatched awards and institutional credit rows have unknown transfer dates; they do not borrow another record's date. A legacy award row can supply its recorded value without claiming that its original author or historical publication is known. Ordinary reports do not replay or certify protected event history.

The source covers the student's stored academic-credit rows across pursuits. It does not decide whether a receiving pursuit can use the work, approve transfer equivalency, award academic credit or alter GPA. Use the formal academic/transfer and degree-audit workflows for those decisions. CEUs remain separate from academic transfer credit.

## Summarize completions by month or year

Open **Reports → Report Studio** and choose the existing academic-credit source. Add a transfer completion date as an output column. In that column's settings, choose **Day**, **Month** or **Year** under **Group dates**. Months retain their year, so June 2025 and June 2026 remain separate.

Use **Academic credit reference → Distinct count** to count academic-credit records rather than distinct course codes. Repeated work can have different credit references. Unknown dates remain a separate null group unless an explicit **is not empty** or date-range filter excludes them. Grouping changes the displayed period; ordinary date filters still compare the original full date. **Minimum** and **Maximum** can summarize the selected date; they do not select the other fields from the same record.

Transfer dates use the existing canonical ISO-string storage. The compiler distinguishes it from native SQL DATE columns and uses portable calendar prefixes, without server date-style or timezone conversions. No stored date is converted during reporting.

## Select the latest or earliest complete record

In an existing student or application report, add **Recorded academic credits** and **Transfer completion dates**. Expand the academic-credit card's row-selection controls. Choose **Latest** or **Earliest**, then select either current or original transfer completion date. The result retains the whole selected academic-credit record, not unrelated minimum/maximum values from separate records.

Equal dates are kept by default. An explicit one-record tie option uses a stable credit reference; it does not establish which course the student completed first that day. A left join preserves students with no matching dated transfer work and shows unknown related values. An inner join intentionally excludes those students. Named copies can independently select, for example, the latest and earliest completed transfer records.

To exclude voided work before choosing the latest record, put the academic-status condition in the academic-credit card's **Advanced join filters**. An ordinary output filter runs after row selection and can remove the selected row; it does not search for the next qualifying record. The existing explanation and saved-definition controls retain this distinction.

## Save, share and export

The existing dynamic/static save, share, private CSV and complete background-export workflows carry these fields and selections. Dynamic runs use current accepted transfer dates. Static captures keep the values captured at preparation. Sharing a definition or output never grants the underlying student/admissions permissions; execution, metadata, exports and background workers retain their existing current-access checks.

Reporting writes no academic or financial event, changes no award and sends no message. [Institutional academic completion/source dates](institutional-academic-date-reporting.md) now use a separate event-derived read projection, with explicit unknowns and bounded operator reconciliation. Ordinary reports never scan the protected journal. Broader historical/as-of academic reporting, graduation facts and general expression-tree work remain separate roadmap items.
