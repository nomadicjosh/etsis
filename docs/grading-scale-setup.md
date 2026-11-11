# Global and program grading scales

The institution defines a global grading scale. A complete, explicitly approved program scale overrides it for that program; without an override the program inherits the current global scale. This supports graduate and undergraduate differences without making reusable course groups program-owned.

## Setup

Apply `20261109000001_CreateSisGradingScales` and then `20261109000002_CreateSisVersionedGrades` after the existing SIS/history migrations. `sis_grading_scales` stores current scope/publication metadata; `sis_grades` stores immutable grade definitions for each recorded publication. Authoritative approvals stay in the existing protected journal. The migration creates no parallel event store, resets no academic data and invents no earlier policies. Development checks use fictional in-memory databases and have not migrated your working database.

1. Open **Academic catalog → Grading scales** (also available from **Grade definitions → Manage grading scale**).
2. Choose **Add grade**, then enter its **Grade**, **Minimum score (%)**, **Maximum score (%)**, **Grade points**, **Count in GPA**, and **Earns course credit**. Select a grade card to edit it. A scale supports up to 200 grades; the editor shows 20 cards at a time.
3. For example, `A+`, minimum `97`, maximum `100`, grade points `4.0`, and **Count in GPA** checked retains the legacy example. Institutions choose their own ranges, points and credit eligibility. A score of 97% and a GPA point value of 4.0 are different quantities.
4. Leave **both** score boundaries blank for administrative/non-percentage grades such as W or P where appropriate. GPA inclusion and earned-credit eligibility are independent explicit choices.
5. Enter a reason, choose **Review grading scale**, inspect the definitions, then **Approve reviewed grading scale**. **Change scale review** preserves unsaved entries and the reason. Review alone changes nothing.
6. For a different program scale, open **Programs → the program → Program grading scale**, or the same action in its degree builder. Choose **Create program override**, configure a complete scale and review/approve it. **Use global scale** prepares a reasoned decision to remove the override. Program overrides are complete policies, not partial grade-by-grade merges.

The maximum grade-point value is derived from the largest configured grade-point value. It has no editable maximum field. Only an empty grading setup displays a default maximum of **4.0**. A configured zero-point-only scale has maximum zero; a configured 4.3 or 5.0 scale retains that maximum. The optional course-group minimum is chosen by staff and must fit its selected authoring context. A program’s degree publication also validates group floors and general GPA minimums against its resolved scale. Points are not silently normalized between scales.

The reason field is required and identified as such in the editor. A rejected review preserves the entered scale; field errors open the affected grade and describe the correction next to its input.

Score boundaries are inclusive, support three decimal places, and must lie between 0 and 100. Ranges cannot overlap, including shared endpoints. For adjacent continuous ranges use boundaries such as 93.000–96.999 and 97.000–100.000. Gaps are allowed and stay unmatched; there is no implicit rounding or guessed grade. The pure lookup returns a matching grade definition or an unknown result, without writing an award. A complete nonempty scale is required for approval. For example, D at 65–66% and F at 0–65% conflict at 65%. If 65% earns D, set F’s maximum to 64.999%; if 65% earns F, move D’s minimum above 65%. The institution chooses the boundary; the system never changes it automatically.

## Preservation and staged academic integration

`GradingScaleWasChanged` schema 1 records global/program scale approvals and program returns to inheritance, with trusted actor, reason, expected stream version and retained predecessor. The current projection is verified against bounded replay. Review/commit uses private browser routes with CSRF, encrypted review tokens, same-actor checks, concurrency, exact-retry receipts and atomic journal/projection/audit writes. Authoring requires `sis:catalog:manage`. Corrupt/missing projections fail closed; reads do not automatically rebuild them.

New degree publications use `RequirementsWerePublished` schema 4. The composition grammar remains schema 3; the event additionally retains the complete resolved grading-scale snapshot, its source and program policy version where present. Later global or program scale changes do not rewrite an earlier publication or a student’s selected requirements. Earlier degree publications keep unknown scale inputs unknown. A scale change during an unaccepted degree review requires a new review; accepted retries retain their original receipt.

## One grading catalog for authoring and academic workflows

Approving a scale projects every grade into **`sis_grades`**, with its grade code, score boundaries, point value, GPA/credit flags, stable scale identity and exact publication ULID. Grade codes are unique within a publication, so an undergraduate A and graduate A can have different values, and older versions coexist. Grade-row ULIDs are deterministic projections of publication identity and code; verified replay recreates the same references without adding historical events. `sis_grading_scales` contains current scope/publication metadata and no second editable JSON definition.

New final grades, transfer awards and late-drop grade resolution use a shared verified grading catalog. Program overrides take precedence; otherwise the global scale applies. A chosen grade must belong to the applicable policy. The faculty grade dialog requests student-specific choices, with an explicit program-pursuit choice when a dual-degree student has different scales. It cannot use an unrelated program’s grade or silently choose a different scale. Other workflows lacking an explicit pursuit context stop on ambiguous program scales. New institutional registrations retain known policy references; closing a pursuit does not discard that grading context. Older untracked credits without a known policy and only closed pursuits require review; they never silently fall back to a global policy.

An academic credit's `grade_id` points to its immutable published definition. Subsequent scale changes do not alter recorded grade labels, earned-credit eligibility, GPA inclusion or stored quality points. Corrections to an already graded final/transfer record choose from its retained scale, including earlier publications, rather than today's scale. New institutional credits pin known student-program policy at registration, including before their first grade; later publication does not replace it. Unknown inception policy stays explicitly unknown.

Transcripts and reports keep their grade-ID joins. Reporting includes **Recorded grade scale publication** and **Grade-point units**. The latter is the per-course grade value (e.g. 4.0), distinct from credit-weighted quality points (e.g. 12.0 for three credits).

The old independent `POST /sis/grade-definitions/` route and single-grade CRUD writer are removed. The scale editor is the policy authoring path.

## Existing database cutover

`CreateSisVersionedGrades` preserves existing grade IDs and every referring academic record. Older unversioned grade rows retain unknown policy provenance and are never presented as invented approved global policy. Historical/earlier workflows can still resolve those rows until an approved scale is applicable; a previously graded unversioned record retains its earlier definitions. The authoring screen uses the 4.0 default before an actual policy is approved.

The migration verifies recorded scale events with the existing history keys and reconstructs all their published grade rows in bounded pages. It checks the earlier current JSON projection against the actual protected stream before removing that obsolete column. Event bytes, original actor/reason/times, receipts and academic records are preserved. Missing keys, changed projections, broken history and orphaned stream metadata stop the cutover; recovery can resume without inventing approvals. The bootstrap already supplies the history codec.

Run this migration with writers stopped. SQLite requires a controlled table rebuild to remove the earlier global code uniqueness constraint; grade IDs and foreign keys are verified, and the original foreign-key enforcement mode is restored. Unexpected custom SQLite grade indexes/triggers require explicit schema review rather than being discarded. Existing normalized projections can be retried; web requests never silently rebuild corrupt projections. Collapsing published versions into the old mutable-code schema is unsupported by rollback.

Fictional integration checks cover populated/empty upgrades, historical references, policy/receipt preservation, resumability, tamper failure, program-specific choices, retained corrections and affected read/write workflows. Schema definitions compile through the installed MySQL, PostgreSQL, SQLite, SQL Server and Oracle compilers. Runtime database verification uses SQLite; other engines still require live DDL, locking and LOB verification. No working database was migrated by development tooling.

**Institutional credit/grade/drop decisions now use protected academic events.** See [institutional credit history](academic-credit-history.md) for review/confirmation, correction authority, observation of older facts and registration-time policy pinning. [Transfer awards/corrections](transfer-credit-history.md) now also retain their grade facts in the shared journal, including policy retention through void/reinstatement. Full section versions remain a separate conversion. Previously recorded grades are not recalculated, and unknown earlier policies remain unknown.
