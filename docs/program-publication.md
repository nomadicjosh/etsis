# Published program details

A program has one working definition and explicitly published catalog revisions for existing academic years. Each publication retains its code, title, plain-text description, department, academic level and offered degree/award. Later draft edits and reference-library renames do not replace those facts.

The [degree-audit builder](degree-audit-builder.md) separately publishes course/group requirements and policies. Program publication is not a graduation decision and does not automatically assign or replace a student's degree-audit requirements.

## Staff workflow

1. Set up [term types and academic years](academic-calendar-setup.md). Terms identify the academic year of matriculation; there is no separate catalog-year entry form.
2. Open **Academic catalog → Programs → a program → Program details & publications**.
3. Edit the code, title and description together. Choose the department, academic level and award from searchable libraries. Add a degree or certificate directly from the award chooser, or use **Degrees & awards** in the catalog toolbar.
4. Save once. Unsaved text and classification choices survive returning from a chooser or publication history. Publication stays disabled while changes are unsaved. Codes allow up to 80 letters/digits/dots/underscores/hyphens, titles up to 191 characters and plain-text descriptions up to 10,000 characters.
5. Choose **Publish for an academic year**, enter a reason and choose an existing academic year. Searches accept a blank value and use bounded pages. Choosing a year preserves the reason.
6. Choose **Review program publication**. Inspect the year, proposed catalog revision, program text and classifications. **Revise program publication** preserves the year/reason; **Refresh program review** prepares a fresh review. An unconfirmed review expires after 20 minutes.
7. Choose **Confirm program publication**. Confirmation records the actual acceptance time and selects the next program catalog revision for that academic year.

For example, publish a B.A. definition for **2022-2023**, then a revised definition for **2024-2025**. A student's existing 2022-2023 assignment keeps its reference. New assignments entering 2024-2025 use that year's published revision. Editing today's draft without publishing for 2022-2023 does not change the labels used by a new assignment entering that older cohort.

Publishing another revision for the same year changes the default for subsequent assignments into that year. It does not move existing students. Retrying an earlier accepted confirmation returns its original receipt after later publications, edits or expiry, without restoring the earlier default.

## History and read models

Publication creates a `sis_program_catalogs` reference and a normalized `sis_program_catalog_details` projection. The existing term → academic year → current program catalog selection drives subsequent matriculation/additional-program assignments. Existing students keep their `sis_student_catalogs` bindings.

Before a new entry decision confirms, an internal reader verifies the selected year's protected publication chain and retained projection. A missing/conflicting reference stops the decision rather than borrowing today's definition or treating a missing projection as an unrecorded program. Replay consumes verified pages and retains only the aggregate head and relevant year's latest publication. Admissions/registrar commands keep their existing permissions; students do not need catalog-management authority to use a published definition.

Ordinary draft views, student assignment lists and reports use indexed read models without replay or write locks. Assignment labels use retained catalog labels when a program definition was recorded. Report Studio's **Student programs & catalog cohorts** dataset adds published program code/title/description/year, department, academic level and award fields. Joins preserve one row per assignment. Earlier unversioned references have null published-detail fields; today's library does not supply inferred historical classifications.

**Published program details** shows curated definitions, academic years, revisions and accepted/recorded times in frozen 20-entry pages. It omits actors, protected reasons and raw envelopes. Earlier prepared transcript documents, academic records and financial postings are unchanged.

## Security and storage

`ProgramDefinitionWasPublished` uses the existing protected journal, with program-owned streams, expected positions and predecessor checks. There is one current `ProgramDefinition` and `ProgramClassifications`, with no numbered wrappers or parallel journal. Award/academic-level labels do not grant permissions, award a credential or infer a grading scale.

Encrypted reviews bind purpose, reviewer, operation, complete definition, academic year, proposed revision, previous reference, stream position, reason and expiry. Confirmation locks the program/year and assigned classification references in identity order, compares the reviewed facts and rechecks authority after waiting/replay. Stale, competing, wrong-actor, changed-reference and expired reviews cannot publish. Accepted retries verify history and return their original result without repairing projections.

The event, cohort revision, retained definition, current-year pointer, durable receipt and required audit commit or roll back together. Draft saves use authorized CRUD, optimistic revisions and required audit. Retired classifications cannot be newly assigned; existing retired choices can remain. Awards cannot be used as departments or academic levels.

Five endpoints require `sis:catalog:manage`: GET/POST `/sis/programs/{id}/details/` and POST `/sis/programs/{id}/publication/review/`, `commit/`, `page/`. Browser mutations retain framework CSRF protection; responses are private/no-store and debug collectors are suppressed. Unsaved state remains only in page memory.

## Migration and scope

**`20261110000005_CreateSisProgramDefinitions`** adds two ULID-keyed draft/reference tables through the portable schema API. All 111 SIS table definitions compile for the framework's five supported databases. The migration was applied to the configured DDEV application database. A count-only preflight found no existing program cohort references or program-publication events. No existing event or student assignment was converted, removed or fabricated.

Older unversioned curriculum references remain unversioned; this workflow does not invent their historical approvals. Existing reference initialization remains available for programs without a recorded publication for that year. Once a year's program publication exists, new entry decisions verify and use it.

Schools, majors/minors, specializations, CIP/location classifications, effective retirement, public program-discovery presentation remain later stages. Degree-audit selections keep their own retained requirements; evaluation and graduation remain separate work.

Fictional checks cover year-specific student labels/bindings, report joins, retained history, stale/expired/competing reviews, reference changes, permission rechecks, rollback, exact retries, frozen pages and framework replay. Actual-provider/router checks cover CSRF, private responses, permission boundaries and curated history. Browser checks cover combined editing, draft preservation, year/reason review, keyboard confirmation, narrow-screen accessibility and literal rendering.

New degree-requirement publications now retain the reviewed program revision; see [the linked publication workflow](degree-program-publication-link.md). Earlier requirements remain unlinked.

## Optional description assistance

The details editor offers [consent-based program description assistance](optional-authoring-assistance.md#academic-program-descriptions) using the institution's configured provider. Authorized authors can generate, rewrite or expand saved descriptions, inspect suggestions and copy them into the unchanged editor. Assistance never saves or publishes automatically. Normal revision checks and academic-year publication preserve earlier student references.
