# Reviewed imports

**Import data** uses one mapping, reconciliation and confirmation workflow for courses, academic programs and catalog reference lists. Imports create working definitions/reference entries or explicitly link existing identities; they never overwrite catalog definitions or publish academic requirements. All actual imports run on the server after a reviewed request is queued. The same screen also provides [create-only people intake](#import-people). All examples use fictional data.

## Choose and map a file

1. Choose **Courses**, **Academic programs**, or a list under **Catalog reference lists** as the import destination. Changing the destination clears the selected file, mappings and row choices so an earlier review cannot be confirmed against another destination.
2. Choose a stable **Source name**. Source names are lowercase and contain up to eighty letters, digits, dots, underscores or hyphens. Keep the same source name for later imports from that source.
3. Upload a UTF-8 CSV with distinct column headers: up to 2 MB, forty columns and 5,000 data records. Comma, semicolon and tab delimiters are available. **Sample CSV** downloads the example for the selected destination.
4. Choose **Match fields**. Course imports require four distinct source columns: **External ID**, **Course code**, **Course title** and **Credits**. Program imports require three: **External ID**, **Program code** and **Program title**. Reference imports also require three distinct columns: **External ID**, the reference **code**, and its **name**. Only course imports have a credit field. Mapping shows a source example for each selected column; unrelated source columns can be left out.

An external ID is a stable source-system identity, not a student/person institutional ID. Its original letter case is preserved. Duplicate IDs within a file, or existing source IDs differing only by case, require resolution. Mappings are independent for every destination, including each reference category. The same source/external ID can identify a course, a program, a subject and a department without linking those entities together.

Course credits accept exact thousandths, such as `3`, `3.5` or `3.125`, without floating-point rounding. Program codes are normalized to uppercase and follow the ordinary program editor's format: up to eighty letters, digits, dots, underscores or hyphens. Program descriptions are optional plain text up to 10,000 characters; literal markup is text, not executable HTML.

## Set up reference lists first

Import **Subjects**, **Departments**, **Academic levels**, **Course levels**, **Degrees & awards**, **Instructional methods**, **Campuses & sites**, **Schools & colleges**, and **Fields of study** through the same three-step mapper. Select one destination per file; use the [reference CSV example](../public/assets/examples/catalog-references.csv), replacing its fictional values with the codes and names for that destination. No particular institutional values, such as Undergraduate or Graduate, are assumed.

Reference codes are normalized to uppercase and accept up to forty letters, digits or `. _ / -`. Names accept up to 191 printable characters. There are no course-credit, description or dependent-code mapping controls for a reference list. Once processed, new entries are active and immediately available to the ordinary catalog selectors and subsequent course/program imports. Reference imports do not create courses, classifications on courses, publications or Moodle mappings.

An existing active entry with the same code/name needs an explicit **Link subject**, **Link department**, or equivalent decision. A different name or an inactive entry is a conflict: imports never rename, overwrite or reactivate an existing entry. Authorized catalog staff may deliberately edit it separately and review again. A previously accepted source ID remains a prior import even if staff later edit or retire its entry; repeating the file does not restore old values.

After the server job completes, **Review subjects**, **Review departments**, or the corresponding action opens the existing reference editor for staff who also have catalog-maintenance permission. Then use **Import another file** for the next dependency or switch to courses/programs. Importing references first avoids unresolved dependency codes in subsequent files.

## Optional details and dependencies

Expand **Add course details & classifications** for a course, or **Add program details** for a program. Map only the fields you intend to supply.

| Destination | Optional fields |
| --- | --- |
| Courses | Optional course number; subject, department, academic level and course level codes. |
| Programs | Program description; department, academic level, award, school/college, field of study and campus/site codes. |

Course number is optional text up to 32 printable characters, for example `001A`. It preserves leading zeros, suffixes and case; the full code remains required. An unmapped number does not participate in matching and cannot clear the existing value. A mapped value, including an explicit blank, must match before an existing course can be linked. Different numbers are a conflict requiring a separate catalog edit or exclusion. Mapping the number never changes an earlier accepted source receipt or a published/student definition. The importer does not infer numbers from full codes.

Each nonblank reference code must resolve to exactly one active entry in its own library. Codes are trimmed and normalized to uppercase; readable names appear in the review. The importer never guesses a subject from a course code, turns a school into a campus, creates missing references silently or assumes Undergraduate/Graduate exist in every installation. Optional destinations may deliberately share a source column.

A blank or unmapped reference remains unspecified for a new definition. When linking an existing record, only supplied reference codes must agree; omissions never clear its current assignments. An unmapped program description does not clear or replace the existing description. If description is mapped, its trimmed value—including an explicit blank—must match before linking. Descriptions/classifications on a conflicting existing program require a separate authorized catalog edit.

Missing, ambiguous or retired dependencies appear as actionable row conflicts. Authorized catalog staff can open the existing **Catalog setup** drawers directly from the review, including subjects, departments/levels, schools/fields, campuses/sites and awards where applicable. Closing a drawer preserves the file, mapping and choices. Choose **Review choices** afterward. Import permission does not itself grant catalog-maintenance authority.

Use the [course reference example](../public/assets/examples/courses-classifications.csv) or [program example](../public/assets/examples/programs.csv). The program example deliberately has blank reference codes so it does not assume any institution's libraries are already configured.

## Review, link and confirm

1. Choose **Review import**. The summary separates new definitions, matches needing approval, approved links, prior imports, excluded rows and conflicts. Reviewing creates no catalog record, source mapping or business event. A ready review stages a private encrypted source artifact for confirmation.
2. A matching existing record requires its explicit **Link course**, **Link program**, **Link subject**, or corresponding reference action. Linking records only the source identity. Its title, description, credits, classifications, activity, publications and student references remain unchanged. Several matches are a conflict rather than an automatic guess.
3. Choose **Leave out** for unwanted/conflicting rows. **Include row** or **Undo link** changes the working choice. Existing data is never overwritten by a changed source row.
4. Choose **Review choices** after changing a link or exclusion. The current ready review is required for confirmation. Search, review filters and twenty-five-row pages keep a large file manageable; the first five original source rows are available separately.
5. Choose **Queue catalog import**. Confirmation queues the reviewed source and returns immediately; it creates no catalog records. Follow **Import activity** or the notification bell. The browser can close safely. Each server batch commits its catalog changes, source mappings, encrypted operation receipt and progress together. Earlier completed batches remain if a later batch stops.

New courses are inactive drafts. New programs have a working program definition and their explicitly reviewed references. The optional mapped campus is the primary/contextual campus; maintain additional offering sites through the ordinary program editor. Linking an existing program preserves its additional affiliations. Programs use the ordinary catalog model: their reference can appear in program choice/directory lists, as a manually created program does. Import does not publish an academic-year program revision, create degree requirements, assign application forms, matriculate anyone or move a student's cohort. Complete the normal [program publication](program-publication.md) and application-form setup before using a new program in an intake workflow. There is no separate importer-owned program activation state.

After completion, **Review course drafts** or **Review program details** opens the normal catalog workspace. **Import another file** starts a fresh working selection.

## Source identities, history and retries

A previously imported source row with the same original semantic content is skipped, even if its catalog record was edited later. A source ID that now identifies different content is a conflict, not an update instruction. An accepted queue confirmation returns the original job and its current status after expiry or later catalog changes, without queuing another copy. Current import authority and the original signed-in owner still must match. The protected submission binds its destination, file, mappings and choices; confirmation submits only that proof. Earlier catalog operation receipts remain intact. A program review cannot be reused as a course review.

Unconfirmed reviews expire after twenty minutes. Each server batch checks its current catalog plan against the originally approved row hashes, locks selected catalog identities and dependencies in stable order, and rejects changed matches, classification labels/assignments, new code/source collisions and unauthorized requests. Final checks verify newly written/linked records, source mappings and requested dependencies again after required audits. Changed created definitions, missing mappings, authority loss, expiry or audit failure roll back the current batch and its progress together. Existing previously imported catalog facts are not silently repaired.

Working program/course/reference setup and source reconciliation are ordinary authorized CRUD, not new academic events. Publications remain separate consequential decisions in the single protected journal. Imported drafts and source links do not rewrite earlier course/program publications, student program bindings, grades or financial records. Course fingerprints, mapping identities, proof purpose and accepted-result contracts retain their existing meanings through the shared catalog-import implementation.

`CatalogImportRow`, `CatalogCsvMapper`, the League CSV adapter and `MappedImportService` serve all catalog destinations. A server-owned enum selects allowed tables and identity fields; browser input cannot name a table or column. The existing course mappings remain in `sis_import_mappings`; program mappings use `sis_program_import_mappings` with a ULID, source/external identity, content hash and program foreign key. Both reuse the existing encrypted `sis_catalog_operations` receipts. These are reconciliation/operation support, not parallel domain event journals.

Migration `20261120000012_CreateSisProgramImportMappings` adds only the empty program-mapping table. It copies no current records into history, changes no existing mapping/receipt, seeds no classifications and grants no permissions. Its DDL compiles for the framework's MySQL, PostgreSQL, SQLite, SQL Server and Oracle dialects; the original installation evidence below predates the PostgreSQL conversion. Current import queue qualification is described separately below.

## Access, privacy and limits

Preview and confirmation require `sis:import:manage`. Reference setup independently requires `sis:catalog:manage`; access to the ordinary program editor keeps its own catalog permission. These actions use the existing CSRF-protected `/sis/imports/preview/` and `/sis/imports/commit/` routes, private/no-store responses and suppressed debug collectors. The explicit `target` is `course`, `program`, `subject`, or one of the eight classification kind values (`department`, `academic_level`, `course_level`, `award`, `instructional_method`, `campus`, `school`, `field_of_study`). Existing course requests that omit it still mean courses. Null or arbitrary destinations are rejected.

The CSV and preview stay only in browser page memory while reviewing. A ready review also stores an encrypted server artifact containing the source and approved row hashes. Jobs and notices contain reference IDs, destination, status and counts, never plaintext source values, credentials or email bodies. Audit entries contain actor/action/record metadata. Source values render as escaped text; neither source nor proofs use browser storage or logs. Student, authoritative academic, financial and institutional legacy conversion remain separate open work; they must use this server queue with their own domain reconciliation rather than add synchronous import endpoints.

The program-mapping migration was applied to the configured `nomadicjosh_sis` DDEV database on November 20, 2026. The new table was empty and its program foreign key was verified. Existing program/core-detail, course-mapping and catalog-operation checksums remained unchanged, as did all twenty journal events, stream-head/receipt checksums and the sixteen/zero role/user grant counts. Focused acceptance passed 29 PHP tests (240 assertions), real-provider private/CSRF/authorization checks and three single-worker browser scenarios covering both import destinations, dependency setup, mapping/link confirmation, escaped content, no browser storage, narrow-screen reflow and automated WCAG checks.

Reference imports use `sis_subject_import_mappings` with a subject foreign key and `sis_class_import_mappings` with a classification foreign key and destination-kind scope. Both use ULIDs and indexed unique source identities. They reuse the existing encrypted catalog operation receipts, transaction boundary and required metadata auditing. Reference setup remains ordinary CRUD; imports append no fabricated historical business events. The migration creates empty mapping tables and converts or removes no existing data.

The reference-mapping migration `20261120000015_CreateSisReferenceImportMappings` was applied to `nomadicjosh_sis` on November 20, 2026. Both mapping tables were empty afterward, with their subject/classification foreign keys present. Checksums for eleven existing catalog, reconciliation, journal, stream-head and operation tables were unchanged; all twenty recorded events were preserved. Focused verification passed 36 PHP tests (399 assertions), real-provider routing/private-response/authorization checks covering all nine reference destinations, and seventeen distinct browser scenarios across the new and existing import/editor flows. Reference browser checks include explicit links, CSRF requests, no browser storage, accessible narrow-screen review and destination controls surviving navigation. SQLite execution and five-dialect DDL compilation are covered; native engines beyond the configured MariaDB remain release qualification work.

## Import people

Open **Import data → Import people**. This action requires both `sis:import:manage` and `sis:person:manage`. Download **Sample people CSV**, choose comma, semicolon or tab, provide a creation reason, and choose **Read columns**. Map three distinct columns to **Given name**, **Family name** and **Email**, then choose **Review people**. Review pages contain at most twenty-five people; the complete bounded file is validated before queuing.

Names must be printable text up to 191 characters. Every person requires a valid email up to 191 characters. Email comparison ignores case. Repeated emails within the file and emails already used by any account block submission. The importer never automatically links or merges people based on an email or name. Resolve an existing person through their ordinary record; remove that row from new-person intake.

**Queue import** creates a server job. Each accepted row uses the ordinary person/account creation workflow: one canonical person, one linked basic sign-in account, a private password-setup notice awaiting delivery, and genuine protected creation history. It creates no student, employment or faculty capability, enrollments, imported historical facts or elevated grants. Names and creation reasons remain subject to their normal protected history/receipt controls. The queue does not send password email directly; the existing account-setup sender handles private delivery separately.

## Server processing, progress and notifications

All supported imports use the same durable queue. The worker revalidates the submitting account's current sign-in access and required permissions; it never borrows a browser session. One pass processes up to 100 catalog records or ten people, inside a transaction that also advances the cursor. A lost process rolls back that current batch. A two-minute lease lets a later worker resume from the last committed cursor; independent workers cannot both commit the same cursor. Processing must finish within one day of submission.

**Import activity → View progress** shows queued/processing/completed/needs-attention status and processed, created, linked, prior-import and excluded counts. Use **Refresh** for the current server result. Completed or stopped jobs create one persistent personal **Import updates** notification. The bell checks unread import notices once a minute while the workspace is visible. Open **Import updates** to view all retained notices, page through them, or permanently delete them. Close and reopen the section to reload the list. Deleting a notice removes its database row; a completed retry does not recreate it. Job results and domain receipts remain separate from disposable notifications.

If a job stops, its first **processed** data records have been committed; none after that cursor were imported. The failed batch has no partial domain writes. Review the displayed reason, correct the remaining source records and submit a new reviewed file containing only the unprocessed data. This is especially important for people, where repeating already created emails blocks intake. Full-file rollback is not promised. Source changes are never silently approved by a worker.

Encrypted source artifacts are deleted on completion, when a job stops, or when an abandoned review expires and the worker next cleans it up. Minimal job metadata and encrypted domain operation receipts remain for reconciliation and original retries. Notifications contain no person names, email addresses, source rows or correction reasons. All activity/notice reads and mutations enforce the signed-in owner, private/no-store transport and ordinary browser CSRF protection.

## Run the server worker

Staff can use **Import activity → Cancel remaining import steps** for an owned queued or running job. Cancellation takes effect at the next transaction boundary; an in-flight step can commit first. Already accepted records and their counts remain, the encrypted queued source is removed, and one private cancellation notice is issued. Original submission retries return the stopped job rather than restarting it. Review a new import for remaining records. Deleting the notice does not delete business records or restore the job.

The Codefy `ConsoleCommand` is `sis:imports:run --limit=2` (one through twenty batches). It stops between batches after twenty seconds and prints only status/count metadata. Run it using the server's configured PHP interpreter, with a process memory cap and an outer deadline. The supplied launcher accepts that interpreter path:

```sh
/bin/sh tools/run-import-worker.sh /path/to/server/php
```

The launcher holds a single filesystem worker lock, runs low-priority passes capped at 128 MiB/thirty seconds, and pauses fifteen seconds between passes. Database leases/cursors protect writes independently of that local process lock. Configure one supervised launcher per application deployment; production may instead schedule bounded console passes. Monitor worker availability: a queued job requires a running server worker, regardless of browser state. There is no browser-driven fallback for actual importing.

DDEV installs `.ddev/sis-imports.conf` through `.ddev/web-entrypoint.d/sis-imports.sh`, so its ordinary supervisor starts the launcher in the web container. This development worker was activated without a container restart. Inspect it with `ddev exec supervisorctl status sis-imports`; start/stop it with supervisor when needed. Local DDEV uses the same physical computer, while a hosted deployment runs on its server. The defaults deliberately keep one worker and small sequential batches.

Configure the web server/PHP to accept the bounded request envelope (for example `post_max_size=32M` and a matching request limit). The application allows an eight-MiB decoded review envelope for CSV escaping and explicit row choices, while enforcing the two-MiB source-file limit and 5,000-record limit. Other workspace routes retain their existing smaller request allowance. Background work removes the need to keep a browser request open; it does not remove upload, server memory or institutional validation limits.

Migrations `20261125000013_CreateSisPersonImports` and `20261125000014_CreateSisBackgroundImports` add four empty ULID-keyed receipt/job/artifact/notice tables. They copy or delete no existing records, grant no permissions, and add no parallel event journal. SQLite execution and all five framework DDL dialects are checked. They were applied to the configured PostgreSQL database after a server-enforced read-only snapshot: all original business-table fingerprints and application-role flags matched afterward. The original 235-table/1,101-row database grew to 239 tables/1,103 rows, with only two extra migration-ledger entries. Protected history remained 17 streams/20 events/20 receipts/20 associated references. Native concurrent importer/load qualification on other engines remains release work.


Selected PostgreSQL and SQLite [server import recovery](native-workflow-qualification.md#server-import-recovery) now covers real process interruption, lost replies, committed cursor recovery, competing claims/confirmations and later source/permission conflicts with fictional data. This includes 21-person and 101-course jobs; it does not establish production throughput, other-engine acceptance or full backup restoration.
