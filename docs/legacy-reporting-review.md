# Legacy SELECT reporting acceptance review

This review uses the actual examples in `legacy/docs/sql-query`, the saved-query/SQL routers and module, the section-roster/transfer routers, and the reporting views in the legacy migrations. The legacy feature allowed SELECT reporting across related tables; it was substantially more capable than the original fixed student-list report. Read-only access must preserve analytical power. This document is an acceptance plan, not a claim that the replacement builder is complete.

| Legacy source | Report and relationships | Required builder capabilities |
| --- | --- | --- |
| `sql-query/final-grade-report.md` | Academic credits + person + course section, scoped to a term | Multiple joins, selectable/aliased columns, readable concatenated names, grade access controls |
| `sql-query/less-than-12-report.md` | Enrollment + section + assigned faculty, grouped by section/term | COUNT/DISTINCT, GROUP BY, HAVING, enrollment status sets; retain zero-enrollment sections when requested |
| `sql-query/student-load.md` | Student term load + person | Term/program filters, summed credits and institution-defined load classifications |
| `sql-query/understanding-joins.md` | Students + people + registrations, with an anti-self-join to find each student's latest term | INNER/LEFT joins, aliases of the same relation, latest-row/anti-join/NOT EXISTS patterns; optional relationships; chronological term ordering |
| `sql-query/student-account-balance.md` and `financial.module.php::past_balance` | Student/term account + separately grouped tuition, fees, payments, person and latest-payment data | Derived aggregate datasets, multiple join keys, SUM/COALESCE, arithmetic, latest-payment selection and HAVING; account/currency grain and finance permission |
| `sql-query/mail-merge.md` | Person + accepted application + address, filtered by application dates | Several joins, date ranges, output-column aliases/order and permissioned contact data; exporting a report does not send mail |
| `sql-query/email-active-students.md` | Person + active student | Relationship filters, typed status choices, permissioned contact columns; selecting recipients does not authorize sending |
| `sql-query/graduating-students.md` | Eligible active student program assignments | Program-assignment grain, rather than assuming one program per student; future degree-audit/registrar decision integration |
| `sql-query/student-locator.md` | Enrollments + section + meeting time/location | Authorized academic/scheduling joins, effective occurrences and time ranges; online and hybrid modes must not require a room or expose private meeting links |
| `sql-query/batch-registration.md` | Students in a selected active section or explicit student set | IN/NOT IN, status sets, saved population selection and explicit reviewed action hand-off |
| `sql-query/using-case.md`, `functions.md`, `concatenation.md`, `select-distinct.md` | Readable status descriptions, labels, counts, sums and distinct values | Typed CASE, arithmetic, concatenation, NULL-aware functions, aggregate expressions and DISTINCT |
| `20160916002812_initial_schema.php::v_sacp` and later view revisions | Student program + catalog year + program/degree/department metadata + registration history | Several chained relationships, curriculum cohort identity independent of current catalog publication, stable versioned metadata |
| `section.router.php` roster SELECT; `transfer.router.php` equivalency SELECT | Enrollment/program/person/section rosters; internal course + external course + dated equivalency | Correct join paths and row grain, optional relationships, parameterized search, date-effective filters |

## Required reporting architecture

1. Store a versioned, typed report definition with root dataset, relationship aliases, INNER/LEFT join selection, output expressions/labels, predicates, grouping, HAVING and deterministic sorting. jQuery QueryBuilder handles predicate editing; relationship, expression and grouping editors are additional controls, rather than pretending a WHERE-condition widget is the whole reporting system.
2. Expose a discoverable relationship/field registry spanning authorized domains, including student program catalog bindings. Authorize every joined or referenced dataset and field, including fields used only in filters, expressions or sorting. A report share must not grant access to the underlying academic, finance, HR, contact or private case data.
3. Compile a SELECT execution plan with bound literals and repository-owned identifiers through the supported database dialects. A read-only query is not inherently privacy-safe or resource-bounded. Keep credentials, encrypted notes/documents, raw event payloads and private communication content outside general reporting. Domain-specific read models provide legitimate reportable information instead.
4. Handle one-to-many relationships deliberately. Joining several fact tables can multiply tuition, fees or student counts. Provide aggregates at a declared grain (for example student + account + currency + term) before combining datasets, plus EXISTS/NOT EXISTS filters where row multiplication is unwanted.
5. Preserve NULL/zero distinctions, compound predicates, date ranges, aliases, selected fields, calculated columns, SUM/COUNT/MIN/MAX, DISTINCT, grouping/HAVING and latest-row patterns. Normalize RIGHT JOIN use cases by changing the root and using LEFT JOIN so institutions get equivalent results across supported databases.
6. Define stable pagination for detailed and grouped output, bounded previews and exports, configurable execution/resource limits, cancellation and actionable validation. Never silently truncate a report and present it as complete. Validate grouping explicitly; do not reproduce permissive MySQL queries that return arbitrary ungrouped columns.
7. Recheck execution and export permissions each time; retain private/no-store responses, CSRF for browser mutations, debug suppression, formula-safe exports and metadata-only audit. Shared report definitions are distinct from saved output snapshots and from permission to perform a batch action.

## Acceptance scenarios to carry forward

- A 2024 matriculant remains in the 2024 program catalog cohort after the 2025 catalog is published, and reports can select the student's assigned catalog independently of the latest published catalog.
- A section headcount report includes zero-enrollment sections and supports an institution-selected HAVING threshold; faculty and room are optional relationships.
- A student with several programs appears once in a person-grain selection, or once per assignment in an explicitly selected program-grain report.
- Two fee rows and three payment rows do not produce six-times-inflated balances; mixed currencies are never added into an unlabeled total.
- Latest registration uses institutional term chronology, not a lexical comparison of arbitrary term codes.
- A recipient of a shared academic/financial report who lacks the relevant source permissions is denied before query execution, even when protected fields appear only in a filter.
- A report may feed a reviewed registration population without changing any rows until the separate authorized batch confirmation.

The old examples also include SSN-derived PIN functions, raw SQL logging and name-based sharing. Those are not requirements to reproduce: they conflict with the current privacy and identity design. Analytical functions and joining power remain requirements; sensitive source access and operational actions need their own explicit permissions.

## Current implementation stage

Report Studio implements the first three dataset families: student program/catalog assignments, academic credit/final-grade snapshots, and sections/enrollments. Staff can select chained INNER/LEFT relationships, calculated numeric columns, readable names/statuses, nested conditions, related-row join conditions, grouping, summary conditions, distinct output and ordering. Text/date MIN/MAX retain their types when filtering summarized output. AJAX previews pair exact-value tables with charts, with versioned encrypted saves, existing account-based sharing and formula-safe CSV exports of the current page. Source permissions are required for every reference and ancestor relationship, including filter-only paths.

The remaining acceptance work above includes more domain sources, derived financial aggregates, latest-row/anti-join editors, repeated aliases, general expression trees and reviewed population hand-offs. The current UI is an initial working stage, not a full replacement for every legacy SELECT. See [backend-workspace.md](backend-workspace.md#relational-report-studio) for setup and limits.
