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

### Choose periods and effective-date presentation

After enabling **Include recorded academic standing** in **Transcripts → Transcript setup**, choose **Standing periods to include**:

- **All recorded periods through preparation** includes selected periods whose start is on or before preparation day, including earlier ended periods. This is the default and preserves the previous behavior.
- **Only standing effective at preparation** includes a selected period only when its inclusive start/end contains preparation day. A period ending on that day still applies. This does not print the last expired period or infer good standing when the date falls in a gap.

**Print standing effective dates** is enabled by default. Clearing it prints retained codes/names without each period's start/end. The document's preparation cutoff remains visible; this is a presentation choice, not a change to standing records or staff access. Definition selection and period selection apply together: an effective but unselected definition is omitted, without substituting an older selected period. Empty current selections say **No selected academic standing is included for this date**. Without a definition filter, an empty effective period says **No standing is recorded for this date**.

Both controls retain their draft through definition search/paging, failed saves and temporary disabling of standing. They remain editable during a pending save; later edits stay visibly unsaved and the next save uses the accepted revision. Loss of setup authority clears private controls. No choices are kept in browser storage.

### Preserve presentation on originals and later corrections

A preparation review binds the complete policy, period/date choices, actual standing source and cutoff. A source or setup change requires another review even if the printed result would be unchanged. The protected PDF, accessible HTML, private preview and preparation evidence retain the accepted choices. Subsequent setup edits and later calendar days cannot reinterpret that original. Delivery uses the original cutoff and presentation, together with the current separate delivery-check setup.

A correction that changes an included label, number/order of printed periods, or effective membership at the original cutoff requires fresh preparation before delivery. A date-only correction requires it when dates were printed. When dates were omitted and the same periods remain effective/selected in the same printed order, the existing original can remain current. Changes to expired periods omitted by effective-only mode do not change that original. These rules never alter or erase a standing decision; original artifacts/receipts stay retained, and required audit/source failures roll back coupled writes.

Current settings and new preparation confirmations explicitly retain `standing_presentation` with `periods` (`through_cutoff` or `effective_at_cutoff`) and Boolean `show_dates`. New setup API commands must include both typed choices, even with standing disabled. Earlier encrypted setup packets and document confirmations without this field retain their actual original all-periods/date-visible behavior without being rewritten. Older standing snapshots remain in their original shape and their existing fingerprints remain verifiable. No migration, grant, report or additional event stream is needed.

## Historical and security boundary

`StudentStandingWasRecorded` decisions use one `StudentStanding` aggregate per pursuit in the existing `student_standing` stream type. The same authoritative `sis_history_events` journal protects actor, reason, confirmation time, correlation, exact dated periods, accepted definition identity/labels, prior operation and before-state fingerprint. Effective periods are explicit event facts; the event context records the confirmation time. Unknown earlier standing remains unknown. Migration creates no historical decisions.

`sis_student_standing` is the reconstructible current projection. `sis_standing_definitions` is an audited, revisioned CRUD library of labels; it is not an academic rule engine. Commands use student/definition locks, expected versions, expiring actor/instruction-bound reviews, bounded replay, required audit and one atomic transaction. Completion-time source, authority, definition, expiry and projection checks roll back failed decisions. Repeating an accepted confirmation returns its original receipt even after later changes or closure, without replacing later facts. `StudentStandingLedger` shares selected replay and protected-head/projection verification across maintenance, private reads and the internal transcript source port. Ordinary report/search pages remain bounded projection reads; a transcript without standing selected performs no standing-source verification.

Browser mutations keep framework CSRF; responses remain private/no-store with debug collectors suppressed. Drafts/proofs stay in page memory. Delayed responses cannot replace a later pursuit selection. Migration `20261120000008_CreateSisStudentStanding` adds ULID-based reference/projection tables and independent permissions without rewriting the existing journal. Schema compilation covers the five framework dialects; live multi-engine and institutional acceptance remain separate qualification work.

The migration was applied to the configured DDEV application database on November 20, 2026. All twenty recorded events remained intact; before/after checksums matched for the journal, stream heads and operation receipts. Existing role/user permission-grant counts stayed unchanged. The new tables start empty, with no invented standing or default institutional labels. Focused verification passed 66 PHP tests, nine browser scenarios, real-provider HTTP/CSRF/private-response checks and coding standards for the changed code. Browser scenarios ran with one worker and fictional records.

The follow-up permission-only migration `20261120000009_CreateSisStandingCorrectionDuty` was also applied on November 20. It added the protected correction duty without changing existing grants, transcript setup or documents. The same twenty events and all three history checksums remained unchanged. Connected verification passed 77 PHP tests, nine browser scenarios, real-provider standing/transcript HTTP checks and coding standards for the changed code, using fictional records and one browser worker. No event conversion, new journal or extra recovery workflow was introduced.

## Remaining policy work

This completes reviewed manual standing, historical corrections, setup, private display, optional transcript annotations with definition selection, and existing reporting. Reviewed GPA proposals are implemented below. Program/academic-level policies are supported below. Backdated GPA assessments, load/classification-based proposals and graduation decisions remain separate work. Current-record term GPA is supported below. Transcript period selection and date presentation are supported below. [Enrollment-load bands and working reviews](enrollment-load.md) are implemented independently; they do not make standing or official census decisions. Standing itself does not change student activity, account access, registration holds, grades, earned credits, enrollment, charges or graduation. Institutions should not rely on this label alone for automated financial-aid, immigration or other eligibility decisions.


## Reviewed GPA standing proposals

The institution can publish cumulative or term-GPA bands that suggest a standing for a new period. A proposal does not assign standing automatically. An authorized registrar chooses the effective dates, reviews the complete before/after periods, and confirms the determination. The existing manual workflow remains available independently.

### Publish institutional GPA bands

Open **Students → Standing definitions → Standing proposal policy**. Configuration requires `sis:standing:configure` and `sis:student:read`; ordinary standing management does not grant policy configuration. Existing permissions are reused and no role/user grants are added.

1. Create the institution's standing definitions first, then add up to twenty GPA bands and choose an active definition for each.
2. Enter increasing GPA floors between **0.000 and 10.000**. An enabled policy must start at **0.000**. The highest floor met applies, inclusively. For example, floors 0.000 / Academic review and 2.000 / Good standing would place exactly 2.000 in Good standing. These are examples, not seeded definitions or policy.
3. Optionally require a minimum number of **institutional GPA credits**. These are credits used in the verified GPA after retained repeat rules, rather than all registered or earned credits. Undefined GPA never becomes zero GPA.
4. Enable the institutional default, enter a reason, review the current and proposed policy, then publish. You can instead leave the default disabled and enable specific program or academic-level overrides below. Before the first publication the screen explicitly says that no proposal policy is published; the technical version-zero state is not an institutional decision.
5. If another administrator publishes first, refresh the policy version and review the latest rules before replacing them. Refresh, definition search, paging and failed saves preserve the draft, including unfinished numeric inputs. Access denial clears the private policy controls. Nothing is stored in browser storage.

One reviewed publication contains the institutional default and optional program/academic-level overrides. Policy publications retain the chosen definition identities and labels. Retiring or renaming a definition never relabels earlier policies or student decisions. If the currently proposed definition changes or retires, fresh proposals require policy review; disabling the policy remains possible without discarding its retained labels.

### Configure program and academic-level overrides

From **Standing proposal policy**, choose **Add program policy** or **Add academic-level policy**. Search the appropriate reference library, use **More policy targets**/**Previous policy targets** to page, and select the target. Configuration authority is sufficient; catalog-management permission is not required. Up to twenty distinct overrides may be published together. Choosing an already selected identity reopens its draft rather than adding a duplicate.

Each override has its own enabled choice, up to twenty GPA bands and minimum institutional GPA credits. The same floor rules apply as for the default. **Back to proposal policy** preserves the override, default inputs and reason. Definition lookup, target lookup, review, Keep editing and explicit version refresh retain the draft in memory, including unfinished numeric values. Remove an override to restore the next applicable rule. Disabling an override deliberately prevents proposals for its scope. Review shows the current and proposed default and expandable details for every override before one reasoned publication.

For example, a fictional institution could leave the default disabled, enable undergraduate proposals at a 2.000 good-standing floor, and enable one program at a 2.500 floor. These are examples, not supplied policy. Target identities and labels are retained alongside the bands. **Re-select policy target** refreshes a renamed target or deliberately selects a different target of the same kind while preserving its GPA draft. A target already used by another override cannot be duplicated. A renamed/retired target must be reviewed before an enabled publication; an existing override may retain its old labels while being explicitly disabled or removed. Later reference edits do not rewrite earlier publications or accepted decisions.

### Resolve the applicable standing policy

For the student's current primary active pursuit, precedence is explicit:

1. Use the matching **program policy**, including its enabled choice.
2. Otherwise, use the matching **academic-level policy** from the student's actual assigned, verified published program cohort.
3. Otherwise, use the **institutional default**.

The first matching rule controls both GPA floors and minimum GPA credits. A disabled matching override stops the proposal; it never falls through to a broader enabled rule. Conversely, an enabled override can operate while the institutional default is disabled.

Academic level comes from the assigned retained cohort, not the program code, today's working program definition, a newer unassigned publication, or the selected degree's name. If academic-level overrides exist and no program override matches, an unknown/unverified cohort level requires a records review rather than silently using the default. A verified publication that explicitly has no academic level may use the default. An explicit program override does not require an unused academic-level lookup. Assigning a different cohort requires a fresh proposal even if the GPA and resulting label remain unchanged.

### Propose, review and confirm a new period

Open **Students → a student → Academic standing**, then choose **Propose from current GPA**. This requires the existing standing-management and student-read duties and a verified current primary, active program pursuit. Closed-pursuit corrections retain their separate manual evidence workflow.

The calculation uses that pursuit's selected retained requirements, grading publications, repeat-GPA rules and verified academic work. It evaluates **institutional GPA under the applicable cumulative or term basis**, excluding transfer work even when an overall-GPA policy includes transfers. Failing GPA-bearing work can produce a verified zero GPA. Missing requirements, undefined GPA, incompatible grading, unverifiable history, insufficient GPA credits or replay limits cannot produce a successful partial proposal. A proposal read writes no standing or academic decision.

The new period initially uses the calculation's current date. Complete its inclusive dates and retain or shorten earlier periods as appropriate; periods still cannot overlap. This is a calculation from **current recorded work**, not a reconstruction of GPA at an earlier effective date or a census calculation. A selected-term assessment uses that term’s current recorded work as described below. Effective dates remain the institution's reviewed determination.

The editor shows the proposed label, applied policy scope with retained target code/name where applicable, GPA, GPA credits, calculation date and published policy version. Degree-progress navigation preserves the draft, reason and proposal. **Use manual review instead**, changing the proposed standing, or removing its period clears the proposal evidence from the draft and announces that change. Manual review does not claim a verified GPA proposal. Recalculating replaces the pending proposed period while keeping its entered dates and optional reference.

Review and confirmation recheck the actual source, not merely the resulting standing label. Another completed course with the same GPA, a new publication with identical bands, a cohort/requirements change, an expired review or a retired definition requires a fresh proposal/review. Final required-audit, source, permission and expiry failures roll back the decision, period projections and receipt together. Original accepted retries return their original result after later grade, policy or definition changes.

### Retained evidence and privacy

A confirmed proposal retains its exact institutional-policy publication, rules, requirement publication reference, source fingerprints/positions, institutional GPA totals and compact frozen calculation inputs in protected metadata on the existing standing decision. The frozen grading/repeat settings and attempts reproduce the accepted GPA independently of later working records. Genuine policy replay verifies that the retained bands belong to the named publication. Identity routing binds the evidence to the exact student, pursuit and new period. Existing manual events remain unchanged and do not acquire inferred proposal evidence.

**Standing history** displays the accepted GPA, GPA credits, calculation date, policy version and applied scope with retained target code/name alongside the retained period. The compact attempts, source positions and internal hashes remain protected. Student/advisor standing reads, transcript annotations and the existing standing report continue to expose their established period fields; this adds no report, automated registration hold, activity change, grade, credit, charge or graduation decision.

Calculations share a bounded replay allowance, with a four-MiB/five-cooperative-second read budget and a separate one-MiB retained-evidence limit. At most five hundred frozen attempts are supported. The additive portable migration `20261125000003` creates only a zero-version policy counter; publications and accepted proposal evidence remain in the single protected journal. Native PostgreSQL DDL/preservation is qualified separately from the populated fictional SQLite workflows and bounded browser checks.

### Retain and verify the accepted policy scope

New proposal evidence also records the original receiving program and captured pursuit-stream position. When level rules must be considered, it retains the exact assigned academic-year/program-publication references and that publication's academic-level snapshot, including an explicitly absent level. Bounded historical replay verifies the captured pursuit position and its actual cohort assignment, then authenticates that original program publication. A genuine but unassigned publication cannot replace this evidence. Later cohort moves, new program definitions or policy changes do not reinterpret an accepted decision.

The same standing event, policy stream and protected journal retain these facts; no additional event table, projection field, permission or migration is introduced. Earlier three-field policy publications and standing evidence without scope keep their actual original shape and calculation. Their display explicitly says that scope was not recorded rather than supplying today's program or level. A genuinely unjournaled earlier pursuit can support a program/default rule through its verified immutable student/program identity, recorded with position zero and no invented cohort history; academic-level rules still require recorded assigned-cohort evidence.

Staff responses expose only the applied kind and retained target code/name. Internal program/cohort references, captured stream positions and frozen calculation inputs stay protected. Owner/advisor, transcript and existing report field boundaries are unchanged. No GPA proposal assigns standing automatically or adds a report.

### Choose cumulative or term GPA in each rule set

In **Students → Standing definitions → Standing proposal policy**, choose **GPA basis** in the institutional default or a program/academic-level override:

- **Cumulative GPA** uses the verified institutional work in the current receiving pursuit. This preserves the earlier behavior.
- **Term GPA** uses only verified institutional work posted to the term staff select when preparing a proposal. The same retained grading and repeat rules apply within that term; grades outside it cannot replace an attempt in this calculation.

Each rule set has its own basis, bands and minimum GPA credits. Program → assigned-cohort academic level → institutional-default precedence still selects the complete applicable rule set. A matching program rule can therefore use cumulative GPA while a broader rule uses term GPA. The highest met GPA floor applies as before. Minimum GPA credits are measured after repeat rules within the selected basis: six cumulative credits cannot satisfy a six-credit term minimum when only three qualify in that term.

Definition and target lookup, Back to proposal policy, version refresh and rejected reviews preserve the chosen basis with the rest of the draft. Publication review shows the current and proposed basis. Changing it creates a new genuine publication under the existing permissions, expected-version and audit rules; earlier publications and accepted decisions remain exact. No sample institutional policy is seeded. Earlier rule sets without `gpa_basis` retain their actual original cumulative meaning; new editor commands send `cumulative` or `term` explicitly.

### Select a posting term and calculate standing

Open the student's **Academic standing** editor. For a term-based policy, choose **Choose proposal term**, search or page through twenty calendar references at a time, and use the desired posting term. **Back to academic standing** preserves the draft and reason. These are current calendar reference labels, not evidence that the student has qualifying work. The chooser needs standing-management/student-read authority, without catalog-management authority. Closed or non-primary pursuits retain their separate manual correction workflow.

Choose **Propose from current GPA**. A term policy requires a term; a cumulative policy requires clearing it. This prevents a selected term from being silently ignored or missing selection from becoming cumulative GPA. An empty term has undefined GPA and cannot produce a zero-GPA determination. Verified failing GPA-bearing work can produce zero GPA normally.

Term membership uses the academic record's verified **posting term**, including a reviewed posting-term correction, rather than the original registration term, course dates, completion date or today's section setup. Work remains scoped to the receiving pursuit. Transfer awards remain outside institutional GPA even if an overall-GPA policy counts them. Work from a previously graduated program still needs formal receiving transfer approval, including same-institution work; choosing a term does not bypass that policy. Current source history must be verified before selection; choosing a term does not hide inconsistent or unknown academic history.

This is **current recorded work in the selected term**, not GPA as known at that term's end or an earlier historical cutoff. The calculation does not require or infer that the term is complete. Retained repeat rules operate within the selected term; cumulative degree-progress and undergraduate-to-graduate registration GPA rules remain separate and unchanged. Effective standing dates still require staff review and confirmation.

Changing or clearing a selected term removes the pending proposed row and its proof. Entered effective dates, optional policy reference and reason remain available for the next calculation, which replaces that pending proposal without duplicating it. **Use manual review instead** retains its existing explicit behavior. Private controls clear on lost chooser authority; late responses cannot replace a later record. No draft or proof is stored in browser storage.

### Retain and verify the accepted GPA assessment

New protected proposal evidence records the cumulative/term basis. Term evidence additionally retains the selected posting-term identity, its calendar code observed at calculation, and the verified posting-term identity on every frozen contributing attempt. Typed replay rejects missing/mismatched basis, mixed terms and transfer attempts in a term assessment, then recomputes GPA from the compact frozen inputs using the published rules. The same standing event, policy root, journal, bounds and original receipts are used; there is no new projection, schema or report.

Unaccepted proposals bind the exact term, label, source and policy. Posting corrections, calendar-label changes, new policy publications or other captured source changes require a fresh proposal even when GPA/band are unchanged. Final audit/source/authority failures roll back coupled source changes, the standing projection and receipt together. Accepted retries and history retain their original assessment after later term-label, posting, grade or policy changes.

Staff proposal/history summaries show only the applied basis and retained term code in addition to the existing minimized GPA evidence. Posting-term identities, attempts and source positions stay protected. Earlier evidence without assessment remains genuinely cumulative and is not rewritten. Student/advisor standing, transcript annotations and existing reports keep their established period-only fields; this adds no report, registration rule, credit, charge or automatic standing decision.
