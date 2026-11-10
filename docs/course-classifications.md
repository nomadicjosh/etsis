# Course departments and levels

Departments, academic levels and course levels are independent reference definitions. Their codes are not parsed from course codes, and they do not grant staff permissions or select a grading scale. Program grading policy still overrides the institution's global policy through the existing grading workflow.

## Set up the library

1. Open **Academic catalog → Departments & levels**.
2. Choose **Departments**, **Academic levels** or **Course levels**. For example, an institution might define Sciences, Undergraduate and Introductory respectively.
3. Add a code and name. Codes can contain letters, digits, dots, underscores, slashes and hyphens, up to 40 characters; codes are normalized to uppercase. Names allow up to 191 characters and display as literal text.
4. Use the search to find existing entries. An empty search browses a bounded page; additional pages are loaded explicitly. Codes are unique within their category, so the same code can have independent meanings in different categories.
5. Edit an entry to rename it or clear **Available for new assignments** to retire it. Retirement preserves existing assignments and publications. An entry cannot be moved to another category or deleted through the interface.

## Assign a course

1. Open a course and choose **Departments & levels**.
2. Choose the department, academic level and course level using their searchable libraries. All three choices stay together on the course screen. Returning from a chooser preserves unsaved choices; adding a definition from a chooser selects the newly saved entry.
3. Clear a choice if it should be unspecified. Retired entries cannot be newly assigned, but an existing retired choice can remain while other classifications are edited.
4. Choose **Save draft classifications**. Until saving, **Review course publication** is disabled so an unsaved choice cannot be mistaken for the definition being published.
5. Choose **Review course publication**, enter a reason, inspect the classifications with the other course facts and confirm the publication.

Draft changes do not change an earlier publication or an already bound section. A department rename or course reassignment becomes part of a later publication only when staff explicitly review and publish it. Earlier student records retain the course publication originally selected for them.

## Historical and security behavior

The current `CourseDefinition` includes a `CourseClassifications` value with three explicitly nullable snapshots. Each specified classification retains its identity, code and name once per course publication in the existing protected journal. No numbered course-definition class, additional event type or parallel journal is introduced.

Reference setup and draft assignments use catalog-authorized CRUD with optimistic revisions and required audit. Assignments lock the course, then current and proposed references in identity order, recheck authorization and validate the category of every choice. A concurrent rename or assignment change requires reloading. Publication also locks the assigned references and compares the complete reviewed definition; a rename after review requires a fresh publication review.

Retained section/course references store the accepted classification snapshots in `sis_course_version_classes`. A row is retained even when all choices are explicitly unspecified. Missing, malformed or conflicting retained references are rejected rather than filled from today's library. Reference retention, binding history, receipt and audit commit or roll back together.

Report Studio exposes retained department, academic-level and course-level codes and names on the **Sections** and **Academic credits & final grades** datasets. These join a single retained classification row per course publication, preserving the dataset's row grain. They do not join today's assignment. Unbound records have no inferred historical classification. Existing source and student-record permissions still apply.

All four routes require current `sis:catalog:manage`: POST `/sis/catalog/classifications/{kind}/page/`, POST `save/`, and GET/POST `/sis/courses/{id}/classifications/`. Categories are restricted to `department`, `academic_level` and `course_level`. Browser mutations retain framework CSRF protection, private/no-store responses and debug suppression. Lists are capped at 50 entries per page; unsaved choices remain only in page memory.

## Database setup and scope

Migration **`20261110000004_CreateSisCourseClassifications`** adds the reference library, current course assignments and retained publication-reference tables. It uses ULID/string identities and the framework's portable schema API. The 109 SIS table definitions compile for MySQL, PostgreSQL, SQLite, SQL Server and Oracle; isolated behavior checks use SQLite, and this migration was also applied successfully to the configured DDEV application database.

Before extending the current course shape, an application-database check confirmed there were no course-publication events or retained course definitions requiring conversion. No existing events were removed or rewritten, and no historical labels were inferred from current rows. An older open course-publication review must be refreshed.

This stage covers course classification and its retained downstream references. [Program publication](program-publication.md) now reuses departments/academic levels and adds degree/award definitions. Historical academic-level decisions for students remain separate work. Course classification alone does not decide registration eligibility, degree completion, GPA treatment, billing or access.

Fictional integration checks cover retained labels after renames/retirement, reports, stale reviews, category/permission boundaries, bounded pages and rollback. Real-provider route checks cover empty searches, typed inputs, CSRF and privacy. Browser checks cover the combined chooser, search clearing, retirement, draft preservation, keyboard saving, literal rendering and accessibility.
