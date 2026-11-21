# Curriculum reporting

Use **Reports → Open Report Studio → Start from a template** to inspect component ownership or selected student curricula. Current setup and retained student choices are separate sources. These reports do not select curriculum, assign requirements, evaluate degree completion or award credentials.

## Current component ownership

Choose **Current component ownership**. The **Current curriculum components** source has one row per program-owned major, minor, concentration, track or specialization, including retired entries. It includes the current program, component code/name/type, availability, responsible department, that department's school/college and optional required major. Filter **Available in current setup** if you want active entries only.

These labels describe today's library. Editing a department, school or component changes a dynamic report from this source. It does not show what an earlier cohort offered or what any student selected. An unclassified earlier reference has an empty type; no role is inferred from its name. Source access requires `sis:catalog:manage` plus the existing report-management/execution duties. It grants no student access.

## Selected student curriculum

Choose **Selected student curriculum**. The source has one row per currently recorded component choice within a student program pursuit. A student with one major and one concentration therefore has two rows. A student with no recorded component choice, or a deliberately empty selection, has no component row; use **Student programs & catalog cohorts** for the complete pursuit population.

The program, catalog year/revision, component role/code/name, responsible department/school and required major come from the exact program publication retained by the selection. Current library renames, retirements, department moves and reparenting never relabel those rows. Student names/institutional IDs and pursuit status are explicitly current. Closed pursuits remain included until filtered out. The source requires `sis:student:read` and the existing reporting duties, independently of catalog management or curriculum-assignment authority.

**Selection matches current cohort** compares the selection's exact program publication and cohort-binding revision with the pursuit's current binding. False means the retained choices need deliberate review against the current cohort; changing a cohort and later changing it back still requires that review. It does not automatically clear old choices or their history. Add **Program pursuit status**, filter **Selection matches current cohort**, and choose catalog/program/type filters deliberately for the population you need.

For earlier publications, empty retained labels can mean missing evidence. Use **Component type recorded**, **Department recorded** and **Required-major relationship recorded** to distinguish that case from an explicit independent/unassigned choice. A recorded blank department means unassigned; an unrecorded department remains unknown. Neither is filled from today's library. Required-major labels identify a selection dependency, not another credential or department owner.

## Headcounts and summaries

Choose **Students by major and department** for a starter grouped by retained program, component type/code and department. It supplies both **Distinct students** and **Selected components**. Use distinct student references for a headcount within each group. Counting component rows counts choices, not students, degrees or awards.

One student may belong to several component or department groups. Adding the groups' headcounts can double-count that student. To find a single total across majors and concentrations, remove those grouping columns and count distinct students once. Program pursuits and selected components remain separate grains, and none of these counts establishes enrollment load, graduation eligibility or an award.

Ordinary typed filters, grouped conditions, column selection, saved definitions, explicit sharing, private CSV/background exports, static captures and expiry reuse the existing Report Studio controls. Recipients need the source permissions at read/export time. A static capture keeps its original rows after subsequent curriculum changes; it does not become an action population. Protected reasons, actors, answer documents, raw event payloads and requirement grammars are unavailable as columns or hidden filters.

## Open the exact student workflow

Save a live **Selected student curriculum** detail report with **Student reference** and **Program pursuit reference** included as unaggregated columns. Expand **Open a student's selected curriculum** beneath the saved results, then choose **Open curriculum · row …**. The server verifies current student/pursuit scope before opening the existing component selection screen. **Back to report** reopens and refreshes the saved report. Denied access returns to the saved report with an error; it performs no curriculum mutation. Closing or replacing the loading drawer ignores a delayed response, so it cannot reopen a dismissed task.

The navigation is available only for live detail rows, not grouped results or static captures. It creates no decision and grants no editing authority. Changing choices or assigning requirements still requires the independent `sis:degree-audit:assign` duty and the ordinary reasoned review/confirmation. Selection revise/confirmation and requirements/history navigation preserve the return destination. The full program publication remains authoritative if the report's selection has become stale.

## Projection and migration

Apply **`20261121000013_CreateSisCurriculumReports`** before running these reports. It adds the ULID-keyed `sis_student_curriculum_components` reporting projection and nullable completion/count checkpoint on the existing selection index. New selection and requirement-assignment decisions update the projection inside the same event/index/audit transaction, with final authority, expiry and projection checks. Removal clears only current report rows; retained decision events remain in the existing `sis_history_events` journal. Earlier accepted retries preserve later selections and do not repopulate removed choices.

For populated upgrades, each invocation processes at most twenty existing pursuit selections, with sorted student locks and one shared bounded history-read allowance. It verifies the recorded selection/requirements streams against their existing projections and verifies the exact retained program publication before indexing its facts. A completed batch is retryable; if more remain, retry the migration until completion. Unknown earlier fields remain unknown. Conversion never infers a selection from current setup, edits a publication, appends a historical event or changes grants. Failed verification rolls back that batch and stops conversion for investigation.

Ordinary reports check completion, per-pursuit row counts and stream/decision checkpoints before executing. A mismatch returns a fixed verification notice without a partial result. They query normalized indexed rows instead of replaying/decrypting individual event streams or parsing JSON with engine-specific SQL. Selected domain reads additionally compare complete component rows with the retained selection/publication. The lightweight reporting probe is not an independent cryptographic integrity audit, full reconciliation or release certification. Live multi-engine, representative-load, backup/restore and independent privacy/accessibility acceptance remain roadmap work.
