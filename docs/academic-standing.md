# Academic standing

Academic standing is an institution's determination for a dated period under one student-program pursuit. Institutions define their own labels: etSIS does not assume that an unreviewed student is in good standing. This workflow brings the decision into the same program workspace as degree progress without creating competing editable GPA or enrollment-load totals.

## Setup and registrar review

In Access management, grant `sis:standing:configure` to the office responsible for the institution's standing definitions. Grant `sis:standing:manage` and `sis:student:read` to staff who record student determinations. Standing history additionally requires `sis:audit:read`. These independent system permissions are protected; the migration does not change any role/user grants. The existing `super` role retains full access. Open **Students → Standing definitions** to maintain the library; the Students workspace also requires student-read access.

1. Add the institution's code and name. For example, a fictional institution could use `GOOD` / Good standing and `REVIEW` / Academic review. These are examples, not seeded policy. Retire a definition when it should no longer be available for new determinations.
2. Open a student, then **Student programs → More → Academic standing**. New determinations belong to the current primary, active pursuit. Historical pursuits remain available for authorized review and separately authorized corrections below.
3. Add a period, choose a standing, and enter its effective start and optional end. Dates are inclusive and periods cannot overlap. If one period ends August 9, the next can begin August 10. Keep earlier periods when recording the next determination.
4. Optionally record a policy reference, such as the institution's handbook edition and section. Enter the reason for the decision. The institution remains responsible for the underlying assessment; the reference is evidence supplied by staff, not an executable or automatically verified policy.
5. Use **Degree progress** to inspect the existing verified academic review, then return to the standing draft. Its values and reason remain in memory. Staff do not re-enter GPA or earned-credit totals here.
6. Review the complete before/after periods and confirm. A changed draft, changed source, retired/renamed definition or expired twenty-minute review requires another review. Removing a period is an explicit reasoned correction; its earlier accepted facts remain in protected history.

Each pursuit supports up to fifty periods. A retired/renamed definition does not relabel accepted periods. Existing periods can be kept or shortened with their retained labels; extending or replacing them requires choosing a current active definition. Correcting a published label in the library alone never changes a student's standing.

## Correct a closed pursuit

Grant `sis:standing:correct` in addition to `sis:standing:manage` and `sis:student:read` to the registrar responsible for historical corrections. The new protected duty is independent: ordinary standing management does not imply it, and migration does not add role/user grants. Open the same **Academic standing** editor under the closed pursuit; staff without this duty retain its read-only view.

Correct the periods, enter a reason and confirm **I verified institutional evidence for this historical correction**. Review the complete before/after periods and confirm. The evidence confirmation is bound to the review and retained in `StudentStandingWasCorrected`, in the same pursuit stream as the original determinations. It records the correction now, with the supplied effective dates; it does not invent an earlier recording time or missing past decisions. Current authority, source, definition and expiry checks still apply through the final audit.

The pursuit remains closed. Neither its original standing decisions nor the student's current-program standing, enrollment, activity, charges or advisor access are replaced. A program change does not copy the correction into the new pursuit. Accepted retries return their original result rather than overwriting later corrections. Evidence and protected reasons remain staff-only; students continue to see the retained labels/dates under their own historical pursuit.

## Check standing history

Authorized support staff can use **Students → open student → Student programs → More → Check standing history** for the selected current or closed pursuit. This requires `sis:history:verify`, `sis:audit:read`, `sis:student:read` and `sis:standing:manage`; ordinary ownership/advising access does not grant it. The shared drawer runs only on request, displays minimized verification/limit/change outcomes, records a restricted support receipt and offers **Back to record**. Previous checks remain observations of their recorded point.

A pursuit without recorded standing reports **No recorded standing observed**, with earlier standing explicitly unknown. It does not imply good standing. Recorded/corrected decisions are compared with their complete period projection; a renamed definition does not relabel them. No label, dates, private policy reference, reason or raw event is exposed in the support result. This check changes no standing or academic decision. Trusted operators can use the existing [selected verification command](event-history-foundation.md#selected-standing-and-enrollment-load-verification) for a larger bounded observation; repairs and institutional decisions remain separate authorized work.

## Student and advisor views

Students see **Academic standing** beside the selected pursuit's degree progress in **My campus → Degree progress**. The presentation shows the accepted label and inclusive dates. Earlier/future periods remain available through **Other recorded periods**. Closed pursuits are explicitly identified as historical. No period for the selected date means no determination is recorded; it does not mean good standing, probation or suspension.

Current assigned primary/additional advisors see the same minimal standing presentation in **My campus → Advising** when opening an advisee's program and degree progress. This uses the existing dated advisor/pursuit gate. It does not grant general student-read or editing permissions. Assignment expiry/removal, loss of advisor eligibility, disabled accounts or pursuit changes remove that scoped access. Student endpoints remain strictly owner-only, including when called by a staff account. Staff reasons and policy references are withheld from both student and advisor responses. Advisor reads record metadata-only access evidence.

A program change keeps the former pursuit's standing under its original identity. It does not copy a determination into the new pursuit. Closing a pursuit does not erase its recorded periods or turn them into a withdrawal from the institution.

## Reporting

In Report Studio, choose **Academic standing periods**. Report access requires `sis:reporting:run`, `sis:student:read` and `sis:standing:manage`. Authorized report authors can select retained standing names/codes, dates, current student names/IDs and the relevant pursuit/program. The ordinary report configuration, private sharing, live/static captures and expiry controls apply.

The source has **one row per currently recorded period**, not one row per student. For a determination effective on August 10, filter **Effective from** on or before that date and **Effective through** either empty or on/after it. For headcounts use a distinct student count; several periods or joined registrations must not inflate a count. Pursuit status can distinguish current from closed programs. Person/program labels are current labels; standing labels are retained from the accepted determination.

This source reads bounded projections and does not decrypt or replay event history. It excludes staff reasons, policy references and raw events. Effective-date filtering describes the currently accepted periods; it is not a reconstruction of what staff knew before a later correction.

## Standing on transcripts

In **Transcripts → Transcript setup**, an authorized policy administrator can select **Include recorded academic standing**. This option is off by default and uses the existing encrypted, revisioned transcript settings. It requires the existing `sis:transcript:policy`, `sis:transcript:manage` and `sis:student:read` duties. Ordinary transcript operators need no standing-edit permission to prepare the institution-selected content.

When enabled, new previews and prepared PDF/HTML documents show the retained standing labels and inclusive dates under the primary program pursuit selected at preparation, subject to the definition selection below. The last-selected closed pursuit is supported. Periods that begin after preparation day are excluded. Other pursuits' standing is not imported into this document. With all definitions included, no available period means **No standing is recorded through this date**, not an inferred good standing. Staff reasons, evidence confirmations and policy references are never printed.

Each prepared artifact retains its content choice, cutoff, pursuit reference and verified source version. Changing transcript setup later applies to new preparations; it does not add or remove content from an existing original. Verification of that original uses its retained choice and cutoff. A later day, a renamed library definition, a private reference-only update or a future period beyond the cutoff does not change its printed standing. Pending preparation reviews still bind the complete source context and must be refreshed when it changes.

If a new accepted determination or correction changes standing facts printed in a prepared document, delivery requires fresh preparation. The earlier protected PDF/HTML remains readable, and a repeated accepted preparation still returns its original receipt. If current saved standing rows disagree with protected history, preparation/delivery stop for records review; access to protected originals remains available. Source checks share the existing transcript confirmation, rendering, final-audit rollback and delivery workflow rather than introducing a separate repair queue.

### Choose standing definitions for transcripts

After enabling **Include recorded academic standing**, choose **Standings to include**:

- **All recorded standing definitions** includes every retained standing period within the document's pursuit and preparation cutoff. This preserves the previous enabled behavior.
- **Selected standing definitions** includes periods associated with the definitions you deliberately choose. Pick one to twenty-five definitions. Search and use **More definitions**/**Previous definitions** to browse twenty results at a time; selections and unfinished release checks remain intact when paging or searching. **Remove** changes the proposed selection. Choose **All** explicitly to remove the filter; an empty selected list cannot be saved while standing is enabled.

For example, a fictional institution could include its `HONORS` and `ACADEMIC_REVIEW` definitions while omitting routine `GOOD` annotations. This controls printed annotations only. It does not delete or change the student's standing, degree progress, registration eligibility, activity or financial facts. If no selected period applies, the document says **No selected academic standing is included through this date**; it does not claim that the student has no recorded standing.

Definitions are selected by their stable identity. Renaming or retiring one does not change its membership in the selection or relabel a student's accepted periods. Retired definitions remain available because they can identify earlier recorded periods. Create a new definition when its institutional meaning changes; use renaming for label corrections. Definitions are maintained in **Students → Standing definitions** under their separate setup duty; transcript policy authority does not grant standing-edit authority.

Save setup to apply the selection to future preparations. Temporarily disabling standing retains an existing valid selection for later re-enabling. If setup changes elsewhere, a stale save preserves your draft for explicit reload/review. Edits made while a save is pending remain unsaved in the current drawer after the accepted revision returns. Lost policy authority clears the setup, and closed lookups cannot restore it. No drafts are placed in browser storage.

Each preparation retains its exact selection with the existing protected release evidence. Later setup changes do not rewrite an original PDF/HTML, its accepted preparation receipt or its selection. Current verification and delivery checks use that original selection and cutoff. A legitimate change to an excluded standing period alone does not change the printed standing facts; changed included facts still require fresh preparation as above. Delivery acknowledgements use the current release-check setup and do not replace the original document's content selection.

The selection extends the existing encrypted, revisioned configuration and artifact evidence, with current permissions, ordered reference locks and atomic required audit. Earlier settings/evidence without a selection field retain their actual previous all-definitions behavior when standing was enabled; they are not rewritten. New setup API commands explicitly provide `standing_definitions`: `null` for all, or one to twenty-five distinct definition ULIDs. Omission and an empty selected list are rejected, rather than silently broadening the content choice. The private reference lookup adds no report, student access grant, schema conversion or historical standing event.

## Historical and security boundary

`StudentStandingWasRecorded` decisions use one `StudentStanding` aggregate per pursuit in the existing `student_standing` stream type. The same authoritative `sis_history_events` journal protects actor, reason, confirmation time, correlation, exact dated periods, accepted definition identity/labels, prior operation and before-state fingerprint. Effective periods are explicit event facts; the event context records the confirmation time. Unknown earlier standing remains unknown. Migration creates no historical decisions.

`sis_student_standing` is the reconstructible current projection. `sis_standing_definitions` is an audited, revisioned CRUD library of labels; it is not an academic rule engine. Commands use student/definition locks, expected versions, expiring actor/instruction-bound reviews, bounded replay, required audit and one atomic transaction. Completion-time source, authority, definition, expiry and projection checks roll back failed decisions. Repeating an accepted confirmation returns its original receipt even after later changes or closure, without replacing later facts. `StudentStandingLedger` shares selected replay and protected-head/projection verification across maintenance, private reads and the internal transcript source port. Ordinary report/search pages remain bounded projection reads; a transcript without standing selected performs no standing-source verification.

Browser mutations keep framework CSRF; responses remain private/no-store with debug collectors suppressed. Drafts/proofs stay in page memory. Delayed responses cannot replace a later pursuit selection. Migration `20261120000008_CreateSisStudentStanding` adds ULID-based reference/projection tables and independent permissions without rewriting the existing journal. Schema compilation covers the five framework dialects; live multi-engine and institutional acceptance remain separate qualification work.

The migration was applied to the configured DDEV application database on November 20, 2026. All twenty recorded events remained intact; before/after checksums matched for the journal, stream heads and operation receipts. Existing role/user permission-grant counts stayed unchanged. The new tables start empty, with no invented standing or default institutional labels. Focused verification passed 66 PHP tests, nine browser scenarios, real-provider HTTP/CSRF/private-response checks and coding standards for the changed code. Browser scenarios ran with one worker and fictional records.

The follow-up permission-only migration `20261120000009_CreateSisStandingCorrectionDuty` was also applied on November 20. It added the protected correction duty without changing existing grants, transcript setup or documents. The same twenty events and all three history checksums remained unchanged. Connected verification passed 77 PHP tests, nine browser scenarios, real-provider standing/transcript HTTP checks and coding standards for the changed code, using fictional records and one browser worker. No event conversion, new journal or extra recovery workflow was introduced.

## Remaining policy work

This completes reviewed manual standing, historical corrections, setup, private display, optional transcript annotations with definition selection, and existing reporting. Reviewed GPA proposals are implemented below. Program/level-specific policy sets, term-only or backdated GPA assessments, load/classification-based proposals, additional annotation presentation policies and graduation decisions remain separate work. [Enrollment-load bands and working reviews](enrollment-load.md) are implemented independently; they do not make standing or official census decisions. Standing itself does not change student activity, account access, registration holds, grades, earned credits, enrollment, charges or graduation. Institutions should not rely on this label alone for automated financial-aid, immigration or other eligibility decisions.


## Reviewed GPA standing proposals

The institution can publish cumulative-GPA bands that suggest a standing for a new period. A proposal does not assign standing automatically. An authorized registrar chooses the effective dates, reviews the complete before/after periods, and confirms the determination. The existing manual workflow remains available independently.

### Publish institutional GPA bands

Open **Students → Standing definitions → Standing proposal policy**. Configuration requires `sis:standing:configure` and `sis:student:read`; ordinary standing management does not grant policy configuration. Existing permissions are reused and no role/user grants are added.

1. Create the institution's standing definitions first, then add up to twenty GPA bands and choose an active definition for each.
2. Enter increasing GPA floors between **0.000 and 10.000**. An enabled policy must start at **0.000**. The highest floor met applies, inclusively. For example, floors 0.000 / Academic review and 2.000 / Good standing would place exactly 2.000 in Good standing. These are examples, not seeded definitions or policy.
3. Optionally require a minimum number of **institutional GPA credits**. These are credits used in the verified GPA after retained repeat rules, rather than all registered or earned credits. Undefined GPA never becomes zero GPA.
4. Enable proposals, enter a reason, review the current and proposed policy, then publish. Before the first publication the screen explicitly says that no proposal policy is published; the technical version-zero state is not an institutional decision.
5. If another administrator publishes first, refresh the policy version and review the latest rules before replacing them. Refresh, definition search, paging and failed saves preserve the draft, including unfinished numeric inputs. Access denial clears the private policy controls. Nothing is stored in browser storage.

One institution-wide policy applies to supported current primary pursuits. Separate program/level-specific policy sets remain future work. Policy publications retain the chosen definition identities and labels. Retiring or renaming a definition never relabels earlier policies or student decisions. If the currently proposed definition changes or retires, fresh proposals require policy review; disabling the policy remains possible without discarding its retained labels.

### Propose, review and confirm a new period

Open **Students → a student → Academic standing**, then choose **Propose from current GPA**. This requires the existing standing-management and student-read duties and a verified current primary, active program pursuit. Closed-pursuit corrections retain their separate manual evidence workflow.

The calculation uses that pursuit's selected retained requirements, grading publications, repeat-GPA rules and verified academic work. It evaluates **institutional cumulative GPA**, excluding transfer work even when an overall-GPA policy includes transfers. Failing GPA-bearing work can produce a verified zero GPA. Missing requirements, undefined GPA, incompatible grading, unverifiable history, insufficient GPA credits or replay limits cannot produce a successful partial proposal. A proposal read writes no standing or academic decision.

The new period initially uses the calculation's current date. Complete its inclusive dates and retain or shorten earlier periods as appropriate; periods still cannot overlap. This is a calculation from **current recorded work**, not a reconstruction of GPA at an earlier effective date or a term/census calculation. Effective dates remain the institution's reviewed determination.

The editor shows only the proposed label, GPA, GPA credits, calculation date and published policy version. Degree-progress navigation preserves the draft, reason and proposal. **Use manual review instead**, changing the proposed standing, or removing its period clears the proposal evidence from the draft and announces that change. Manual review does not claim a verified GPA proposal. Recalculating replaces the pending proposed period while keeping its entered dates and optional reference.

Review and confirmation recheck the actual source, not merely the resulting standing label. Another completed course with the same GPA, a new publication with identical bands, a cohort/requirements change, an expired review or a retired definition requires a fresh proposal/review. Final required-audit, source, permission and expiry failures roll back the decision, period projections and receipt together. Original accepted retries return their original result after later grade, policy or definition changes.

### Retained evidence and privacy

A confirmed proposal retains its exact institutional-policy publication, rules, requirement publication reference, source fingerprints/positions, institutional GPA totals and compact frozen calculation inputs in protected metadata on the existing standing decision. The frozen grading/repeat settings and attempts reproduce the accepted GPA independently of later working records. Genuine policy replay verifies that the retained bands belong to the named publication. Identity routing binds the evidence to the exact student, pursuit and new period. Existing manual events remain unchanged and do not acquire inferred proposal evidence.

**Standing history** displays only the accepted GPA, GPA credits, calculation date and policy version alongside the retained period. The compact attempts, source positions and internal hashes remain protected. Student/advisor standing reads, transcript annotations and the existing standing report continue to expose their established period fields; this adds no report, automated registration hold, activity change, grade, credit, charge or graduation decision.

Calculations share a bounded replay allowance, with a four-MiB/five-cooperative-second read budget and a separate one-MiB retained-evidence limit. At most five hundred frozen attempts are supported. The additive portable migration `20261125000003` creates only a zero-version policy counter; publications and accepted proposal evidence remain in the single protected journal. Native PostgreSQL DDL/preservation is qualified separately from the populated fictional SQLite workflows and bounded browser checks.
