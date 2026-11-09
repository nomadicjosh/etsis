# Academic calendar setup

Configure **Term types → Academic years → Terms**, in that order. Calendar setup requires `sis:catalog:manage`. Open **Academic Catalog** in the admin workspace to find all three entries.

## 1. Configure term types

Open **Term types → Add term type**. A term type is a reusable template, not an actual term. Set its internal code, display-name template, generated term-code template, category, dates and active state.

Choose a Fall or Spring starting point, or expand **Optional January and summer sessions** for J-Term, a February Spring start, one Summer term, Summer 1 or Summer 2. These fill the current form with editable example values; they do not create or activate additional templates. Save only the templates your institution offers. Adjust every date and drop/add deadline to institutional policy.

Set **Example academic year starts in** to a year such as `2028`. For each date, enter a month and day in `MM-DD` format and choose **First year — 2028** or **Second year — 2029**. These choices refer to the academic year’s two calendar years, not to the first and last dates of the individual term. Fall normally uses the first year for all three dates; J-Term, Spring and Summer normally use the second year. Choose **Preview generated term** to see the actual name, code and dates using the same rules as year generation, without saving. Editing any value clears the old preview. The drop/add deadline must be within the generated term, including its first and last days. Invalid dates, including February 29 in a non-leap year, require a template correction; dates are never silently rolled forward.

An example institution with fall and spring terms:

| Setting | Fall | Spring |
| --- | --- | --- |
| Type code | `FA` | `SP` |
| Display name | `Fall {{START_YEAR}}` | `Spring {{END_YEAR}}` |
| Term code | `{{START_YEAR_SHORT}}/FA` | `{{END_YEAR_SHORT}}/SP` |
| Category | Standard | Standard |
| First day | `08-24`, First year | `01-04`, Second year |
| Last day | `12-18`, First year | `05-15`, Second year |
| Drop/add through | `09-07`, First year | `01-29`, Second year |
| Active | Yes | Yes |

For academic year **2026-2027**, these generate **Fall 2026 (`26/FA`)** and **Spring 2027 (`27/SP`)**.

Supported placeholders are `{{START_YEAR}}`, `{{END_YEAR}}`, `{{START_YEAR_SHORT}}` and `{{END_YEAR_SHORT}}`. They work in display names and generated codes. Codes may contain dots, slashes, underscores and hyphens; generated term codes are uppercase and limited to 22 characters.

**Standard** terms cannot overlap another standard term anywhere in the calendar, including across academic years. First and last dates are inclusive: a term ending December 15 conflicts with another starting December 15. **Non-standard** sessions may overlap. Use that category for genuine overlapping sessions, not as a workaround for incorrect standard dates.

Keep only the templates needed for future generation active. Editing or deactivating a type affects future academic years; it does not rewrite terms already generated. Institutions can generate up to 100 active templates per year.

### Optional January and summer terms

J-Term is an optional, separately named term. Its example template uses `{{END_YEAR_SHORT}}/J` and January dates in the second year. If a standard J-Term ends January 31, Spring must begin afterward; the **February Spring** starting point demonstrates February 1. Both sets of dates are editable, and overlap rules still apply.

For summer, choose a single active Summer template (`{{END_YEAR_SHORT}}/SU`) or separate Summer 1 and Summer 2 templates (`{{END_YEAR_SHORT}}/S1` and `{{END_YEAR_SHORT}}/S2`). All normally use second-year dates. Institutions can change codes, dates, categories and the number of sessions, including creating other custom templates. Nothing automatically forces one summer model. Summer starting points are non-standard to allow overlapping sessions; choose standard only when those dates do not overlap other standard terms.

For academic year **2028-2029**, a Fall template from August 24 to December 18 in the first year generates **28/FA**, while Spring in the second year generates **29/SP**. An active Summer template ending August 30 in the second year extends term coverage to **2029-08-30**. With J-Term and split summers enabled, additional codes may be **29/J**, **29/S1** and **29/S2**. Inactive templates generate nothing.

## 2. Create an academic year

Open **Academic years → Create academic year** and enter only the **start year**. etsis supplies the next consecutive end year: entering `2026` gives **2026-2027**. There is no separate end-year input or catalog-year form.

Choose **Review generated terms**. Check every term's name, code, category, dates and drop/add deadline, then choose **Confirm academic year and terms**. Review alone saves nothing. Confirmation saves the academic year, its derived cohort reference and all terms in one transaction. Missing templates, duplicate codes, invalid dates and standard overlaps stop the operation.

If someone changes the calendar after your review, review it again. If confirmation is interrupted, choose **Check saved academic year** or retry the same confirmation. Keep the existing review open until its result is known; a retained operation reference prevents duplicate creation.

An academic year's displayed **active-term coverage** runs from its earliest member term start to its latest member term end, in chronological order. It is not a fixed August-to-August boundary; summer extends coverage only if an active summer template produces that later end date. The label and identity remain fixed. A term's explicit academic-year membership determines matriculation, not a search for overlapping date ranges.

### If generated term codes conflict

An existing code must identify the same term consistently. The year review names the generating term type and code, the requested academic year, and either the year already linked to that code or each date that differs. It saves nothing when a conflict is found.

Open **Review term types** from the year form and check **Term code template**. Use `{{START_YEAR_SHORT}}/FA` for Fall and `{{END_YEAR_SHORT}}/SP` for Spring in the following year; for 2027-2028 these produce `27/FA` and `28/SP`. A literal code such as `26/FA` stays unchanged for every year. The template editor previews two consecutive years and warns about fixed codes.

For an unlinked existing term with the intended code, compare its actual dates in **Terms** with the template's month/day and year anchors. Correct the configuration that is wrong, then review year creation again. A term already linked to another year cannot be moved or reused by generation. Existing dates and linked academic/financial records are never overwritten to resolve a code conflict.

## 3. Maintain individual terms

The separate **Terms** entry remains available. Open a term to maintain its display name, code, category, first/last dates or drop/add deadline. These changes use the displayed revision to reject stale edits. Existing registrations, grades and financial records keep their term ULIDs.

Use **Add a term** for an exceptional session that does not belong in the recurring templates. Select its academic year and supply the term details. Term dates must fall within the start or following calendar year. A term may be linked to an academic year once; it cannot subsequently be moved to a different year or unlinked, protecting matriculation history. Template source/version metadata remains available after individual edits.

Editing a term's drop/add date affects subsequent registration/drop policy decisions, so follow institutional policy when changing a calendar already in use. This screen does not create course sections, registration windows, tuition rules or room bookings. Those remain separate setup tasks. A term's standard/non-standard category does not determine whether courses are online, in person or hybrid.

## Matriculation and retained program requirements

A student's catalog year is an automatically derived cohort, not another setup record staff must enter. At matriculation or a new program assignment, etsis follows the program entry term's academic-year link and retains a stable program/year curriculum reference on that assignment.

For example:

| Student | Starts pursuing the B.A. | Retained requirements cohort |
| --- | --- | --- |
| Brian. Smith | 2022-2023 | B.A., 2022-2023, throughout that program through graduation |
| Mary Smith | 2024-2025 | B.A., 2024-2025, throughout that program through graduation |

Creating 2024-2025 or revising its B.A. curriculum does not replace Brian's reference. Later revisions also leave existing assignments untouched. Each program assignment has its own entry term and retained reference. Staff can review **Matriculation cohort** and its history from a student's program record; the current workspace does not offer a manual cohort reassignment form.

This establishes the stable identity needed by the forthcoming degree-audit builder. Requirement authoring, requirement evaluation and institutional exceptions are not implemented by calendar setup. An existing assignment without a reliable historical reference remains flagged for review; the system never silently substitutes the current year.

## Database setup

`20261108000001_CreateSisAcademicCalendarTables` creates the five calendar tables and seeds the calendar lock row. It depends on earlier SIS migrations, including `20261107000008_CreateSisCatalogYearTables`, which still creates the internal cohort and curriculum-reference tables used by the application. Both migrations remain necessary for fresh installations. Apply them through the project's configured runner in order.

The temporary conversion loops for the earlier development schema have been removed. Fresh installations begin with empty term types, academic years and term memberships; follow the setup sequence above to populate them. The migration does not copy existing cohort rows, infer term membership from dates, change publication states or assign missing student curriculum references. `sis_catalog_years` remains an internal derived cohort projection, with no editable setup form. Visible academic years live in `sis_academic_years`.

An already-converted test database keeps its existing rows and migration history. This source cleanup does not require rerunning or rolling back an applied migration. The development checks use fictional in-memory databases and do not alter the working test database.

When importing historical terms, review their explicit academic-year membership and standard/non-standard classification. A term without metadata appears as **Needs classification** and conservatively blocks overlapping new standard terms. Generation can adopt an existing unlinked term only if its code, first/last dates and drop/add deadline exactly match the generated term; adoption preserves its ID and connected records. Existing linked terms cannot be adopted again. Historical student curriculum assignments require their own validated references and are never guessed from dates.

Reports display full academic-year labels such as `2024-2025`; review saved filters that previously matched a four-digit catalog-year label.

Rolling back this migration removes its calendar metadata, template definitions and generation receipts. It retains underlying terms, internal cohort references and academic/financial records. Reapplying recreates empty calendar tables; it does not restore removed metadata. Schema compilation is checked for the framework's MySQL, PostgreSQL, SQLite, SQL Server and Oracle dialects. Migration execution and rollback tests use fictional SQLite databases; live-server behavior for the other engines has not been certified.
