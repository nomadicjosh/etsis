# Published course definitions

A course has one current `CourseDefinition` containing its code, title, default credits, optional retained [credit policy](course-credit-policies.md), prerequisite identities and optional counted choice groups, `CourseText`, optional `SubjectSnapshot` and `CourseClassifications` for department/academic level/course level. New publications include an explicit fixed/range rule; earlier absent policies remain unrecorded. `PublishedCourseDefinition` adds the course and publication ULIDs. We evolve this model directly during alpha; field additions do not create another numbered domain class or a compatibility API.

A new **publication revision** is still a separate historical fact. Changing a title, description or subject and publishing again never overwrites the definition retained by an earlier section or student. Publication revisions and development-era serialization formats are different concerns.

## Staff workflow

1. Open **Academic catalog → Courses** and open the course. Save course and prerequisite edits first.
2. Use **Titles & description** for the optional transcript title (80 characters) and plain-text description (10,000 characters). Blank text is explicitly unspecified. Use **Course subject** for an explicit draft classification; subjects are not inferred from the course code.
3. Use **Departments & levels** to save all three classification choices together; see [classification setup](course-classifications.md). Select **Published definitions**, enter a reason and choose **Review current definition**. Earlier publications are paged oldest first, 20 at a time.
4. Inspect the complete definition. An unconfirmed review expires after 20 minutes. **Refresh course review** prepares a fresh review; **Return to definitions** lets staff revise the reason. The draft reason remains in page memory across review and history pages, never browser storage.
5. Choose **Publish reviewed definition**. Confirmation records the actor, protected reason and actual acceptance time, and displays the retained publication reference.

Publication is separate from course activation, offering activation, registration and billing. The first publication records a decision made now; it does not infer an earlier academic approval from an existing row.

## Optional course numbers

A full course code remains the catalog identifier, such as `MATH.101`. The optional **Course number** stores a separate display value, such as `101` or `001A`, when an institution needs that part independently. It is text, accepts up to 32 printable characters, preserves leading zeros/suffixes/case, and may be blank. Numbers need not be unique across subjects or courses. No value is parsed from a course code or used as a database identity, credit quantity or degree rule.

Use **Academic catalog → Courses → a course → Titles & description** to save the number alongside the existing transcript title and description. Saving uses the current course revision, catalog authority, course lock and atomic required audit. Current actor/permission checks run again after locking and auditing. A number-only edit invalidates pending publication and clone reviews. A clone copies the reviewed number into its inactive draft; accepted retries preserve the original copy after later source edits.

Publication retains the explicit string or blank in the existing `CourseDefinitionWasPublished` definition. Earlier definitions that omitted this field remain **Not recorded in this publication**, distinct from a new **Not specified** choice. The same current `CourseText`/`CourseDefinition` model decodes actual earlier facts without rewriting them or manufacturing a number. Selected section/academic/transfer publication references retain their own number; a later draft or publication cannot relabel them. The number does not change recorded credits, grades, fees, enrollments, full course codes or printed transcript layouts.

The public section catalog displays and searches the selected course publication's number. An unbound section uses only the current published number; a private draft never supplies that value. A bound earlier publication with no number does not fall back to today's number. Existing parameterized literal searches and cursor limits apply.

Report Studio section fields distinguish **Current draft course number**, **Current published course number** and **Retained course number**, with publication-recorded flags. Academic-credit fields use the accepted course-version reference and flag. A false flag means the selected older publication did not capture a number; a true flag with a blank value is an explicit unspecified choice, and a missing binding stays blank. Existing dataset/field permissions, private results, named joins, captures and exports apply; reports do not replay every publication. A later course edit leaves historical values alone.

[Course import mapping](catalog-imports.md#optional-details-and-dependencies) can optionally map the number. New courses stay inactive drafts. Explicit linking requires mapped values, including a blank, to match; omitted number mappings preserve current values. New mapped values bind the protected review/fingerprint, while original unmapped receipt fingerprints retain their earlier meaning. Imports never overwrite a conflicting existing number or publish a definition.

Migration `20261121000001_AddSisCourseNumbers` adds nullable draft/published/retained number fields plus recorded flags in existing course-description/version projections. No new table, journal, permission or inferred backfill is introduced. The migration is idempotent and refuses destructive rollback. It compiled in all five framework SQL dialects. On the inspected development MariaDB database it applied in 0.40 seconds: every original column value across four description rows and one retained version matched before/after hashes; all five journal/support/course/audit checksums matched. Existing number fields remained blank and earlier recorded flags false.

Validation: **102 focused PHP tests / 746 assertions**, separate real-provider private/CSRF checks, and **four browser workflows** for authoring/publication, optional assistance, import mapping/retry and public discovery. Coverage includes leading zeros/suffixes/Unicode/bounds, old absent versus new blank definitions, stale reviews, actor/audit rollback, clone/accepted retries, mapped conflicts/unmapped preservation, retained portal/academic report values, migration reruns and five dialects. Source coding standards, JS syntax and whitespace checks pass. Checks were focused and low priority with time/memory limits and one browser worker. Populated multi-engine/load and operational reconstruction qualification remain open.

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

Existing migrations supply the course text, course bindings, section publications and offering-selection tables. The alpha model consolidation requires no additional schema migration. [Course subjects](course-subjects.md) documents subject setup. Verified institutional awarded-course and exact-publication corrections now use the [existing academic-source review](academic-credit-history.md#correct-the-awarded-course), preserving original registration/offering definitions and earlier documents. Further classifications, complete policy references, enrollment reopening and operational rebuild tooling remain roadmap work.

Fictional checks cover publication revisions, confirmation time, expiry, actor/permission changes during waits/replay, expected versions, exact retries, strict definitions, audit/projection rollback, retained student and portal/report facts, grouped activation, multilingual bounds and inaccessible/tampered requests. Provider checks exercise actual private/CSRF routes. Browser checks exercise refresh/revision, retained drafts, keyboard use, literal text, reflow and accessibility scans.

## Reviewed course imports

Course imports now share the [catalog-import workflow](catalog-imports.md) with academic programs: field mapping, explicit source linking/exclusion, optional reference dependencies, expiring reviewed confirmation and original-result retries. New courses remain inactive drafts; existing definitions and historical publications are never overwritten. The consolidated guide covers setup, examples, permissions, privacy, atomicity and limits.

## Prerequisite choices

Open **Academic catalog → Courses → a course → Edit prerequisites**. By default, meet every requirement; choose how many distinct courses are needed within each list. One course is the default. For example:

- Requirement 1: **at least 2 of MATH.101, MATH.110 and MATH.120**.
- Requirement 2: **ENG.101 OR ENG.110**.

This means two different math courses **and** either writing course. For a list with several courses, **Courses required** sets the minimum. A course can appear in more than one requirement and satisfy each applicable requirement. These are prerequisite eligibility checks, separate from degree-audit course groups and their credit-sharing allocation policies. Repeating one course does not satisfy a two-course minimum; the count concerns distinct institutional course identities, not attempts, credits or GPA.

Each requirement also has **Minimum grade points**. Leave it blank for any verified passing grade, or enter the grade-point minimum for each counted course, with up to three decimal places. This is the grade definition's per-course point value, not a percentage, credit-weighted quality-point total or cumulative GPA. For example, a 2.675 minimum accepts 2.675 but excludes 2.674; zero is an explicit minimum, distinct from leaving it blank. A non-passing grade never qualifies even when its point value exceeds the minimum.

The threshold is an absolute grade-point value; it does not assume a four-point scale, infer a letter grade or convert between global and program grading scales. Staff should choose a value appropriate to the courses and applicable institutional grading definitions. The technical range follows the existing grade-definition model, from zero through ten points. Evaluation uses the student's retained actual grade facts rather than today's grading library. Transfer and non-GPA grades can qualify when recorded as passing and meeting the minimum. Unknown grades and work outside the pursuit's approved scope cannot qualify.

For rules with recorded grade minima, the verifier retains one strongest passing attempt per course, with a stable identity tie-breaker. Shared courses may meet one requirement's minimum while falling below another's. Retakes never increase the distinct-course count. This eligibility choice does not alter the degree-audit repeat-credit/GPA policy or earn additional credit.

The editor supports up to 100 requirements, 100 distinct courses and 500 total choices. A course appears only once within a requirement and cannot require itself. An empty requirement or a count outside one through its course count must be corrected before saving. Removing a choice preserves the required count and explains when more courses are needed; it never silently lowers the minimum. Removing every requirement explicitly means no prerequisites.

### Alternative prerequisite requirements

Use **Allow alternative requirements** when completing one route can replace another. **Requirements needed** controls how many of the displayed requirements must be met. Leave the control off for the existing all-required rule.

For example, build Requirement 1 with **MATH.101 and MATH.102**, set its **Courses required** to two, and build Requirement 2 with **STAT.201**. Enable alternatives and require **one** requirement. A student qualifies through the two-course mathematics route **or** the statistics route. Requiring two of three requirements is also supported. Each requirement keeps its own optional per-course grade minimum, and a failed/unknown course never becomes passing merely because alternatives are allowed. Existing cross-requirement course reuse still applies; this does not change degree-audit sharing rules.

Co-requisites remain independently required under the institution's existing registration policy. Alternatives do not make a lab optional or treat a simultaneously selected prerequisite as already completed. Nested routes are supported below. Other eligibility inputs remain separate policy work.

Removing a requirement does not silently lower an explicit overall minimum. Fix the minimum or restore enough requirements before saving. Explicitly removing all prerequisite requirements clears the overall minimum. Cloning preserves the selected requirements and their minima; it rejects a partial selection that cannot meet them. The older single-reference addition path directs alternative-rule changes to this editor rather than accidentally making a newly added course optional.

Draft saves remain audited CRUD. Course publications retain the overall minimum only when explicitly selected; absent earlier values retain the all-required meaning. New enrollment evidence retains the actual overall rule, course counts, grade floors and verified support in the existing protected journal. Registration review shows how many requirements are met and labels unmet alternative routes without presenting every route as mandatory. A changed overall minimum invalidates pending publication/registration reviews even if the student still qualifies; final source checks roll back coupled seats, credit and charges on changes before completion. Original accepted retries keep their earlier result.

Migration `20261120000003_AddSisPrerequisiteRequirementMinimum` adds nullable `requirement_minimum` fields to the existing draft and course-version projections. It creates no journal, rewrites no published rules and fabricates no past decisions. SQLite integration and compiler checks cover the five framework-supported SQL dialects; live engine qualification remains separate. Configured MariaDB migration and unchanged protected-history checksums were verified.

Course selection uses the existing bounded catalog search and paging. The course being edited, courses already in that requirement and unavailable courses cannot be newly chosen; courses in other requirements remain selectable. Previously assigned retired references can remain. Your choices, counts and optional grade minima stay in page memory when using a picker or returning from publication review. A stale or failed save leaves the draft visible. If you make later edits while a save is in flight, those edits remain unsaved instead of being discarded by its response.

Choose **Save requirements**, then **Review course publication** to review the saved rule with the other course facts. Publication remains disabled while the editor has unsaved or incomplete requirements. The publication review expands named choices and counts on demand; displayed names are current catalog labels, while course identities, grouping and required counts belong to the retained publication. Changing only a course count or grade minimum invalidates an earlier publication review. Review expiry, required reasons, actor/authority checks and original-result retries remain in the existing course publication workflow.

Select that publication when setting up an empty section, or use the existing reviewed offering amendment for future registrations. A section already bound to another publication keeps its earlier prerequisite rule. Older unbound sections retain the existing current-draft eligibility fallback; accepted registrations still record their actual assessed groups and support. Saving draft choices or publishing a new revision never reinterprets an earlier accepted registration or existing academic credits.

Student registration, individual staff registration, grouped registration, selected schedule plans and add/drop section changes share the same pursuit-scoped verifier. It uses known passing institutional/transfer coursework permitted for the current pursuit, including separately approved reuse where applicable. Selecting a prerequisite in the same proposed schedule does not make it completed. Reviews show each requirement's minimum and verified count, plus the number of additional requirements needed under the overall rule. Course labels use response-local positions without exposing supporting academic-record identities. A course-count, overall requirement-count or grade-minimum change requires another registration review even when the student still qualifies.

Before recording a new registration, the shared enrollment ledger checks the selected course's eligibility facts against its protected publication using the existing bounded history reader and shared command budget. A group or multi-section confirmation shares verified immutable course definitions only within that transaction; each current projection is still compared against the verified facts. Different publications of the same course remain separate. Final course checks reuse that verified definition without replaying it again, and a new command starts with fresh verification. A modified retained projection cannot weaken a required course count or grade minimum. A failed check rolls back the command's coupled effects. Ordinary listings, label searches and editor reads continue to use bounded read models; they do not replay publication history.

New registrations acquire the target course lock after student/person/section locks and before reading its policy. Draft editing and publication use that same course boundary. After the required final audit, current course identities, publication selection, credits, activity and prerequisite rules must still match the assessed facts. A changed draft or retained projection rolls back the related registration, credit, billing, receipt and audit effects. Reviewed groups repeat the course check after their final coordinator audit; multi-section plans share verification through their existing per-section completion checks. Original accepted retries verify current access and enrollment history without reassessing today's course policy.

New enrollment metadata retains exact requirement groups, explicit counts and optional grade minima alongside candidate course identities and verified supporting references. When grade minima are present, each supporting reference also retains its verified per-course grade points in the existing protected enrollment event. A later rule edit, grade correction, drop or publication cannot rewrite acceptance evidence or repeat a completed registration/charge. Existing all-required publications and earlier prerequisite evidence retain their exact shapes and meanings. Earlier choice groups without explicit course counts still mean one course per requirement; absent grade minima preserve their earlier passing-grade meaning. Reading historical definitions does not add inferred groups/counts or rewrite event bytes.

Course cloning preserves the original grouping, counts and grade minima for the explicitly selected courses. Omitting alternatives may narrow a choice, but retaining fewer courses than a group's minimum blocks both the clone screen and command. Omitting every course in a group removes that requirement from the new inactive draft. Existing individual add/remove endpoints update the same draft groups: adding an explicitly required course adds a singleton requirement unless already required alone, even when it appears in another choice group; removal preserves the remaining count and rejects an underfilled group. There is one draft authority and one retained course definition.

Migration `20261119000009_CreateSisPrerequisiteGroups` adds the course-owned CRUD draft table and nullable retained-projection field. `20261119000010_AddSisPrerequisiteMinimums` adds nullable text fields for the draft and retained count lists. Absence preserves earlier one-course meanings. The flat relationship list is a checked derived index written atomically with the groups; shared courses appear once in that index. No course, enrollment, event, receipt or historical support is backfilled. Schema definitions compile for the five framework database dialects, with isolated SQLite behavior checks. The native MariaDB upgrade to `20261119000010` left the existing publication without inferred counts, preserved its complete earlier course-field digest, and kept all twenty events plus the event/stream-head/operation-table checksums unchanged. Other PDO backends remain subject to framework schema support.

This stage uses retained passing-grade and grade-point definitions. Co-requisites use the separate required-course list described below. Nested counted expressions are supported below; equivalence expansion and registrar prerequisite overrides remain separate policy work. No SQL, PHP, scripts or executable predicates are accepted by this editor.

Migration `20261119000011_AddSisPrerequisiteGradePoints` adds nullable text fields for draft and retained grade minima. NULL preserves the earlier passing-grade contract without inferred thresholds or support points. The migration does not backfill academic records, publications or events. Its definitions compile for MySQL, PostgreSQL, SQLite, SQL Server and Oracle; SQLite fixtures exercise publication, registration, rollback and replay behavior. The applied native MariaDB upgrade preserved the earlier retained-course digest, all twenty events and the event/stream-head/operation-table checksums; the earlier publication still has NULL grade minima. Multi-engine load/concurrency and independent operational acceptance remain open.

Fictional bounded verification covers a 25-student confirmation with 21 actual course publications: the selected course stream uses two pages total, instead of two pages per student. A separate command reads history afresh. Additional checks cover different publications of one course in a schedule, changed projections after cache population, final-audit rollback, stable lock ordering and partial scans that never enter the verified cache. The invocation-local map is bounded to 100 publications and 100 section selections and uses the existing shared history allowance. It stores no student evidence and is never written to disk or reused between browser requests. This is a targeted replay-count check, not a production capacity benchmark; no schema, journal or historical conversion is added.


### Nested prerequisite routes

Use **Edit prerequisites → Arrange rule** when a single overall count cannot express the requirement. For example, **A AND (B OR C)** requires A plus either B or C; completing B and C without A does not qualify.

1. Prepare the numbered requirement cards with their course choices, course counts and optional grade-point minima.
2. Choose **Arrange rule**. Select B and C within the same branch and choose **Group selected**.
3. Set that new branch to **Complete any one**, leaving the overall rule at **Complete all**. A branch may instead require a chosen number of its children.
4. Use the arrow buttons to change the display order with a keyboard or pointer. **Ungroup** deliberately moves a branch's children into its parent; review the resulting rule.
5. Return to the requirement cards, save, then review and publish the course definition. Publication review displays the same nested rule and numbered course details.

No SQL, executable expressions or new course copies are involved. Co-requisites remain separately required under institutional policy. One qualifying course may satisfy multiple prerequisite blocks, while each block still counts distinct courses and enforces its own grade floor. This does not change degree-audit sharing policies.

Every numbered requirement must appear exactly once in the rule. The server accepts up to five branch levels, 199 total rule items, 100 requirements, 100 distinct courses and 500 course choices. Evaluation visits the bounded rule; it does not enumerate possible course combinations. **Simple rule** deliberately returns the draft to requiring every listed requirement. Remove requirements in that mode, then arrange a new rule if needed. Adding a requirement to a nested rule adds it at the root; review that root count before saving.

Drafts remain revision-checked, audited CRUD. The existing course-publication event retains `prerequisite_rule`, and new accepted enrollment evidence retains `requirement_rule` with its original pursuit-scoped support. Earlier publications without either field preserve their flat counted/all-required meaning. A section's chosen course publication keeps its original rule after draft edits or later course publication. Ordinary lists and searches still use bounded projections; registration commands verify the selected publication and recheck current sources through final confirmation.

Student, staff, grouped registration, schedule plans and section changes use the shared evaluation and readiness view. A nested review marks each branch and requirement **Met** or **Not met**, distinguishing unused alternatives from required missing work without exposing supporting credit identities. Recorded registration summaries identify a satisfied nested rule rather than inventing a flat required-count claim. Existing completed retries retain their original result after later edits. A changed rule invalidates pending reviews, and a final-check failure rolls back seats, academic credit, charges and receipts together.

Cloning retains the full nested structure. Staff may narrow choices within a block if enough courses remain for its count; omitting whole nested blocks is rejected unless all prerequisites are cleared. Restructure the new inactive draft explicitly rather than silently weakening an inherited expression. The older single-reference writer directs nested changes to the full editor.

Migration `20261120000005_AddSisPrerequisiteRules` adds nullable `rule_json` to the existing draft and course-version projections. It creates no event table, new aggregate, historical conversion or inferred rules. Compiler checks cover the five framework dialects; live multi-engine qualification remains separate.

The configured MariaDB upgrade preserved all twenty existing events and the checksums of the event, stream-head and operation tables. Its existing retained course publication still has no nested rule. Fictional-data checks cover nested counts, grade floors, source edits, protected projection verification, original retries, coupled registration/billing rollback and the accessible editor/publication flow.

### Co-requisites

The same entry-requirements editor has a separate **Co-requisites** list. Add each required companion course, such as a laboratory, then choose **Save requirements** and review/publish the course. All listed companions are required; completed-course prerequisite alternatives, course counts and grade-point minima remain separate. Up to twenty co-requisites are supported, within the combined limit of one hundred distinct entry-requirement courses. A course cannot require itself.

Co-requisites belong to the retained course publication. Section version selection and later amendments use that same definition. Cloning preserves the explicitly selected co-requisite references independently of prerequisite groups; it does not turn a lab into a completed-course prerequisite. Individual prerequisite add/remove actions preserve unrelated co-requisites.

Use **Registration policy** in registration settings to choose whether qualifying prior completion counts and whether unsupported drops are blocked or warned. See [registration and linked drops](registration-history.md#co-requisites-and-linked-drops) for the staff/student workflow. These institutional settings are audited CRUD configuration; accepted registration/drop evidence remains protected in the existing authoritative enrollment events.

Migration `20261119000012_AddSisCorequisites` adds nullable draft/publication fields and three defaulted fields to the existing registration-policy table. It creates no event table and performs no historical conversion. Earlier publications and decisions without co-requisites retain their exact representations. The configured MariaDB upgrade preserved all twenty events and the event, stream-head and operation-table checksums; the existing publication has no inferred co-requisites. Isolated tests cover SQLite behavior and schema compilation for the five framework databases.

## Qualifying prerequisite coursework

Open **Academic catalog → course → Prerequisites**. Under **Overall prerequisite rule**, choose **Qualifying prerequisite coursework**:

- **Institutional or transfer** keeps the existing default: either source can satisfy a course prerequisite.
- **Institutional work only** defaults prerequisite requirements to qualifying institutional coursework.
- **Transfer work only** defaults prerequisite requirements to qualifying posted transfer coursework.

This setting is the default for prerequisites for this course. A requirement can explicitly override it as described below. It does **not** decide which credits count toward a student's degree, remove earned credit or change a transfer award. Co-requisites retain their separate institutional policy. Each prerequisite requirement still keeps its course count and grade-point minimum; flat alternatives and nested routes continue to work together with the source restriction. Only verified passing work allowed for the current program pursuit qualifies. Unknown earlier completion is not inferred.

Add at least one prerequisite before selecting a source restriction. Removing every prerequisite resets this setting to the default; co-requisites remain independent. **Save requirements** saves the working definition, preserving edits made while a save is pending. A stale save leaves the proposed choice available for review. Saving setup does not append an academic decision or change an earlier publication/student record.

Use **Review course publication** to retain the policy in an immutable course version. A section using that selected course publication keeps its policy after later draft edits or publications. Sections without a selected publication continue their existing working-definition registration behavior; publishing alone does not automatically replace their selected definition. Review the section's retained course selection separately when changing future registration requirements.

Course cloning preserves the policy when selected prerequisites remain. Partial course removals retain it for the remaining requirements, along with their counts/grade floors; explicitly cloning or clearing every prerequisite clears the restriction. Ordinary relationship edits cannot silently change a remaining policy.

Staff and student registration reviews explain the selected source beside prerequisite readiness. A registrar's independently authorized exception can permit registration with an unmet source requirement; it awards no completion or credit. Retained registration evidence identifies the original source policy and actual qualifying academic references. Existing accepted retries keep their original evidence after later policy or grade changes.

Apply migration `20261123000008_AddSisPrerequisiteCreditOrigin` before using this setting. It adds nullable policy columns to the existing draft and course-version projections. Earlier null/absent values keep the original either-source behavior; no earlier publication, event or institution choice is rewritten. Catalog authority, CSRF/private responses, publication review/confirmation and registration source/authority/audit checks use their existing boundaries. The prerequisite-save API requires an explicit nullable `credit_origin` field. Explicit course equivalence and further institutional prerequisite policies remain separate roadmap work.

## Coursework source for each prerequisite requirement

Implemented November 23, 2026. Every numbered requirement in **Course → Prerequisites** now has **Coursework source for this requirement**:

- **Use course default** inherits the overall **Qualifying prerequisite coursework** choice.
- **Institutional work only** and **Transfer work only** accept only that source for the courses counted in this requirement.
- **Institutional or transfer** explicitly accepts either source here, even when the overall default is more restrictive.

For example, keep the overall default **Institutional or transfer**, set the writing requirement to **Institutional work only**, and leave the mathematics requirement at **Use course default**. The student must complete writing at this institution while qualifying mathematics may be transferred. This configures registration eligibility; the degree audit's separate source policies still determine how awarded credit is allocated.

Each card shows **Effective coursework source** beneath its selector. This updates when either the default or override changes, so inherited choices and the full selected wording remain clear on narrow screens and with enlarged text.

The requirement's source, passing status, program-pursuit allowance and grade minimum must hold for the same actual attempt. A high institutional grade cannot supply the grade minimum for a different transferred attempt. If the same course appears in multiple requirements with different sources, the evaluator keeps up to one best qualifying attempt per course/source and applies each requirement independently. One course is still counted once within a requirement, even when both sources qualify. Counted flat alternatives and nested routes retain their original semantics; missing alternatives can remain when another allowed route meets the rule. Registrar prerequisite exceptions preserve the honest unmet coursework rather than inventing completion.

**Save requirements** preserves changes made while a save is pending and keeps stale proposed choices available. **Review course publication** records the actual default and optional per-requirement overrides. Existing selected section publications and accepted registration evidence remain fixed after later draft/default/grade changes. Current staff/student registration reviews name each requirement's effective source without exposing academic record references. Changing an override requires a fresh unaccepted registration review; final source-policy changes roll back enrollment, academic credit, charges and receipts together.

Cloning and partial removal keep each remaining requirement's override beside its count and grade minimum. Removing a requirement removes its override; adding one starts with **Use course default**. Clearing every prerequisite clears all overrides and the course default while co-requisites remain independent. Nested rules retain their existing all-or-clear restriction when cloning/removing whole requirement positions.

Migration `20261123000011_AddSisPrerequisiteRequirementOrigins` adds nullable `requirement_origins_json` columns to the existing draft and course-version tables. Earlier rows stay null and earlier publications/registration evidence retain their exact absent-field shape. New course definitions optionally retain `prerequisite_requirement_credit_origins`; new registration evidence optionally retains `requirement_credit_origins`. The current save API requires explicit nullable `requirement_credit_origins`: use null to inherit all, or a list matching requirement positions with null/institutional/transfer/either entries. Empty requirements require null. No historical conversion, new journal, notification, report or automatic grant is introduced.

## Completion dates for prerequisite requirements

A numbered prerequisite requirement can now restrict **actual completion dates** independently of its course choices, coursework source and grade minimum.

1. Open **Academic catalog → Courses → a course → Prerequisites**.
2. In the requirement's **Actual completion dates** panel, enter **Completed from**, **Completed through**, or both. Dates are inclusive. Leave both blank to allow any completion date, including an earlier unknown date when the course otherwise qualifies.
3. Choose **Save requirements**, then **Review course publication**. Review the boundaries alongside course choices, counts, source and grade rules. Use the existing course/offering selection workflow to put a published definition into use.
4. To remove a restriction, clear both dates and save/publish the new policy. Existing selected publications and accepted registrations retain their earlier definitions and evidence.

For example, a requirement with **Completed from: 2021-01-01** accepts otherwise qualifying work completed on or after that date. This is a fixed published boundary, not a rolling “last five years” calculation. A later cohort can select a different publication with different dates.

Only the actual completion date retained in verified institutional or transfer history can meet a dated requirement. An unknown date does not meet it; the system does not substitute term dates, academic-period start, grade-effective date, recording time or today's catalog. Use the separately authorized [academic completion correction](academic-completion-dates.md) when supported evidence establishes a missing or incorrect fact. An unrestricted requirement preserves its existing behavior. These dates do not control co-requisites, award credits, GPA, degree-audit expiration or financial eligibility.

Source, completion date, passing status, pursuit allowance and grade minimum must apply to the **same attempt**. An older high grade cannot lend its grade to a newer attempt inside the window. Different requirements can use different actual attempts of the same course; each course still counts once within a requirement. Flat alternatives, counted/nested routes and registrar exceptions keep their current meanings. An exception retains the honestly unmet date/coursework policy rather than manufacturing completion.

The editor preserves date drafts after stale/failed saves and edits made while a save is pending. New requirements start unrestricted; removal/subset cloning carries the remaining requirements' own boundaries. Publication/clone reviews and staff/owner registration readiness show the authored windows. Ordinary readiness does not expose supporting academic IDs or actual student completion dates. Date controls keep their native calendar, visible labels and accessible validation, with narrow/enlarged-text reflow and white inset panels.

New definitions optionally retain `prerequisite_completion_windows`; new registration evidence optionally retains `completion_windows` and each selected support's exact nullable `completed_on`. The evaluator retains separate candidates by course/source/completion date when windows are configured, bounded by the existing 200-source inventory; it never merges a grade/date from different attempts. Earlier absent fields stay absent. Later completion corrections or policy edits do not rewrite an accepted registration's proof or original retry. Pending reviews require fresh source checks, and final completion-policy drift rolls back seats, academic credits, charges, audit and receipts together.

Migration `20261124000003_AddSisPrerequisiteCompletionWindows` adds nullable TEXT `completion_windows_json` fields to the existing draft and course-version projections. It is additive, retry-safe, compiles for five supported dialects and refuses destructive downgrade. Existing rows remain null; no business fact, policy or event is backfilled. The current prerequisite-save API requires explicit nullable `completion_windows`: null for unrestricted requirements, or a position-matched list containing null or `{from: date|null, through: date|null}` with at least one boundary. Empty prerequisites require null. No new event type, journal, report, permission grant or automatic notification is introduced.
