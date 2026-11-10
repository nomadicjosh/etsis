# Course subjects

For department, academic-level and course-level setup, see [Departments & levels](course-classifications.md). These classifications are independent of the subject and course code.

Subjects classify courses independently of their full course codes. `MATH.101` does not automatically create or select a MATH subject. Departments, academic levels and course levels remain separate future classifications.

Apply **`20261110000003_CreateSisCourseSubjects`** through the normal migration runner after the existing course/section reference migrations. It creates three ULID-keyed tables, without changing recorded events or assigning guessed subjects to existing courses:

- `sis_subjects`: current codes, names and active/retired status.
- `sis_course_subjects`: the explicit draft subject assignment for a course.
- `sis_course_version_subjects`: immutable, reconstructible subject values for retained course versions. It is a reference projection, not another journal.

## Staff workflow

1. Open **Academic catalog → Subjects**, then **Add subject**. Enter a unique code and name. Codes are normalized to uppercase and accept up to 40 letters, digits, periods, underscores, slashes or hyphens. Names support up to 191 Unicode characters.
2. Search the library or page through 50 subjects at a time. Editing requires the current revision. Clear **Active subject** to retire a subject; there is no deletion action. Retirement prevents new course assignments and preserves existing uses.
3. Open a course and choose **Course subject → Choose subject**. Select an active subject. This saves a draft classification; it changes no earlier publication or student record. **Clear draft subject** removes only the draft assignment.
4. Choose **Review course publication**. Inspect the subject alongside the course's other reviewed facts, then explicitly confirm publication. A subject edit or reassignment during an unconfirmed review requires a fresh review.
5. Select the published course definition through the existing empty-section binding or offering-plan workflow. New student attempts retain that course version. Staff do not duplicate a subject field on each enrollment or credit award.

A retired subject can remain on an already assigned course and be preserved in an explicitly reviewed publication. Editing its name does not change earlier publications. A clone or CSV import does not infer classification from a course code; assign its draft subject before publishing.

## Historical and security behavior

The single current `CourseDefinition` includes a nullable `subject`. An assigned subject retains exactly its ULID, code and name once per course publication. Null is explicitly unspecified; the application never parses a code or queries today's subject library to invent an earlier classification.

Course reviews use one current proof format, with confirmation-time attribution, 20-minute expiry, expected versions and exact retries. Older open alpha reviews require refresh. Publication locks its course and assigned subject while comparing reviewed facts. Existing sections do not silently switch course publications after a subject rename or reassignment. No `CourseDefinitionV2` or `CourseDefinitionV3` wrappers remain.

Subject setup and draft assignment use catalog-authorized CRUD with optimistic revisions, permission rechecks after locks and required metadata audit. Audit failure rolls back the reference change. They do not create artificial historical events. Actual reviewed publication remains authoritative in the single protected journal. Subject projections are verified against the retained definition when publication/binding commands use them; general operational rebuild tooling remains separate work.

The portal displays the retained subject on bound course offerings. Report Studio's sections and academic-credit datasets expose **Retained subject code** and **Retained subject name**. These join the retained version projection, never today's subject assignment. Unbound records and explicit nulls have no inferred subject labels. Existing student/source permissions still apply.

The four routes are POST `/sis/catalog/subjects/`, POST `/sis/catalog/subjects/save/`, and GET/POST `/sis/courses/{id}/subject/`. All require `sis:catalog:manage`; browser mutations retain framework CSRF protection, private/no-store responses and debug suppression. Text is escaped, searches/pages are bounded, and the editor uses native labels and keyboard controls without browser storage.

Course review proofs are bounded at 512 KiB. Course-definition events may use the existing 256 KiB batch ceiling to accommodate the documented Unicode text limits; other event types retain their 64 KiB per-event limit. Event bytes, encryption and canonicalization remain unchanged.

Fictional checks cover subject renames and retirement, stale revisions/publication reviews, immutable course references, retained portal/report labels, permissions, audit rollback and strict historical snapshot validation. Migration definitions compile through the framework's five supported database compilers; SQLite fixtures exercise execution. This is not live-server certification. No working database or DDEV service was used as a fixture.
