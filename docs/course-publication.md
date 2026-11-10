# Published course definitions

A course has one current `CourseDefinition` containing its code, title, fixed credits, prerequisite identities, `CourseText`, optional `SubjectSnapshot` and `CourseClassifications` for department/academic level/course level. `PublishedCourseDefinition` adds the course and publication ULIDs. We evolve this model directly during alpha; field additions do not create another numbered domain class or a compatibility API.

A new **publication revision** is still a separate historical fact. Changing a title, description or subject and publishing again never overwrites the definition retained by an earlier section or student. Publication revisions and development-era serialization formats are different concerns.

## Staff workflow

1. Open **Academic catalog → Courses** and open the course. Save course and prerequisite edits first.
2. Use **Titles & description** for the optional transcript title (80 characters) and plain-text description (10,000 characters). Blank text is explicitly unspecified. Use **Course subject** for an explicit draft classification; subjects are not inferred from the course code.
3. Use **Departments & levels** to save all three classification choices together; see [classification setup](course-classifications.md). Select **Published definitions**, enter a reason and choose **Review current definition**. Earlier publications are paged oldest first, 20 at a time.
4. Inspect the complete definition. An unconfirmed review expires after 20 minutes. **Refresh course review** prepares a fresh review; **Return to definitions** lets staff revise the reason. The draft reason remains in page memory across review and history pages, never browser storage.
5. Choose **Publish reviewed definition**. Confirmation records the actor, protected reason and actual acceptance time, and displays the retained publication reference.

Publication is separate from course activation, offering activation, registration and billing. The first publication records a decision made now; it does not infer an earlier academic approval from an existing row.

## Confirmation safeguards

The encrypted review binds its purpose, course, staff member, operation, expected stream position, predecessor, complete definition, reason and expiry. The course and its assigned subject are locked while comparing the reviewed draft. Current `sis:catalog:manage` is checked after locking and replay. Competing, stale, expired, wrong-actor or tampered reviews cannot publish.

Event append, current published-text projection, durable receipt and required audit commit together. Failure rolls back the transaction. An exact retry of a completed current-format confirmation returns its original receipt after expiry or later edits/publications; it does not restore earlier text or repair projection rows. Bounded replay verifies the stream before returning the receipt. Routine catalog lists remain read-model queries.

Old alpha review formats are intentionally unsupported. Reload or refresh an old open review. Compatibility will be stabilized around release-candidate planning. This course-model cleanup was preceded by an application-database check confirming no saved course-publication events or retained course definitions needed conversion; no recorded course facts were removed or rewritten.

## Bind a section to a publication

1. Open an empty section and choose **Course definition**.
2. Inspect the course publications, choose one and enter a reason.
3. Review the chosen definition, then **Retain reviewed definition**.

Binding requires current catalog authority and locks the section/course. Any current enrollment record, including a late drop, prevents first binding. Changes to the section or a new student record after review invalidate confirmation. An earlier removed early drop does not acquire a guessed past definition.

The chosen publication remains attached to that section. New institutional credit references and portal/registration reads use it; later course edits cannot silently replace the title, credits, prerequisites or subject. Historical course/section publication references do not grant current teaching access. Unbound existing records remain explicitly unbound. This action does not rebind a populated section.

## Publish offering plans with retained definitions

1. Build an offering plan or use an active offering template. Review and **Save section drafts**; drafts remain inactive and unavailable for registration.
2. Open the saved plan and choose **Review publication**. Choose a published course definition once for each group of empty unbound sections. Existing independent bindings retain their definitions.
3. Review **Instructional dates**. Sections default to term dates or their retained instructional dates. Expand a section to adjust its period. Dates outside the term are highlighted; registration and drop deadlines still follow term policy.
4. Enter a reason and choose **Review definitions & publication**. Compare course definitions, subjects, instructional dates, faculty, delivery, capacity, fees and self-registration choices. Revision preserves the choices, dates and reason.
5. Choose **Publish reviewed sections**. The transaction retains new course bindings, reviewed section publications and initial offering selections, activates drafts, and saves the encrypted receipt/audit. A late failure rolls everything back. Activation creates no student award or account charge and reserves no room.

Plans contain at most 25 sections. All must be empty drafts with active courses. Existing matching offering selections are reused, never implicitly amended. A subject change after course publication does not replace the deliberately chosen publication. Draft/term/faculty/binding/publication/selection changes invalidate an unaccepted plan review. Reviews expire after 20 minutes; an exact accepted retry preserves its original receipt and cannot replace later selections.

Section publication/selection events name the plan as their cause while retaining independent operation/correlation identities. Earlier completed plan receipts remain readable without inventing missing historical references. See [section publication and offering amendments](section-publication.md).

## Storage and APIs

`CourseDefinitionWasPublished` uses the existing protected `sis_history_events` journal, with one current course shape and strict validation. The generic event format marker remains storage metadata; it is not a sequence of alternative domain models. No parallel journal is introduced. `sis_course_descriptions` holds draft text and a reconstructible current publication projection. Retained course/subject reference projections support indexed section, credit, portal and report reads. Missing or conflicting references require verified recovery, not inferred history.

Course review/page/confirmation use POST `/sis/courses/{id}/publication/review/`, `page/` and `commit/`; confirmation accepts only the returned `review_token`. Section binding uses POST `/sis/sections/{id}/course-definition/page/`, `review/` and `commit/`. Browser routes preserve CSRF, private/no-store responses, current permissions and debug suppression. Text renders literally rather than as trusted HTML.

Offering-plan setup/review/confirmation use POST `/sis/catalog/offerings/{operation}/publication-setup/`, `publication-review/` and `publish/`. Review supplies `course_versions: [{section_id, version_id}, ...]`, optional `section_dates: [{section_id, instruction_starts_on, instruction_ends_on}, ...]` and `reason`. Confirmation supplies only its review token. Already bound sections may omit their course choice. Duplicate, foreign, invalid or reversed entries are rejected.

Existing migrations supply the course text, course bindings, section publications and offering-selection tables. The alpha model consolidation requires no additional schema migration. [Course subjects](course-subjects.md) documents subject setup. Further classifications, complete policy references, closed-record replacements and operational rebuild tooling remain roadmap work.

Fictional checks cover publication revisions, confirmation time, expiry, actor/permission changes during waits/replay, expected versions, exact retries, strict definitions, audit/projection rollback, retained student and portal/report facts, grouped activation, multilingual bounds and inaccessible/tampered requests. Provider checks exercise actual private/CSRF routes. Browser checks exercise refresh/revision, retained drafts, keyboard use, literal text, reflow and accessibility scans.
