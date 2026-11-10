# Course group library and degree audit builder

Course groups and degree audits are separate authoring elements. A **course group is an independent, reusable block**; it belongs to no degree, program or academic year. A **degree requirement definition composes particular published versions of those blocks** for a program and academic year. General education can therefore be shared across many degrees, while a specialized group may be used by just one.

This stage provides authoring, immutable publication, historical previews and cloning. Explicit [student program requirement assignment](student-degree-requirements.md) is now implemented. [Student waivers, substitutions and minimum exceptions](student-degree-exceptions.md) are also implemented. Student evaluation, transcript allocation and specialization exclusions remain pending. The preview shows requirements without claiming that a student completed them.

## Setup and navigation

1. Apply `20261108000005_CreateSisDegreeAuditTemplates` and `20261108000006_CreateSisCourseGroupLibrary` through the normal migration runner after their existing calendar/history dependencies. The new migration creates only the independent group draft library. Development checks have not applied it to a working database.
2. Set up academic years and publish the course definitions that the library will use. Catalog years remain derived; there is no catalog-year setup form.
3. Open **Academic catalog → Course group library**. The library is available from the catalog tabs without choosing a program.
4. Build and publish reusable groups, then open **Academic catalog → Programs → a program → Degree audit requirements** to compose a degree.

Both workspaces open full-width dialogs with a source panel, visual requirement canvas and settings panel. Native checkboxes and action buttons complement drag-and-drop. Settings use progressive disclosure and labeled policy choices. Search, publication-version browsing, review and save use the existing private AJAX envelope. Unsaved degree fields remain in page memory when staff enter the group library and return; nothing is written to browser storage. Close/reload ends that in-memory editing session: save working drafts before leaving.

## Course group builder

1. Choose **Create course group**, enter its name, then create the independent draft. No program/year selection is requested.
2. Choose **Require credits** or **Require courses**, then enter the required total. Optionally set **Minimum grade points for each course**. The institution chooses this value; 2.0 is only an example. **Maximum grade points** comes from grade definitions, with a 4.0 display default only before setup. **Choose grading context** lets a reusable group use a program override while authoring without giving the group a program owner.
3. Choose **Add requirement**. Select its card, then choose **All of these**, **Choose a number**, or **Eligible courses**. These are direct controls, not a series of dropdown fields.
4. Search courses in the source panel and expand a course to browse its published versions. Check the version you intend to use and choose **Add checked courses**, or drag that published version onto a requirement card. The builder never silently selects today's latest publication.
5. Add further cards, remove courses, and arrange requirements by dragging or with **Move requirement … up/down**. A course may appear in multiple rules, but one group uses a single explicit publication per course. To replace it, select the intended publication. The builder shows its title/credit changes and affected requirement cards; **Adopt selected course versions** explicitly replaces the reference throughout the working group. Previously published groups are preserved.
6. **Requirements preview** shows an expandable tree with course labels, credits, alternatives and thresholds. **Save group draft** stores incomplete work. **Save & review publication** saves, asks for a reason, then displays the exact saved definition. **Publish reviewed version** publishes only that reviewed snapshot.
7. **Version history** retains earlier definitions. Publishing a new version does not change any degree that selected an earlier version.

Every rule in a group is required along with the selected group quantity. The optional grade-point minimum applies to each applied course individually, using its retained grade definition; it is neither an average group GPA nor credit-weighted quality points. A passing course below that minimum cannot count as completed in this group. For example, an institution may require 1.7 out of 4.3, 2.0 out of 4.0, or another value its scale permits. Blank means no additional point floor.

New group definitions keep credits and course count mutually exclusive. A course-count total counts distinct eligible courses. Grades, allocation, repeated awards and actual completion still need the student evaluator.

Rule controls:

| Rule | Meaning |
| --- | --- |
| All of these | Require every listed course. |
| Choose a number | Require at least the stated number of distinct listed courses. |
| Eligible courses | Listed courses may satisfy the selected credit or course-count total. The group needs a positive total in the selected unit. |

`MATH.101; ENG.203; CS.201 OR CS.301; CLIT.505` becomes four cards: Math, English, choose one of the two CS courses, and CLIT. With three-credit courses the group minimum is 12 credits. This relationship defines requirements; it does not allocate student credit or authorize double counting.

The grammar currently uses explicit course lists. Subject/level/department criteria depend on normalized, historically versioned classification fields. No SQL, PHP, callbacks or executable expressions are accepted. Nested rule trees and specialization relationships remain later grammar stages.

## Degree audit builder

1. Open a program's **Degree audit requirements** and choose **Set up an academic year**. Select an existing academic year to open its draft or create a blank one. Searches trim text and omit a blank search parameter.
2. Enter the requirement title, total earned credits and resident credits. Expand **GPA & program length** for optional cumulative/overall GPA minimums and expected length in months. Blank GPA means no minimum. Decimal values support three places and become integer thousandths in the API/domain.
3. In **Choose library blocks**, search published groups, expand one and explicitly select its version. Choose **Add checked blocks**, or drag that version onto the canvas. The canvas shows its retained requirement tree and a pinned-version badge.
4. Arrange blocks with drag-and-drop or move buttons. Removing a block only removes the reference from this degree draft; it never deletes the library group. The same group can be reused by any other program or requirement year.
5. **Edit block … in library** opens its independent working draft and provides a return action. Saving or publishing the group does not silently replace the degree's selected version. To deliberately adopt a different publication, select that version from the source panel and add the same group again. A degree uses one version of each group.
6. Choose the institutional repeat-credit, repeat-GPA and transfer-GPA policies. Save incomplete drafts with **Save degree draft**. **Save & review publication** presents the exact resolved groups, general minimums and policies before **Publish reviewed version**.
   Degree publication reviews expire after 20 minutes. **Refresh publication review** retains your reason and prepares the current saved definition for another review. Confirmation records the actual publication time. A completed confirmation remains safe to retry after expiry; it returns its original result without publishing again.
7. **Published requirements → Inspect version** resolves original published groups and course labels. **Clone version … to another academic year** creates a fresh draft retaining the exact group-version references and policy inputs; it does not copy or give ownership to the groups. Existing target drafts cannot be overwritten. Review and publish the clone independently.

Two groups may reference the same course with different course publications. Each block retains its own approved definition. Resolving overlapping eligible work and allocation belongs to the future student audit engine; authoring does not assume a greedy allocation or sum group minimums as proof of degree feasibility.

## Grading scales

Use **Academic catalog → Grading scales** for the global setup, and **Programs → Program grading scale** for an override. [Grading scale setup](grading-scale-setup.md) explains grade score ranges, GPA points, inheritance and historical boundaries. Degree previews and publications use the program override when present; otherwise they use the global scale. Reusing a group never silently converts grade points between scales. A group floor or degree GPA minimum above the resolved maximum blocks publication.

## Institutional policies retained in publication

| Setting | Supported choices |
| --- | --- |
| Repeated-course earned credit | Highest eligible earned award per course; or every eligible earned award. |
| Repeated-course GPA | All eligible graded attempts; highest eligible grade per course; or latest eligible graded attempt per course. |
| Transfer awards in overall GPA | Exclude transfer awards; or include accepted awards explicitly marked GPA eligible. |
| Course waivers | Remove a required course everywhere without creating earned credits or GPA points. Credit/GPA minimum changes require separate attributed exceptions. |

The three selectable policies start unselected and must be explicit before degree publication. These are frozen inputs for this program/year, not an installation-wide mutable profile. This stage does not recalculate students or apply policies. Unknown earlier policy inputs remain unknown.

## Existing inline drafts and publications

Earlier degree schemas stored editable groups inline. Opening such a working draft now presents **Move draft groups into the library**. This explicit conversion creates independent group drafts and replaces only the working degree definition with references to them. It preserves existing course references, minimums and known policies. It does not publish any group or degree, rewrite old events, infer that similarly named groups are identical, or assign new requirements to students. Repeating a completed conversion creates no duplicate groups. All group creation, degree revision and audit writes roll back together on failure.

Publish each converted group and explicitly attach its chosen version before publishing the degree. Unpublished group references are allowed in drafts but rejected for degree publication. A group named the same as another group remains a distinct identity until staff deliberately choose an existing library block instead.

Publication schemas remain strict:

- Schema 1 degree publications retain their original inline grammar and missing policy inputs.
- Schema 2 degree publications retain their inline grammar and explicit institutional policies.
- Schema 3 degree publications contain general settings, policies and group/version references; editable inline groups and a degree-level course list are forbidden.
- Degree publication schema 4 adds the resolved grading-scale snapshot to the schema-3 composition. Earlier degree publications keep their original known/unknown inputs.
- Course-group publication schema 1 retains the earlier credit/average-group-GPA contract unchanged.
- Course-group publication schema 2 retains credit-or-course quantity, optional per-course point floor, derived maximum, authoring context and exact course references. Opening an older draft asks staff to explicitly adopt these settings. Its earlier average GPA is never copied into the per-course field; earlier publications remain intact.

Existing schema-1/2 event bytes and accepted operation receipts remain valid. Abandoned inline review tokens cannot create new publications. Cloning an older publication preserves its historical definition as a working inline draft until staff explicitly move its groups into the library. New degree publications use schema 4 with a schema-3 composition and retained scale. No fabricated historical publication or automatic backfill is introduced.

## Domain architecture, history and access

`Domain/DegreeAudit` owns the separate typed definitions, publication aggregates, ports and workflows. Application controllers parse typed input; Infrastructure provides SQL adapters. Group, rule, template and version identities use ULIDs.

`sis_course_groups` stores independent working drafts and reconstructible publication metadata. `sis_degree_audit_templates` stores working composition drafts scoped uniquely by program/academic year and their publication metadata. Neither table is an event store. `CourseGroupWasPublished` and `RequirementsWerePublished` both use the one existing authoritative protected `sis_history_events` table. Backups remain necessary for unpublished drafts. There is no group/history delete API.

Review is side-effect free and binds the exact definition, actor, draft revision, expected stream version, reason and trusted time in an encrypted token. Commit uses locking, expected-version append and atomic event/head/receipt/projection/audit writes. Stale drafts, changed context and competing publications fail without partial publication. Accepted exact retries return the original receipt after later edits; another actor or revoked permission cannot use the review.

Historical previews verify selected group/course publications against their protected streams, never today's mutable catalog labels. Group and degree roots enforce publication predecessor continuity; degree roots also enforce stable program/year ownership. Verified replay consumes bounded pages. History pages contain ten definitions; scoped cursors freeze their upper boundary. Library pages return at most 50 metadata rows with a keyset cursor, exclude draft JSON, escape literal search wildcards and use the published name for published-only search. No complete event history or entire catalog is loaded into the browser.

A degree has at most 25 group references. Each group permits up to 100 rules, 200 distinct course references and 50 courses per rule. Definitions are limited to 48 KiB of JSON, preserving the existing 64 KiB event limit. Incomplete drafts are allowed; published rules must be nonempty. Resident credit cannot exceed total credit. Structural validation does not prove institutional approval or complete curriculum feasibility.

Authoring requires `sis:catalog:manage`; academic-read/registration permissions alone do not grant it. Routes preserve framework CSRF protection, private/no-store responses and debug suppression. Curated history endpoints expose definitions without raw envelopes, actors or protected reasons. The builders use escaped DOM text, visible focus, labeled controls and keyboard alternatives; no real student records are used in tests.

## Student evaluation still to implement

The user confirmed that repeat handling, transfer GPA treatment and waiver effects should be explicit, versioned institutional policies. The updated waiver definition is authoritative: **a course waiver removes the requirement to take that course wherever it is required for the student**. A waiver does not manufacture earned credits, a completed attempt or GPA points. Changes to credit/GPA minima belong to explicitly attributed exceptions; per-pursuit waiver/minimum approval and revocation now use a dedicated registrar permission and protected history. A waiver is course based across the one student-program pursuit, including all its groups; a dual-degree pursuit has separate approvals.

Future work must preserve these distinctions:

- The explicit student-program version pin is implemented through reviewed attributed decisions; automatic matriculation decisions and protected lifecycle conversion remain separate work. Publishing a sheet still leaves existing students unchanged. See [assignment setup](student-degree-requirements.md).
- Implement current-degree evaluation and what-if results, explicit most-advanced-degree ordering and specialization relationships. What-if selection must not mutate the student's program or cohort. Requirement selection now records the actual pursuit date and derives an anticipated completion date from the retained publication length.
- Evaluate institutional and accepted transfer credit, repeats, GPA scopes, current-term in-progress work, eligibility and unused courses from retained academic facts and explicit policy inputs. Current academic/grade SQL writers still need their planned event-sourcing conversion; unknown historical policy inputs must remain unknown.
- Allocate each eligible attempt deliberately across competing groups, with deterministic, explainable results rather than a greedy order that can falsely report unmet requirements. An advisor's proposed applied-course change is a request; the authorized registrar's decision is an attributed, reasoned event, not a raw checkbox update.
- Apply approved substitutions (including not-yet-taken courses) in the future evaluator and add specialization-scoped exclusions. Waivers, substitutions and credit/GPA minimum exceptions now have reviewed approval/revocation histories, outside template cloning and ordinary reporting. Group course-count and per-course-point-floor individual exceptions remain unsupported; the earlier group-average-GPA exception does not alter either field.
- Introduce controlled projection rebuild/reconciliation operations and institutional retention/approval policies. A degree audit advises a decision; it never silently grants a credential.

## Verification

Fictional SQLite integration tests cover group reuse across programs/years, selected-version isolation, exact-reference cloning, explicit draft conversion, legacy event/receipt preservation, protected payloads, Codefy replay, strict schema separation, optimistic saves, stale/competing reviews, exact retries, permission/actor checks, bounded stable pages and atomic rollback. The actual provider/router checks exercise group and degree creation/review/publication with CSRF and private responses.

Browser checks exercise standalone group creation, course/version checkboxes, direct rule-type controls, block composition, native drag-and-drop and button alternatives, preserved degree fields while editing groups, publication review, escaped titles, mobile reflow, focus restoration and automated accessibility scans. All 97 SIS table definitions compile through the five installed framework dialects; runtime integration here uses SQLite only. Other database engines still require live locking/LOB verification.

The student program now has a **Degree requirements** action for inspecting its retained selection and, with explicit office permission, reviewing a new one. [Student requirement assignment](student-degree-requirements.md) documents its migration, permissions and historical controls.
