# Reviewed catalog imports

**Import data** uses one mapping, reconciliation and confirmation workflow for courses and academic programs. Imports create working definitions or explicitly link existing identities; they never overwrite catalog definitions or publish academic requirements. All examples use fictional data.

## Choose and map a file

1. Choose **Courses** or **Academic programs** as the import destination. Changing the destination clears the selected file, mappings and row choices so an earlier review cannot be confirmed against another destination.
2. Choose a stable **Source name**. Source names are lowercase and contain up to eighty letters, digits, dots, underscores or hyphens. Keep the same source name for later imports from that source.
3. Upload a UTF-8 CSV with distinct column headers: up to 1 MB, forty columns and 1,000 data rows. Comma, semicolon and tab delimiters are available. **Sample CSV** downloads the example for the selected destination.
4. Choose **Match fields**. Course imports require four distinct source columns: **External ID**, **Course code**, **Course title** and **Credits**. Program imports require three: **External ID**, **Program code** and **Program title**. Program imports have no course-credit field. Mapping shows a source example for each selected column; unrelated source columns can be left out.

An external ID is a stable source-system identity, not a student/person institutional ID. Its original letter case is preserved. Duplicate IDs within a file, or existing source IDs differing only by case, require resolution. Course and program mappings are independent, so the same source/external ID can legitimately identify a course and a program without linking those entities together.

Course credits accept exact thousandths, such as `3`, `3.5` or `3.125`, without floating-point rounding. Program codes are normalized to uppercase and follow the ordinary program editor's format: up to eighty letters, digits, dots, underscores or hyphens. Program descriptions are optional plain text up to 10,000 characters; literal markup is text, not executable HTML.

## Optional details and dependencies

Expand **Add catalog classifications** for a course, or **Add program details** for a program. Map only the fields you intend to supply.

| Destination | Optional fields |
| --- | --- |
| Courses | Subject, department, academic level and course level codes. |
| Programs | Program description; department, academic level, award, school/college, field of study and campus/site codes. |

Each nonblank reference code must resolve to exactly one active entry in its own library. Codes are trimmed and normalized to uppercase; readable names appear in the review. The importer never guesses a subject from a course code, turns a school into a campus, creates missing references silently or assumes Undergraduate/Graduate exist in every installation. Optional destinations may deliberately share a source column.

A blank or unmapped reference remains unspecified for a new definition. When linking an existing record, only supplied reference codes must agree; omissions never clear its current assignments. An unmapped program description does not clear or replace the existing description. If description is mapped, its trimmed value—including an explicit blank—must match before linking. Descriptions/classifications on a conflicting existing program require a separate authorized catalog edit.

Missing, ambiguous or retired dependencies appear as actionable row conflicts. Authorized catalog staff can open the existing **Catalog setup** drawers directly from the review, including subjects, departments/levels, schools/fields, campuses/sites and awards where applicable. Closing a drawer preserves the file, mapping and choices. Choose **Review choices** afterward. Import permission does not itself grant catalog-maintenance authority.

Use the [course reference example](../public/assets/examples/courses-classifications.csv) or [program example](../public/assets/examples/programs.csv). The program example deliberately has blank reference codes so it does not assume any institution's libraries are already configured.

## Review, link and confirm

1. Choose **Review import**. The summary separates new definitions, matches needing approval, approved links, prior imports, excluded rows and conflicts. Reviewing creates no catalog record, mapping, receipt or business event.
2. A matching existing record requires **Link course** or **Link program** explicitly. Linking records only the source identity. Its title, description, credits, classifications, activity, publications and student references remain unchanged. Several matches are a conflict rather than an automatic guess.
3. Choose **Leave out** for unwanted/conflicting rows. **Include row** or **Undo link** changes the working choice. Existing data is never overwritten by a changed source row.
4. Choose **Review choices** after changing a link or exclusion. The current ready review is required for confirmation. Search, review filters and twenty-five-row pages keep a large file manageable; the first five original source rows are available separately.
5. Choose **Import courses** or **Import programs**. One transaction commits the entire reviewed set with its required audits, mappings and encrypted result receipt. A failure rolls the batch back instead of leaving partial catalog records.

New courses are inactive drafts. New programs have a working program definition and their explicitly reviewed references. Programs use the ordinary catalog model: their reference can appear in program choice/directory lists, as a manually created program does. Import does not publish an academic-year program revision, create degree requirements, assign application forms, matriculate anyone or move a student's cohort. Complete the normal [program publication](program-publication.md) and application-form setup before using a new program in an intake workflow. There is no separate importer-owned program activation state.

After completion, **Review course drafts** or **Review program details** opens the normal catalog workspace. **Import another file** starts a fresh working selection.

## Source identities, history and retries

A previously imported source row with the same original semantic content is skipped, even if its catalog record was edited later. A source ID that now identifies different content is a conflict, not an update instruction. An accepted confirmation returns its original result after expiry or later catalog changes, without reapplying the old file. Current import authority and the original actor, destination, source, file/mapping and row choices still must match. A program review cannot be reused as a course review.

Unconfirmed reviews expire after twenty minutes. Confirmation bulk-checks current catalog matches, locks selected catalog identities and dependencies in stable order, and rejects changed matches, classification labels/assignments, new code/source collisions and expired or unauthorized requests. Final checks verify newly written/linked records, source mappings and requested dependencies again after required audits. Changed created definitions, missing mappings, authority loss, expiry or audit failure roll everything back. Existing previously imported catalog facts are not silently repaired.

Working program/course setup and source reconciliation are ordinary authorized CRUD, not new academic events. Publications remain separate consequential decisions in the single protected journal. Imported drafts and source links do not rewrite earlier course/program publications, student program bindings, grades or financial records. Course fingerprints, mapping identities, proof purpose and accepted-result contracts retain their existing meanings through the shared catalog-import implementation.

`CatalogImportRow`, `CatalogCsvMapper`, the League CSV adapter and `MappedImportService` now serve both destinations. A server-owned enum selects allowed tables and identity fields; browser input cannot name a table or column. The existing course mappings remain in `sis_import_mappings`; program mappings use `sis_program_import_mappings` with a ULID, source/external identity, content hash and program foreign key. Both reuse the existing encrypted `sis_catalog_operations` receipts. These are reconciliation/operation support, not parallel domain event journals.

Migration `20261120000012_CreateSisProgramImportMappings` adds only the empty program-mapping table. It copies no current records into history, changes no existing mapping/receipt, seeds no classifications and grants no permissions. Its DDL compiles for the framework's MySQL, PostgreSQL, SQLite, SQL Server and Oracle dialects; native acceptance beyond the configured MariaDB database remains release qualification work.

## Access, privacy and limits

Preview and confirmation require `sis:import:manage`. Reference setup independently requires `sis:catalog:manage`; access to the ordinary program editor keeps its own catalog permission. These actions use the existing CSRF-protected `/sis/imports/preview/` and `/sis/imports/commit/` routes, private/no-store responses and suppressed debug collectors. The explicit `target` is `course` or `program`; existing course requests that omit it still mean courses. Null or arbitrary destinations are rejected.

The original CSV, preview and confirmation proof stay in page memory; they are not written to browser storage, logs, queued jobs or durable import artifacts. Audit entries contain metadata about the actor/action/record, not file contents. Source values are rendered as escaped text. Import requests remain synchronous bounded transactions; larger restartable background imports, additional entities and institutional legacy conversion/reconciliation remain open roadmap work.

The program-mapping migration was applied to the configured `nomadicjosh_sis` DDEV database on November 20, 2026. The new table was empty and its program foreign key was verified. Existing program/core-detail, course-mapping and catalog-operation checksums remained unchanged, as did all twenty journal events, stream-head/receipt checksums and the sixteen/zero role/user grant counts. Focused acceptance passed 29 PHP tests (240 assertions), real-provider private/CSRF/authorization checks and three single-worker browser scenarios covering both import destinations, dependency setup, mapping/link confirmation, escaped content, no browser storage, narrow-screen reflow and automated WCAG checks.
