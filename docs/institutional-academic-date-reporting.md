# Institutional academic dates in student reports

The existing **Academic credits & final grades** source now includes **Institutional academic dates**. The same relationship is available under **Recorded academic credits** in student and authorized application reports. These are additional fields in the existing report builder, not new report types.

| Field | Meaning |
| --- | --- |
| Recorded institutional completion date | The currently accepted actual academic completion date, explicitly recorded or corrected through the academic-date workflow. |
| Recorded institutional source start date | The currently accepted explicit source-period date recorded through that workflow. |

These dates describe the particular institutional credit attempt. A grade, credit quantity, term boundary, enrollment timestamp, CEU completion date or graduation does not supply either value. Clearing an accepted date makes it unknown again; the original decision and correction remain in protected history. Published offering chronology remains separately retained and protected by the existing academic-date correction rules. This relationship does not substitute an offering start date for an absent explicit source-period decision.

Older work without a recorded date displays an empty value. Work without protected institutional history also remains unknown: creating the projection never invents an establishment or date event. Transfer completion dates continue to use their separate approved transfer-award relationship. CEUs remain separate from academic transfer credits and these academic dates.

## Build a report

1. Open Report Studio and choose an existing academic-credit source, or add **Recorded academic credits** to a student/application report.
2. Add **Institutional academic dates** and select the date columns you need.
3. Use full-date filters, Day/Month/Year grouping, minimum/maximum dates or distinct academic-credit references with the existing column controls. Month values include their year. Unknown dates stay empty.
4. For the latest or earliest complete attempt, use the date selector on **Recorded academic credits**. The recorded institutional dates are available there even when they are not output columns. Equal-date choices, unmatched parents, candidate filters and independent named copies retain the existing whole-record selection semantics.

Dates require the existing student-data permission, including when used only in filters, calculations, matching rules, hidden selection dates or copied relationships. Application reports also retain their independent admissions permissions. The join verifies the exact credit, student, original enrollment reference and institutional origin; removed early attempts do not become current report rows.

Dynamic previews, saved reports, CSV pages and complete background exports use the same read model and readiness check. Static results retain the values accepted when captured, including after a later date correction or live-index problem. Current source permissions still govern access to retained results. A report never changes academic or financial records.

## Installation and existing history

Migration `20261126000005_CreateSisAcademicDateIndex` creates one reconstructible read table, `sis_academic_date_index`. It does not backfill dates, create events, grant roles or modify academic rows. New institutional streams create their own index row with unknown dates and advance it in the same transaction as every academic event. Combined source-period/completion corrections advance both values atomically. Grades and other academic decisions advance the checkpoint without assigning dates.

Existing protected institutional streams need an operator reconciliation before their next new academic decision. If the known index is missing, behind the journal head or conflicts with the current source identity, a dependent report returns an administrator-facing reconciliation message instead of a partial result. Reports that do not depend on these dates remain available. Untouched older work without a protected stream/mapping/index remains an explicit unknown.

Reconciliation is an operator console workflow:

```sh
php codex sis:projections:academic-dates --credit=ACADEMIC_CREDIT_ULID --json
php codex sis:projections:academic-dates --credit=ACADEMIC_CREDIT_ULID --apply --json
```

Use the configured PHP interpreter. The first command is read-only, including when an index is missing or damaged. `needs_reconciliation` exits with status 3; `ready` exits with 0. Only `--apply` installs a verified replacement. Invalid input exits with 2, exhausted allowances or concurrent changes with 3, and unverifiable history/current records with 1. Output contains fixed statuses, opaque references and positions, never decrypted records or exception details. Terminal messages are ordinary console text without translation helpers.

Omit `--credit` to inspect just one stream in stable identifier order. If `next_after` is returned, pass it as `--after=ULID` on the next invocation. Do not combine `--credit` and `--after`. A scan and each application remain one-stream operations; earlier successful invocations are independent. The default complete replay allowance is 500 events, optionally increased with `--events=1..2000`; replay also has a 4 MiB decoded-byte allowance and a five-second budget. A limit never installs a partial index. Investigate a failed reference before advancing its scan cursor. The exact command is permitted during maintenance mode; abbreviations are not.

The reconciler replays the existing protected stream into an isolated academic aggregate, verifies its current academic projection and original stream mapping, then checks the protected head and current index again. Application serializes with journal writers and replaces only the date index. A changed source, failed verification or transaction failure rolls back the replacement. Missing or conflicting primary academic records require their own institutional resolution; this command does not repair them, recreate removed enrollments, post money, send notifications, change receipts or append events. Accepted original command retries retain their original results and never repair a missing index implicitly.

The index retains an encrypted authenticated checkpoint, verified before a new write and during operator reconciliation. Ordinary reports use indexed coverage/head/source checks and never decrypt or replay the journal. Those checks do not independently authenticate every selected date against its sealed checkpoint or certify an entire installation against direct database tampering. Independent integrity verification and broader projection-recovery acceptance remain separate roadmap work.
