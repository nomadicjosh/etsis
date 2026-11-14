# Course group library and degree audit builder

Course groups and degree audits are separate authoring elements. A **course group is an independent, reusable block**; it belongs to no degree, program or academic year. A **degree requirement definition composes particular published versions of those blocks** for a program and academic year. General education can therefore be shared across many degrees, while a specialized group may be used by just one.

This stage provides authoring, immutable publication, historical previews and cloning. Explicit [student program requirement assignment](student-degree-requirements.md) is now implemented. The [adjusted requirements preview](student-degree-exceptions.md#preview-requirements-with-approved-exceptions) now combines a selected baseline with approved exceptions without calculating completion. [Student waivers, substitutions and minimum exceptions](student-degree-exceptions.md) are also implemented. Bounded student progress and course-group placement reviews are implemented separately; retained final evaluations, remaining historical equivalence and specialization exclusions remain open. The adjusted-requirements preview shows requirements without claiming that a student completed them.

## Setup and navigation

1. Apply `20261108000005_CreateSisDegreeAuditTemplates` and `20261108000006_CreateSisCourseGroupLibrary` through the normal migration runner after their existing calendar/history dependencies. The new migration creates only the independent group draft library. Development checks have not applied it to a working database.
2. Set up academic years and publish the course definitions that the library will use. Catalog years remain derived; there is no catalog-year setup form.
3. Open **Academic catalog → Course group library**. The library is available from the catalog tabs without choosing a program.
4. Build and publish reusable groups, then open **Academic catalog → Programs → a program → Degree audit requirements** to compose a degree.

Both workspaces open full-width dialogs with a source panel, visual requirement canvas and settings panel. Native checkboxes and action buttons complement drag-and-drop. Settings use progressive disclosure and labeled policy choices. Search, publication-version browsing, review and save use the existing private AJAX envelope. Unsaved degree fields remain in page memory when staff enter the group library and return; nothing is written to browser storage. Close/reload ends that in-memory editing session: save working drafts before leaving.

## Course group builder

1. Choose **Create course group**, enter its name, then create the independent draft. No program/year selection is requested.
2. Choose **Require credits** or **Require courses**, then enter the required total. Optionally set **Minimum grade points for each course**. The institution chooses this value; 2.0 is only an example. **Maximum grade points** comes from grade definitions, with a 4.0 display default only before setup. **Choose grading context** lets a reusable group use a program override while authoring without giving the group a program owner.
3. Choose **Add requirement**. Select its card, then choose **All of these**, **Choose a number**, **Take credits**, or **Eligible courses**. These are direct controls, not a series of dropdown fields. **Take credits** adds a positive credit minimum for that card's course list; it is independent of the group's total.
4. Search courses in the source panel and expand a course to browse its published versions. Check the version you intend to use and choose **Add checked courses**, or drag that published version onto a requirement card. The builder never silently selects today's latest publication.
5. Add further cards, remove courses, and arrange requirements by dragging or with **Move requirement … up/down**. A course may appear in multiple rules, but one group uses a single explicit publication per course. To replace it, select the intended publication. The builder shows its title/credit changes and affected requirement cards; **Adopt selected course versions** explicitly replaces the reference throughout the working group. Previously published groups are preserved.
6. **Requirements preview** shows an expandable tree with course labels, credits, alternatives and thresholds. **Save group draft** stores incomplete work. **Save & review publication** saves, asks for a reason, then displays the exact saved definition. **Publish reviewed version** publishes only that reviewed snapshot.
7. **Version history** retains earlier definitions. Publishing a new version does not change any degree that selected an earlier version.

Every rule in a group is required along with the selected group quantity. The optional grade-point minimum applies to each applied course individually, using its retained grade definition; it is neither an average group GPA nor credit-weighted quality points. A passing course below that minimum cannot count as completed in this group. For example, an institution may require 1.7 out of 4.3, 2.0 out of 4.0, or another value its scale permits. Blank means no additional point floor.

New group definitions keep credits and course count mutually exclusive. A course-count total counts distinct eligible courses. The student evaluator separately applies grades, actual earned awards, repeats and sharing; authoring totals do not establish completion.

Rule controls:

| Rule | Meaning |
| --- | --- |
| All of these | Require every listed course. |
| Choose a number | Require at least the stated number of distinct listed courses. |
| Eligible courses | Listed courses may satisfy the selected credit or course-count total. The group needs a positive total in the selected unit. |

`MATH.101; ENG.203; CS.201 OR CS.301; CLIT.505` becomes four cards: Math, English, choose one of the two CS courses, and CLIT. With three-credit courses the group minimum is 12 credits. This relationship defines requirements; it does not allocate student credit or authorize double counting.

Cards can use explicit course lists or a selected saved-rule revision. Subject/level/department criteria use normalized, historically versioned classification fields. No SQL, PHP, callbacks or executable expressions are accepted. Nested rule trees and specialization relationships remain later grammar stages.

## Group planning summary

**Group at a glance** shows the distinct listed courses, their combined catalog credits and the number of requirement cards. It updates immediately when staff edit a required total, change credit/course mode, add or remove courses, or adopt a different published course version. It uses the course details already loaded in the builder, with no background save or additional history request for each edit. Blank or unfinished quantities show guidance while keeping focus in the input.

A course appearing in several cards contributes its catalog credits once. Zero-credit courses still count as listed courses, and fractional credits retain their thousandths. Working drafts and published previews use the exact selected course publications; changing today's course credits leaves an older group's figures intact. Missing course details are reported as unavailable rather than silently treated as zero.

If the required credit total exceeds the listed catalog credits, the summary asks staff to review it. This is advisory: actual awards, repeats and approved exceptions can differ from catalog figures. Existing publication rules still apply, including the limit on a course-count requirement exceeding its distinct listed courses. The summary does not allocate completed work, evaluate grades or establish degree completion. It creates no new stored policy, publication format or event history.

The same derived summary appears in group previews, publication reviews and published-version inspection. It is calculated from retained definitions, not stored as another editable total.

## Accept other publications of the same course

Each named course keeps one **required publication** as its curriculum label and planning reference. A group can explicitly accept up to ten additional publications of that same course. These are academic allowances, not inferred equivalence from matching codes or today's catalog.

1. Add the required published course as usual, then choose **Manage accepted publications for …** on its requirement card.
2. Check the additional published versions that may satisfy this course. The screen keeps the required version separate and identifies each selected version's retained title and credits. Paging preserves the in-memory selection. **Apply accepted publications to draft** changes only the open working draft; cancel keeps its earlier choices.
3. Save, review and publish the group, then explicitly choose that group publication in the degree. **Also accepted publications** expands the retained list in previews. A reused published group carries the same allowances into each degree that deliberately selects it.

Editing a working group never changes earlier publications, degrees or students. Adopting a different required publication preserves explicitly selected additional versions except the new primary; it does not automatically accept the discarded primary. Existing students keep their selected curriculum unless staff explicitly assign a new one or approve a [student-specific publication exception](student-degree-exceptions.md#accept-a-course-publication-for-one-student).

A matching award retains its actual course code/title, accepted publication, earned credits and grade facts. Named eligibility can accept its declared version, but saved-rule conditions still inspect that award's own retained classifications. Grade floors, grading compatibility, repeats and sharing remain independent gates. Several accepted publications create neither additional course identities nor catalog credits. A course-count requirement still counts one course, and runtime credit requirements use eligible earned awards rather than the required publication's catalog value.

All additional references must resolve to verified publications of the same institutional course. Foreign, duplicate, missing and excessive references are rejected; required and additional versions cannot duplicate each other. Authoring reads verify selected versions in one bounded scan per course. Runtime reads deduplicate selected publications and share the existing protected-history allowance. A missing earlier course binding remains unknown; an allowance never invents a binding or changes an academic record, charge, issued transcript or prior event. Optional authoring assistance receives only an additional-publication count, without these internal references or student data.

## Publication review lifetime

Both builders use a 20-minute review. The confirmation screen shows the exact saved definition, retained course/group references and reason before publication. **Refresh publication review** keeps the reason and prepares a new review of the current saved draft. It saves no draft and publishes nothing; inspect the refreshed definition again if another staff member has edited it. A changed grading maximum may require reloading and updating the group before a fresh review can be prepared.

Publication takes effect when confirmation is accepted, after waiting for locks and checking the referenced definitions. For example, a review prepared at 10:00 and confirmed at 10:03 records a publication time of 10:03. Expired or stale reviews write no event, receipt, projection or audit entry. Permissions and the reviewer identity are checked after waiting/replay and again before a new publication is recorded.

A completed confirmation remains safe to retry after expiry or later publications: it returns the original result without publishing again or replacing later selections. Earlier accepted group and degree formats keep their recorded times, facts and retries. Earlier unconfirmed review formats require a fresh review. No stored event format, event table or database migration changes for these safeguards.

## Degree audit builder

1. Open a program's **Degree audit requirements** and choose **Set up an academic year**. Select an existing academic year to open its draft or create a blank one. Searches trim text and omit a blank search parameter.
2. Enter the requirement title, total earned credits and resident credits. Expand **GPA & program length** for optional cumulative/overall GPA minimums and expected length in months. Blank GPA means no minimum. Decimal values support three places and become integer thousandths in the API/domain.
3. In **Choose library blocks**, search groups and expand one. Select a publication and choose **Add checked blocks**, or drag its version onto the canvas. Alternatively, choose **Use working draft for testing**. The canvas distinguishes pinned publications from working drafts; publishing the degree still requires a publication for every group.
4. Arrange blocks with drag-and-drop or move buttons. Removing a block only removes the reference from this degree draft; it never deletes the library group. The same group can be reused by any other program or requirement year.
5. **Edit block … in library** opens its independent working draft and provides a return action. Saving or publishing the group does not silently replace the degree's selected version. To deliberately adopt a different publication, select that version from the source panel and add the same group again. A degree uses one version of each group.
6. Choose the institutional repeat-credit, repeat-GPA and transfer-GPA policies. Latest-attempt GPA reveals its ordering choice: academic term/instructional period (the default for a new blank draft) or actual completion date. Earlier missing choices stay unselected. Save incomplete drafts with **Save degree draft**. **Save & review publication** presents the exact resolved groups, general minimums and policies before **Publish reviewed version**.
   Degree publication reviews expire after 20 minutes. **Refresh publication review** retains your reason and prepares the current saved definition for another review. Confirmation records the actual publication time. A completed confirmation remains safe to retry after expiry; it returns its original result without publishing again.
7. **Published requirements → Inspect version** resolves original published groups and course labels. **Clone version … to another academic year** creates a fresh draft retaining the exact group-version references and policy inputs; it does not copy or give ownership to the groups. Existing target drafts cannot be overwritten. Review and publish the clone independently.

Two groups may reference the same course with different course publications. Each block retains its own approved definition. The [student course-group placement review](student-course-placement.md) now resolves overlapping eligible work with bounded exact search. Authoring never assumes a greedy allocation or sums group minimums as proof of degree feasibility. Complete degree evaluation remains a later stage.

## Grading scales

Use **Academic catalog → Grading scales** for the global setup, and **Programs → Program grading scale** for an override. [Grading scale setup](grading-scale-setup.md) explains grade score ranges, GPA points, inheritance and historical boundaries. Degree previews and publications use the program override when present; otherwise they use the global scale. Reusing a group never silently converts grade points between scales. A group floor or degree GPA minimum above the resolved maximum blocks publication.

## Institutional policies retained in publication

| Setting | Supported choices |
| --- | --- |
| Completed courses across requirement groups | Apply a completed course to one group (default for new compositions); or allow it in multiple groups. |
| Repeated-course earned credit | Highest eligible earned award per course; or every eligible earned award. |
| Repeated-course GPA | All eligible graded attempts; highest eligible grade per course; or latest eligible graded attempt per course. |
| Latest-attempt ordering | Retained instructional start (academic period); or actual retained completion date. Required for a new latest-attempt publication. |
| Transfer awards in overall GPA | Exclude transfer awards; or include accepted awards explicitly marked GPA eligible. |
| Course waivers | Remove a required course everywhere without creating earned credits or GPA points. Credit/GPA minimum changes require separate attributed exceptions. |

The repeat-credit, repeat-GPA and transfer-GPA choices start unselected and must be explicit before degree publication. Group sharing defaults to one group in new compositions and can be changed before publication. These are frozen inputs for this program/year, not an installation-wide mutable profile. The [calculation core](degree-audit-calculations.md) now processes these retained choices for supplied verified inputs. The [current-work credit/GPA review](student-credit-gpa-review.md) now uses these policies with verified available sources. Wider completeness reconciliation, remaining chronology evidence and full degree evaluation remain open; authoring never recalculates students. Unknown earlier policy inputs remain unknown.

## Credit requirements within a course list

Use **Take credits** for a requirement such as “Take 6 credits from ENG-100, ENG-101 and ENG-102.” Enter the minimum, then select the exact published course versions as usual. Fractional credits are supported to three decimal places. Saving, reopening, previewing, publishing and cloning retain that number; later publications do not change an earlier selected requirement.

Several cards may express different obligations in one group: all named courses, a selected number of courses and credits from a particular list. Every card and the group quantity must be satisfied together. Credits earned outside a card's list cannot satisfy its minimum, even when the group's overall minimum is already met. An eligible placement can satisfy overlapping cards within one group, but contributes its credits once to the group total. The degree's retained sharing policy still controls reuse across groups.

The allocation engine uses actual eligible earned awards after grade floors, repeats and approved substitutions, rather than today's catalog credit values. Waivers do not create credits or lower a card's credit minimum. A single actual course cannot inflate two alternative required targets into two awards. The staff placement review shows a card-specific optimistic credit bound and shortage without returning a speculative partial plan. Independent credit/GPA totals keep their existing rules.

This extends the current rule contract with mode `credits`, zero `minimum_courses` and a required positive integer `minimum_credits_milli`. Other rule modes reject that extra field. The publication remains in the existing protected journal; no numbered model wrapper, event table, migration or historical rewrite is added. The configured development database was inspected first: one group draft, one degree draft and no group/degree publication streams were present. Existing drafts were not changed automatically.

## Saved-rule and classification requirements

The degree-audit plan's addendum now distinguishes five requirement sources:

| Example | Current support |
| --- | --- |
| Take ENG-101 and ENG-102 | **All of these**, using selected publications. |
| Take 2 of a listed set | **Choose a number**, using distinct completed course targets. |
| Take 6 credits from a listed set | **Take credits**, with an independent per-list threshold. |
| Take courses using saved rule DA.BUS.UPPER | Group cards retain an explicitly selected revision and its conditions. Runtime reviews apply eligible matching awards to count/credit requirements and the separate group quantity, subject to the review limits below. |
| Take credits from a subject or department | Build the subject/department, exclusion and numeric-level conditions in RULE, then attach that saved revision to a **Take credits** card. Runtime placement uses those retained conditions and actual eligible awards, with repeats and sharing applied. `SUBJ`/`SUBJECT` and `DEPT`/`DEPARTMENT` describe the same respective source. |

Saved/classification rules must support combined conditions and exceptions, including “subject ENG except ENG-500” and “subject MATH with course level greater than 200.” Resolve readable codes to stable identities; retain explicit excluded course identities and typed numeric comparisons. Do not infer subject or numeric level by parsing a course code. A course's later reassignment to another subject/department, or a later saved-rule edit, must not silently change the earlier student's requirement or accepted academic facts.

The saved-rule library now provides visual condition controls rather than executable SQL or a requirement for staff to type TAKE syntax. Eligibility filters are distinct from student-specific course waivers, substitutions and credit/GPA minimum exceptions. Missing historical classifications, numeric-level evidence or retained rule versions must remain a review issue rather than silently matching or excluding work. Group publication now retains the complete selected revision, and runtime eligibility uses verified student inputs. The placement review consumes captured eligibility decisions without reading today's rule library again. A pointer to today's mutable rule is never used to reinterpret earlier requirements.

## Test any degree audit against a student

Open **Degree audit requirements** and choose **Test … audit**, or open the builder and choose **Test with a student**. Testing has its own workspace, separate from publication review. Choose **Use working degree draft** to test current editor settings, including unsaved edits, or **Choose published audit version** to select an exact earlier or current publication. **Published requirements → Test version … with a student** opens a publication directly without loading the current working draft. **Return to degree builder** restores editor changes; **Choose another degree audit** returns to the program's plan selection.

1. Build the group in the library and save its working draft. In the degree's library panel, **Use working draft for testing** attaches it without publishing it. Previously selected publications remain available as deliberate alternatives.
2. Enter a student's name or assigned institutional ID and search. No student directory loads automatically. Choose a student, then one of their pursuits to authorize the academic-record read. The tested audit may belong to **any program or academic year**, including one the student does not pursue. The pursuit supplies student access context; it does not restrict the test to the student's current program or change their matriculation cohort.
3. For a working-degree test, leave **Use current working drafts for all course groups** unchecked to test the selected group publications alongside any unpublished draft references. Check it to use saved working drafts for every attached group instead. This is an explicit test choice; it does not replace the versions in the degree draft.
4. Choose **Run temporary audit**. The result identifies the student, tested program/year, degree source and each group's working revision or published version. Inspect general credit/GPA minimums, course-group placement, shortages and current-work contributions through the existing review tools. **Inspect group … → Named-course review** connects required courses/options to actual attempts and eligibility reasons, even when no complete plan exists; it makes no partial placement or saved test result.
5. Return to test setup to choose another student or source combination. Return to the builder to adjust degree settings or save a revised library group, then run another test. Closing the workspace discards the result.

These are **runtime-only tests**. They create no audit-result row, publication, assignment, approval, academic event or audit-log entry. They do not save the editor snapshot or change the student's assigned requirements, credits, GPA, grades or finances. Nothing is sent to an AI provider or placed in browser storage, a job, a file or a persistent result cache. Saving an authoring draft remains a separate explicit action. No migration or test-results table is needed.

Student-specific waivers, substitutions, placement preferences and minimum reductions approved for an existing selected publication are **not applied** to these baseline what-if comparisons, even when testing a published audit. The workspace states this explicitly. A future exception-testing mode would need its own deliberate scope; this test does not silently transfer approvals to a changed curriculum.

Published-degree tests load the selected degree, group, course and grading definitions from the protected journal using one shared replay budget. They do not resolve today's grading scale or working group drafts. The server rejects attempts to combine a published version with an editor definition or a group-draft override. Missing earlier grading or rule evidence remains unknown; it is never replaced by current settings.

The trial shares the production progress evaluator, institutional/transfer input verification, repeat/grade policies and exact placement search. It holds the student lock and shares the existing bounded history allowance across group/course definitions, grading resolution and academic/chronology reads. Both `sis:catalog:manage` and `sis:student:read`, plus the student's pursuit scope, are checked before reads and again before returning. Browser requests retain CSRF protection, private/no-store responses and debug suppression. Changed student/source choices or a closed workspace discard a pending result.

Working groups must contain valid, testable requirements, but need no group or degree publication. Course definitions still use explicitly selected retained course publications; tests do not invent missing historical course/grade evidence from current catalog rows. Unknown/inconsistent evidence and replay/search limits remain review outcomes with no speculative partial plan. Earlier-history gaps remain visible. A successful test does not certify a complete academic archive, final degree completion or graduation eligibility.

The trial supports named-course cards and saved-rule count/credit/pool cards through the same runtime placement path, before or after group/degree publication. Predicate checks remain available separately: a course-condition match still requires an eligible grade/award, repeat-credit treatment and a valid combined placement. Missing facts and the unresolved combinations described below produce review outcomes, with no partial plan or unused-work claim.

Private POST endpoint: `/sis/degree-audits/{id}/test/`. It accepts `source: draft` with the degree editor definition and explicit working-group choice, or `source: publication` with an exact `version_id`; both require student and pursuit identities. A publication must belong to the selected degree audit. Clients cannot provide academic attempts, grade facts, approved exceptions or larger replay limits. The source manifest and definition hash describe only the returned temporary calculation; they are not a publication or authorization for a later academic command.

## Saved course eligibility rules

Open **Rules → Course eligibility**. This library is separate from student-audience rules and requires both `sis:rules:manage` and `sis:catalog:manage` for every read, save and preview. It contains curricular definitions, not student records or a new event store.

1. Enter a reusable rule code and descriptive name. Add visual conditions, combining **all** (AND) or **any** (OR).
2. Choose subjects, departments, academic levels, course levels or individual courses through searchable, paged record pickers. To express “English except ENG-500,” combine **Subject equals ENG** with **Course does not equal ENG-500**. For “Math above level 200,” combine the subject with **Numeric course level greater than 200**. Staff select readable records; the definition stores stable identities rather than parsing course codes or executing SQL.
3. **Save course-rule revision** creates its first revision. Saving later edits appends a revision and retains earlier definitions and reference labels. The rule code stays fixed; use a new rule for a different identity. A stale edit is rejected instead of overwriting someone else's revision. Open earlier revisions from the saved revision list.
4. To test conditions, choose courses and explicitly select their retained publication versions, then preview. No current publication is silently selected. The result is **Matches**, **Does not match**, or **Unknown** when the retained evidence cannot decide. Changing conditions or selected publications clears earlier results.

Numeric course level comes only from a one-to-six-digit course-level classification code retained in the selected course publication (0–999999). For example, a separately configured code `300` supports numeric comparisons. A code such as `UPPER` still supports classification identity comparisons, but has no inferred numeric value. Missing classifications remain unknown; a course number such as MATH-301 is never used to invent a numeric level. AND/OR retain decisive known outcomes without treating missing facts as a match.

Previews accept at most 20 explicitly selected publications and share one bounded protected-history replay budget. Saved-rule and revision lists use 20-row metadata pages without loading every definition. Browser drafts stay in memory; the screen does not place them in browser storage. Browser mutations retain CSRF protection, and responses are private/no-store.

`sis_course_rules` stores immutable authoring revisions with ULID identities, unique code/revision and family/revision constraints, reference labels, an author and time, and a definition checksum. Each save and its audit record commit together. The checksum detects damaged contents; it does not provide the protected journal's guarantees or make an authoring row an academic decision. The migration was applied after inspecting the configured development database; checksums confirmed existing student-rule rows and event, stream-head and operation-receipt rows were unchanged.

Saving or previewing a rule changes no student requirement, earned credits, GPA or completion result; publishing a group retains its definition without assigning it to a student. Runtime placement now uses captured matches as described below. Remaining work includes broader historical course-publication equivalence.

## Use a saved rule in a course group

In a requirement card, choose **Choose saved rule for requirement …**, browse a rule's revisions and explicitly choose one. This requires both catalog-management and rule-management permission. The builder displays its code, title, revision and readable AND/OR conditions. It never silently chooses the latest revision. The group can mix rule-backed cards and ordinary named-course cards.

A rule-backed card supports **Choose a number**, **Take credits** or **Eligible courses**. Its quantity applies to matching work; the group minimum remains separate. **All of these** applies only to an explicit named list and is unavailable for an open eligibility rule. **Use listed courses** switches the card back to named selections. Listed course/credit planning totals exclude dynamic matches and show a notice; the builder does not pretend to know the size or credits of the eligible universe.

Saving and publication review verify every supplied rule snapshot against its original immutable authoring row; changed labels, conditions or checksums are rejected. Publication retains the full revision, condition tree and display references inside the existing protected group event. No new event table or numbered model wrapper is added. Earlier group contracts remain unchanged; the current group format gains the optional `eligibility` snapshot only on rule-backed cards. Authoring revisions are not new academic publications of their own.

**Inspect saved-rule eligibility** in a runtime test or staff degree-progress review shows the captured student's course-condition checks, 20 at a time. Paging reuses that result without another request or replay. The shared reader uses accepted institutional/transfer course-publication references, deduplicates repeated version reads and never substitutes current subject/department/level assignments. If an earlier course has no publication reference, only independently known course identity can decide a condition; missing classifications remain unknown. This does not parse a numeric level from a course number.

The request shares the existing student lock and history budget, accepts no caller-supplied academic work and caps rule/work comparisons at 5,000 across at most 200 current records. Verification failures or an exhausted budget return no partial review. The student portal omits the rule snapshots and detailed eligibility records; no raw rules, internal authoring IDs or author narratives are sent there. Optional authoring AI receives only flags indicating a selected rule and that student work is needed for evaluation, through the existing consent allowlist; it receives no rule snapshot or student data.

### Place matching awards in course groups

Runtime tests, staff degree progress and the standalone course-placement review now feed those captured checks into the existing exact bounded search. The compiler derives temporary course targets from the student's accepted work, then applies passing-grade facts, the group's grade-point floor and placement preferences **before** selecting repeat credits. It never scans today's catalog to invent candidates. A larger attempt excluded by the rule cannot replace a smaller eligible award. A failed or below-floor attempt cannot lend its rule membership to a different passing attempt.

A matching award can satisfy overlapping cards within one group, but contributes credits once to that group. Highest-eligible and all-eligible repeat-credit policies remain distinct: all-eligible can add several actual awards' credits, while those awards still represent one distinct course for a course-count requirement. The retained one-group/multiple-group sharing policy governs placement across groups; overall earned-credit/GPA calculations remain independent. Group quantity and each card's credit/count minimum must all be met. Too few known matching courses is a genuine current-work shortage, not an invalid rule or a satisfied empty list.

Some combinations still need registrar review. A course publication outside the named target's explicitly accepted set requires review. Potentially usable work with unknown predicate, grade, publication or grading-context evidence blocks a definitive plan. Unknown rule facts on known unusable failed/dropped work do not make an otherwise verified plan inconclusive. These cases return **needs review**, without a complete or speculative partial placement or shortage claim. Unknown earlier bindings and broader cross-course equivalence remain open; accepted publications are now explicit as described above.

Repeated attempts can match different rule cards because their accepted course publications differ. The evaluator retains each attempt's memberships instead of merging them. With **highest eligible**, it retains the highest awards within each equally matching set and offers those sets as separate alternatives to the search. Only one alternative for that actual course can be placed in a group. Equal-credit attempts with different memberships are separate placement choices; equal-credit attempts with the same memberships remain equivalent evidence alternatives. With **all eligible**, the pool retains every eligible award, but each card receives only its own matching credits. The course still counts once toward a course-count minimum.

For example, an earlier three-credit award matches ENG and a later four-credit award matches HUM. All-eligible can supply seven group credits, three toward the ENG card and four toward HUM; it cannot supply seven toward either card or two distinct courses. Highest-eligible cannot combine those two attempts to satisfy both cards. The search can use other eligible courses to complete the missing card, or report that no complete plan exists. Overall earned credits and GPA still follow their independent policies.

**Requirement contributions** on a proposed placement shows each saved-rule card's own credits and single-course contribution. Expand it to inspect the breakdown; local inspection adds no request or replay. A course's placement is marked only on the cards it actually supports. Shortage bounds likewise use card-specific eligibility and amounts; an optimistic bound does not establish a feasible combination.

An active approved substitute can now satisfy its named target and a matching saved-rule card in the same group through one placement. For example, a three-credit SCI.201 award approved for REQ.0 can supply that named requirement and three matching elective credits. It contributes three group credits and one distinct course. It cannot fill two separate named requirements, inflate a choose-two card, or let the original REQ.0 borrow SCI.201's predicate match. The approved replacement publication, passing grade, group floor, repeat and sharing rules still apply. Missing approval leaves the named target unmet; an incompatible accepted publication or conflicting approval requires review. Revocation removes the alternative on the next verified review without editing earlier facts.

The named card displays its retained target; the saved-rule card lists actual eligible courses. Proposed placements and their contribution breakdown preserve that distinction. Count shortages now use the tighter bound from distinct targets and actual courses, so several eligible slots for the same substitute cannot masquerade as several completed courses. These are still optimistic bounds; the combined search resolves competing choices. Baseline runtime what-if tests continue to exclude student-specific approvals, as described above; this does not add an implicit exception-testing mode.

Derived target lists and labels exist only in the current review. They do not alter rule revisions, published groups, student rows or the protected journal. Display labels follow the accepted work supporting the selected award, including its retained course version. At most 200 course targets per group and 5,000 rule/work checks enter preparation; existing evidence/search/replay bounds remain in force. Local inspection reuses the result. No audit result is persisted, and a feasible group plan does not certify graduation. The older named-course match screen covers only named targets and directs staff to degree progress for saved-rule placement.

## Course sharing across groups

Under **Degree settings → Institutional audit policies**, choose **Apply a completed course to one group** or **Allow a completed course in multiple groups**. This is the institution's policy for the degree being published. It governs sharing the same completed course across requirement groups; repeated attempts retain their separate credit/GPA policies. Sharing never authorizes counting an award twice in overall credit or GPA totals.

The choice is retained with the degree publication and appears in publication reviews, historical inspection and student requirement/approval previews. A later draft or publication cannot replace an earlier student's selected policy. Cloning a publication retains its choice; a blank new composition defaults to one group. Staff can deliberately assign another publication through the existing reasoned student workflow.

The current composition model validates `policies.group_reuse` as `one_group` or `multiple_groups`; missing, null and unsupported values are rejected. The alpha change was preceded by a read-only check of the configured application database: zero degree-requirement events and zero template drafts were present. The existing composition format was extended directly, with no new numbered model, event schema, migration or recorded-fact conversion. Existing unrelated events were not changed.

Earlier inline publication contracts still omit this policy and display it as not recorded. Explicitly moving an inline working draft into a new reusable-group composition assigns the new-draft default; it does not rewrite an earlier publication. Legacy conversion/import of historical facts must not infer a missing allocation policy.

This stage retains the policy input. The allocation and repeat/GPA domain cores now consume retained choices. The current-work credit/GPA review now connects the repeat/GPA core to verified student inputs. Remaining equivalence/constraint integration and complete degree evaluation remain on the roadmap; publishing or editing a choice never automatically recalculates students.

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

Review is side-effect free and binds the exact definition, actor, draft revision, expected stream version, reason and trusted expiry deadline in an encrypted token. New publications use confirmation time; the review does not supply an effective timestamp. Commit uses locking, expected-version append and atomic event/head/receipt/projection/audit writes. Stale drafts, changed context and competing publications fail without partial publication. Accepted exact retries return the original receipt after later edits; another actor or revoked permission cannot use the review.

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
- Apply approved substitutions (including not-yet-taken courses) in the future evaluator and add specialization-scoped exclusions. Waivers, substitutions and credit/GPA minimum exceptions now have reviewed approval/revocation histories, outside template cloning and ordinary reporting. Group course-count and per-course-point-floor reductions now have explicit student exception targets. Counts remain whole numbers, the earlier group-average-GPA target remains distinct, and none of these approvals changes earned work or required-course rules.
- Introduce controlled projection rebuild/reconciliation operations and institutional retention/approval policies. A degree audit advises a decision; it never silently grants a credential.

## Verification

Fictional SQLite integration tests cover group reuse across programs/years, selected-version isolation, exact-reference cloning, explicit draft conversion, legacy event/receipt preservation, protected payloads, Codefy replay, strict schema separation, optimistic saves, stale/competing reviews, exact retries, permission/actor checks, bounded stable pages and atomic rollback. The actual provider/router checks exercise group and degree creation/review/publication with CSRF and private responses.

Browser checks exercise standalone group creation, course/version checkboxes, direct rule-type controls, block composition, native drag-and-drop and button alternatives, preserved degree fields while editing groups, publication review, escaped titles, mobile reflow, focus restoration and automated accessibility scans. All 97 SIS table definitions compile through the five installed framework dialects; runtime integration here uses SQLite only. Other database engines still require live locking/LOB verification.

The student program now has a **Degree requirements** action for inspecting its retained selection and, with explicit office permission, reviewing a new one. [Student requirement assignment](student-degree-requirements.md) documents its migration, permissions and historical controls.

## Inspecting student work against requirements

After assigning a published degree to a student pursuit, use **Degree requirements → Academic work → Check requirement matches** for [per-course candidate explanations](student-academic-work.md#checking-requirement-matches). The view uses retained course/grading references and applicable exceptions. It identifies possible uses without allocating courses, calculating GPA or deciding degree completion.

The [structural allocation engine](degree-audit-allocation.md) handles normalized eligible courses, named and saved-rule quantity constraints and the retained sharing choice. [Course placement](student-course-placement.md) and [degree progress](student-degree-progress.md) connect it to verified student inputs and retained repeat policies; the per-course candidate preview remains a separate inspection tool.

## Optional saved-group review

**Optional group AI help** in the course-group builder offers a consent-based readiness review of saved quantity/grade settings, rules and the exact selected course publications. It uses its own permission and never changes the group or evaluates student work. See [setup, payload and verification limits](optional-authoring-assistance.md#saved-course-group-readiness). Group and degree assistance share the same consent interface; normal saving and reviewed publication remain explicit.

## Review a saved degree composition

**Optional degree AI help** now includes the exact published groups and courses selected in the saved composition, alongside minimums and policy choices. The consent preview uses numbered course references to make cross-group overlap visible without sending internal IDs. Unselected groups do not contribute current draft contents. Existing selections keep earlier publication facts after later edits or publication changes.

This is advisory authoring assistance, not degree completion or accreditation approval. It cannot modify requirements, read student records or make academic decisions. Oversized or unverifiable compositions are not partially sent. See [payload, consent and limits](optional-authoring-assistance.md#saved-degree-setup-readiness).
