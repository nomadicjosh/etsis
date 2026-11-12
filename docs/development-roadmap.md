# Development roadmap

Last reviewed: **2026-11-11**, after adding reviewed registrar course-placement preferences and the optional Moodle connection-diagnostic foundation.

etSIS has working foundations across the legacy modules, a staff workspace and a student/public portal. The largest remaining work is completing historically reliable academic workflows, evaluating degree progress, expanding office operations and proving recovery and deployment behavior. A working screen or passing isolated test does not make an entire module complete.

This is the planning overview. [Backend coverage](backend-migration.md) records implementation detail; [workflow review](workflow-review.md) records the approved staff experience. The [event-sourcing architecture](event-sourcing-architecture.md) and [historical field review](academic-history-field-review.md) govern historical and security acceptance. Legacy modules are integrated capabilities, with no paid-add-on gates.

## Reading the estimates

Preserve historical facts and support attributed corrections and controlled replay/recovery. AJAX search can avoid unnecessary initial directory requests; AJAX updates retain the event-sourced command and transaction boundaries they require.

Effort describes remaining implementation, interface work, tests and documentation together:

| Estimate | Approximate scope |
| --- | --- |
| Small | One focused change within an established workflow. |
| Medium | Several connected changes, usually spanning domain rules, persistence and the staff interface. |
| Large | Multiple end-to-end workflows, with historical definitions, policy choices or integrations to resolve. |
| Very large | Several large milestones with substantial cross-domain acceptance work. |

These are relative estimates, not calendar commitments or percentages complete. Institutional policy choices, integration scope and findings from database/load/recovery testing can change them. The remaining scope is substantial; it should be delivered in small, reviewable stages rather than treated as one final polish pass.

“Working foundation” below means the stated behavior exists. “Remaining” items are not complete. Check off a remaining item only after its relevant domain, UI, historical/security, testing and documentation acceptance checks pass.

## Working foundations

| Area | What is available now |
| --- | --- |
| Architecture and identity | PHP 8.4 minimum; CodefyPHP Application/Domain/Infrastructure boundaries; ULID identities; canonical person records, institutional IDs and linked login accounts. |
| Access | Database roles, permissions and additional user grants; protected system definitions; separate registrar duties; unrestricted `super` authority. |
| Staff workspace and portal | Bootstrap staff screens and metrics; public campus content/course discovery; Vihzhuo themes/blocks and authenticated student services. |
| Academic setup | Term types, reviewed academic-year/term generation, separate term maintenance and derived matriculation cohorts. |
| Published academic definitions | Academic-year program definitions with retained catalog references; course publications and explicit offering bindings; reviewed section publications with instructional dates and current offering facts; reusable course groups, degree compositions and global/program grading-scale publications. Initial section-version selection and reviewed future-registration amendments are available; offering-plan activation retains those references atomically; further definition coverage remains incomplete. |
| Student records | Program assignments, primary-program selections, activity history, requirement-version assignment, waivers, substitutions and explicit degree/group minimum exceptions, including whole-course totals and per-course grade-point floors. |
| Admissions | Program application forms, protected submission references, reviewed acceptance/rejection, independently authorized pre-matriculation corrections and atomic initial matriculation. |
| Registration and academic credit | Accepted seats and section decision order; early/late drops coupled to billing; institutional grades/corrections and transfer award/correction/void/reinstatement history. |
| Finance | Event-sourced student-account postings/reversals, term billing rules and balanced journals; registrar/finance review handoff. Portal payment integration exists separately; live settlement is not established by isolated tests. |
| Transcripts | Reviewed preparation, protected PDF/accessible HTML snapshots, immutable document revisions and delivery evidence. |
| Office tools | Visual joined Report Studio, report requests/sharing, mapped course CSV import, room bookings, timesheets, encrypted communications and campus content management. Each has remaining scope below. |
| Historical storage | One protected authoritative event table for framework/domain events, expected versions, durable receipts, atomic writes, registered schemas and bounded stream replay. Complete operational recovery remains outstanding. |

## Milestone order

| Priority | Milestone | Remaining effort | Dependency and completion outcome |
| --- | --- | --- | --- |
| 1 | Historical definitions and security/recovery | Very large | Extend the existing foundation first and alongside each affected workflow. Academic facts retain their applicable definitions; verified rebuilds and restores preserve them. |
| 2 | Registrar and registration completeness | Large | Build on retained definitions. Corrections, eligibility and lifecycle decisions preserve earlier facts and their financial effects. |
| 3 | Student degree-audit evaluation | Large | Consume retained academic facts and the implemented requirement/exception publications. Explainable student results precede graduation decisions. |
| 4 | Admissions and student-service completion | Large | Extend the existing submission/decision/matriculation streams. Staff and applicants can follow a complete, authorized case without duplicate entry. |
| 5 | Reporting, populations and conversion | Large | Add domain read models as their source workflows stabilize. Satisfy the actual legacy SELECT examples through the visual builder and safe action handoffs. |
| 6 | Finance and institutional integrations | Large | Extend existing monetary histories with explicit policies and real integration acceptance. Reconcile internal postings with external outcomes. |
| 7 | Scheduling, employment and office workflow breadth | Large | Complete the supplied legacy module workflows with the same connected staff experience and privacy controls. |
| 8 | Release qualification and operations | Large | Starts during earlier milestones; finishes after the intended release scope is agreed and verified end to end. |

This is an order of emphasis, not a requirement to finish every item in a large milestone before doing any independent work elsewhere. Historical/security acceptance applies to every stage. Each implementation stage should identify the checklist items it advances and update this document.

## 1. Historical definitions and security/recovery

- [ ] Finish normalized classifications: [subjects](course-subjects.md), [course departments/levels](course-classifications.md) and [program department/academic-level/award publications](program-publication.md) have setup, assignment and retained references. School, major/minor/specialization, CIP/location classifications and further program relationships remain.
- [x] [Link new degree-requirement publications to the reviewed program definition](degree-program-publication-link.md), retain earlier publications and require matching program catalog references for new student selections. Earlier unlinked history remains explicitly unlinked.
- [ ] Complete section publications: instructional dates, retained course/grading/registration policy, delivery classification, teaching assignments and fee references. Review later changes against affected students.
- [ ] Support versioned fixed/variable-credit rules and student-selected credits; keep CEUs separate from academic credits. Add explicit reporting-term/group relationships where required.
- [ ] Finish trusted actor, reason, effective-date and correlation context for older framework/financial command paths. Course, program, reusable course-group and degree-requirement publications now use confirmation time, expiring reviews and permission rechecks while preserving earlier completed receipts/events. Preserve real existing events and mark unknown earlier facts honestly.
- [ ] Reconcile remaining consequential hiatus/return and policy/status writers into canonical streams without duplicating actions or discarding existing case evidence.
- [x] [Resolve shared retained course histories once per degree read](student-degree-requirements.md#shared-course-history-reads), with exact version selection, bounded verification and no reuse across separate invocations. This reduces repeated replay in student requirement reads/reviews/confirmations; it does not complete evaluation or operational rebuilds.
- [ ] Provide bounded, read-only integrity/reconciliation tools and independently verified projection checkpoints; rebuild into isolated output, validate it and deliberately switch readers. Replay must never send messages, issue refunds or grant access.
- [ ] Implement and exercise key rotation/recovery, independently protected integrity anchors, least-privilege database access and backup/restore procedures.
- [ ] Establish institutional retention categories, legal holds, protected artifact/document retention and access/export evidence, including backups. Preserve necessary facts without retaining unnecessary sensitive material indefinitely.

**Done when:** earlier academic/financial results remain reproducible after today's definitions change; corruption or conflicting projections cannot silently alter decisions; isolated rebuild and restore exercises recover consistent results without repeating business actions. See [architecture acceptance](event-sourcing-architecture.md#retrofit-order-and-completion-gates).

## 2. Registrar and registration completeness

- [ ] Add reasoned replacements for closed institutional credits and transfer course/posting-term/completion identities. Retain the original and replacement lineage, and require fresh transcript preparation when results change.
- [ ] Complete versioned prerequisite/co-requisite expressions, schedule-conflict checks and retained eligibility-policy inputs.
- [ ] Add waitlists with an explicit institutional ordering/allocation policy, accepted server ordering and atomic seat/billing decisions. Browser click time is not a fairness guarantee.
- [ ] Extend reviewed enrollment actions to report-selected populations, multi-section schedules and explicit re-enrollment after closure; preserve distinct attempt and billing identities.
- [ ] Add dated academic standing, load/classification determinations, advisor relationships and registrar review workflows. Advising appointments/check-ins remain distinct from degree audits.
- [ ] Complete optional grade-change approval chains, closed-record corrections and weighted gradebook aggregation.
- [ ] Complete institution-specific transcript release rules, template selection and electronic delivery/signature integrations where selected.

**Done when:** staff can complete an authorized academic case in one connected flow; corrections preserve original facts, repeated confirmations cannot overwrite newer decisions, and academic/seat/financial effects commit together where required.

The approved drop policy remains: an early drop removes current enrollment/transcript projections and reverses its original course charge; a late drop retains the charge and institutional policy grade. A retake in another billing term is charged separately. Restricted historical events remain protected.

## 3. Student degree-audit evaluation

- [x] [Preview selected requirements with approved exceptions](student-degree-exceptions.md#preview-requirements-with-approved-exceptions) and compare the published baseline, preserving pursuit scope, retained labels and explicit conflict/cohort notices. This does not calculate student completion.
- [x] [Review current academic work on demand](student-academic-work.md), with 20-record pages, verified institutional/transfer histories, retained grade facts and explicit earlier-history gaps. This read-only tool does not allocate courses or establish historical completeness.
- [x] [Explain per-course requirement candidates](student-academic-work.md#checking-requirement-matches) against retained selections and applicable exceptions, including grade/history gaps and publication differences. This bounded inspection does not allocate work or calculate completion.
- [x] [Build the bounded structural allocation core](degree-audit-allocation.md): exact backtracking across eligible group/target choices, credit/course quantities, required/choose/pool rules, pursuit-wide waivers and retained sharing policy. Unknown/exhausted results remain distinct from academic failure; student-input, repeat/GPA and UI integration remain open.
- [x] [Add the retained repeat-credit/GPA calculation core](degree-audit-calculations.md), with separate credit/GPA decisions, equal-award alternatives, exact weighted arithmetic and explicit unresolved grades/scales/order/ties. The institutional ordering choice supports academic term/instructional period by default for new selections and explicit completion-date ordering. The available-source verification step is implemented; archive-wide completeness, remaining chronology evidence, grading compatibility and full evaluation integration remain open.
- [x] [Add bounded academic input verification](academic-input-verification.md): cross-check surviving current, registration, academic and transfer sources under one student lock and a shared replay budget. Keep early removals private and unknown/limited/inconsistent outcomes distinct. Archive-wide completeness reconciliation and evaluation integration remain open.
- [x] [Review current earned credits and GPA](student-credit-gpa-review.md) using the selected degree policies and verified available sources within one student lock and shared replay budget. Explain per-record contributions, missing evidence, ties and undefined GPA without partial totals. Remaining chronology evidence, wider completeness, allocation and final degree evaluation remain open.
- [x] [Retain and use institutional repeat ordering](repeat-attempt-ordering.md): default only new blank drafts to academic-period order, require a choice for new latest-attempt publications and resolve exact retained instructional starts only for competing GPA attempts. Preserve earlier unknowns and shared replay limits. Institutional completion dates and sending-institution period starts now have separate reviewed workflows; missing earlier evidence remains unknown.
- [x] [Review structural course-group placement](student-course-placement.md) from bounded verified available work, exact retained requirements and approved group exceptions. Apply group eligibility before repeat-credit ranking, retain tied evidence, respect sharing and expose no speculative partial plans. General degree minimums now have a combined review; historical transfer/equivalence gaps, other degree constraints and retained final evaluation remain open.
- [x] [Combine current general minimums and course-group placement](student-degree-progress.md) under one lock/read budget and one academic-input collection. Separate institutional cumulative GPA from policy-controlled overall GPA before repeat selection; compare approved credit/GPA minimums without rounding up or choosing a favorable resident-credit alternative. This is not retained graduation eligibility; broader historical/rule coverage and retained final results remain open.
- [x] [Explain clear course-group shortages](student-course-placement.md#understanding-shortfalls) from already prepared eligible evidence: bounded credits, distinct-course counts and named/choose requirements. Keep waivers, substitutions and repeat effects explicit; unknown inputs suppress comparisons, and optimistic bounds never become partial placements or proof of completion. Remaining complex conflicts still require the combined search and broader rule coverage.
- [x] [Explain supported shared-course shortages](student-course-placement.md#shared-course-shortages) across overlapping group pairs/components under the retained one-group policy. Use optimistic credit and distinct-course bounds, respect waivers and repeat evidence, suppress unknown/limited claims and reuse the captured result in combined degree progress. No additional replay, partial placement, academic write or exhaustive conflict diagnosis is introduced.
- [x] [Review unused and pending work](student-degree-progress.md#unused-and-pending-work) alongside independent credit/GPA treatment. Identify unused completed records only within a complete proposed group plan; keep tied alternatives explicit and unknown grades separate from known ungraded institutional attempts. Pending attempted-credit summaries and local posting-term filters add no replay or future-credit assumptions.
- [x] [Record actual institutional completion dates](academic-completion-dates.md) through reasoned registrar reviews in the existing academic-credit stream. Preserve earlier dates, support an explicit clear to unknown, keep grades/awards/charges unchanged and feed retained completion-date ordering without using posting timestamps. Existing unknown dates are not backfilled; closed-record replacement remains open.
- [x] [Retain sending-institution academic-period starts](transfer-academic-periods.md) through reasoned, expiring registrar reviews in the existing transfer-credit stream. Preserve completion/posting/award facts, keep period evidence through credit corrections and void/reinstatement, and allow an explicit clear to unknown. Feed verified source starts to academic-period repeat-GPA ordering; retain bounded curated history and original retry behavior without backfilling earlier chronology.
- [x] [Retain the accepted course publication on new transfer awards](transfer-course-publications.md), with explicit bounded choices, retained transcript labels, independent equivalency credits and verified credit/version indexes shared with section projections. Earlier unknown awards remain unbound; historical equivalence and replacement reviews remain open.
- [x] [Review accepted course definitions on existing transfer awards](transfer-course-publications.md#review-an-existing-awards-accepted-course), with same-course publication choices, effective dates/reasons, immutable prior decisions, explicit observations of older untracked facts, bounded history and protected retries. Keep credits, grades and finances unchanged; require fresh transcript preparation after changed labels. Cross-course identity replacement and broader equivalence rules remain open.
- [x] [Review curriculum-course placement preferences](student-degree-exceptions.md#course-placement-preferences) within one pursuit and selected degree publication. Retain server-derived course/group references, expiring reasoned reviews, supersession/revocation and original retries. Restrict eligible group choices without changing earned credits, GPA or finances; broader exclusions, replacement-only course preferences and individual-attempt selection remain open.
- [ ] Implement deterministic evaluation against the student's retained degree, group, course and grading publications, using authoritative institutional/transfer credit facts.
- [x] [Retain the institution-selected course-sharing policy](degree-audit-builder.md#course-sharing-across-groups) in each degree publication, defaulting new compositions to one group. Earlier selected policies stay retained; the structural engine now consumes this choice, while complete degree evaluation remains outstanding.
- [ ] Extend the supported structural placement review to remaining historical equivalence and requirement rules; retain explanations for applied, unused, insufficient-grade and in-progress work. The current engine already searches overlapping groups under the retained sharing policy rather than choosing a greedy plan.
- [ ] Complete remaining chronology, grading compatibility and residency rules beyond the current retained repeat-credit/GPA calculations, group quantities/grade floors and general minimum comparisons. Never convert grade scales implicitly.
- [ ] Carry the existing pursuit-scoped waivers, substitutions and minimum reductions through remaining requirement grammar and retained final evaluations. A course waiver removes that required course wherever it appears within the pursuit, including outside a group; it creates no earned credit/GPA and does not itself lower minimums.
- [ ] Complete specialization relationships/exclusions and any remaining requirement grammar against the agreed specification. Existing publications keep their original interpretation.
- [ ] Extend the current accessible progress reviews with remaining unmet-requirement explanations and controlled retained evaluation results, including enough input/version context to reproduce them.
- [ ] Add reviewed graduation eligibility, authorized award/credential decisions and later corrections. An audit result alone must not automatically confer a degree.

**Done when:** the same retained inputs reproduce the same explained result; later catalog changes leave earlier results interpretable; dual-degree pursuits remain separate; an authorized graduation decision is retained independently.

Authoring, exception inputs and [paged academic-work review](student-academic-work.md) already exist: [builders with live group planning totals](degree-audit-builder.md#group-planning-summary), [student requirement assignment](student-degree-requirements.md), [exceptions](student-degree-exceptions.md). Structural group placement is implemented; complete degree evaluation and retained final results are still outstanding.

## 4. Admissions and student services

- [ ] Complete safe anonymous/public application intake where required, with abuse controls, identity matching and a clear route into the existing protected submission workflow.
- [ ] Add institutions attended and appropriate contact/address/emergency-contact maintenance, including owner verification, restricted visibility and actionable correction requests.
- [ ] Add application completeness checklists, protected documents/references, office assignment and follow-up tasks in one application workbench. Define upload scanning and retention before accepting sensitive files.
- [ ] Add offers, applicant responses, withdrawal/reopening and any additional approval stages as explicit institutional policies and retained transitions. Post-matriculation rescission must coordinate student/program/academic/financial consequences.
- [ ] Complete applicant/student status and next-action views, advisor/academic-progress access and institution-selected service requests, reusing completed backend rules.
- [ ] Complete page-builder/content approval and scheduling workflows and verify theme/block accessibility across narrow screens and keyboard use.

**Done when:** applicants and authorized offices can see the appropriate next step, every consequential outcome retains its reason/context, and public intake cannot reveal another person's identity or records.

The existing [admissions corrections](admissions-corrections.md) feature covers accepted/rejected outcomes **before matriculation**; it does not yet implement these broader policy stages.

## 5. Reporting, populations and conversion

- [ ] Cover the remaining [legacy SELECT acceptance cases](legacy-reporting-review.md): financial aggregates at a declared grain, latest-row/anti-join patterns, repeated relationship aliases and richer typed expression trees.
- [ ] Extend authorized datasets for finance, contact/admissions, scheduling and other completed domains. Prevent one-to-many joins from inflating headcounts, credits or balances; keep mixed currencies separate.
- [ ] Add reviewed report-to-population handoffs. Selecting students must not grant authority to register, bill or message them.
- [ ] Add bounded full-result export jobs, cancellation, protected output retention/archival and frozen result snapshots where needed. Distinguish a shared live report from a retained historical extract.
- [ ] Expand mapped imports beyond courses: identity/dependency reconciliation, useful row errors, explicit conflict review and safe restartable batches.
- [ ] Build and exercise legacy-data conversion/reconciliation, preserving verified recorded history and explicitly identifying unknown earlier facts.

**Done when:** the visual builder answers the approved legacy questions without a raw SQL screen; field/source permissions apply even to hidden filters; exports are complete and bounded; conversion produces reconciled records with no invented history.

## 6. Finance and institutional integrations

- [ ] Add versioned per-credit and richer population-based pricing, with reviewed billing previews and understandable exceptions.
- [ ] Complete source-linked adjustment/refund cases, institution-defined refund schedules and installment arrangements. Account credit and a cash refund remain separate decisions.
- [ ] Complete automatic student-account-to-GL mappings, controlled period closing and reconciliation against retained postings.
- [ ] Verify gateway checkout, uncertain outcomes, duplicate notifications, refunds and settlement reconciliation through selected provider test environments before any live use.
- [x] Add the disabled-by-default [Moodle REST connection diagnostic](optional-moodle-integration.md), with bounded requests, fixed capability output and no enrollment/grade writes. Institutions explicitly opt in; Moodle is not required for SIS workflows.
- [ ] Build optional deep Moodle LMS integration: institutional single sign-on, authorized background course-section/enrollment synchronization and background gradebook synchronization. Establish identity matching and source-of-truth policies; preserve academic approval/history boundaries and make external synchronization idempotent.
- [ ] Add authorized operational workers, a durable outbox and idempotent consumers for external effects where needed. Store protected references rather than student payloads in jobs.

**Done when:** internal balances and external settlements reconcile; retries and replay cannot repeat charges/refunds; pricing and approval changes preserve the policy used for prior transactions. Installed SDKs and fictional gateway tests do not establish operational readiness.

### Optional AI assistance

- [x] Add a disabled-by-default, provider-independent course authoring contract, exact business-payload preview and per-request consent. Course readiness and description actions have separate protected permissions. See [setup and safeguards](optional-authoring-assistance.md).
- [x] Keep network calls outside transactions; validate bounded structured results, recheck draft/provider/actor changes, and require staff to copy/save/publish explicitly. Use an isolated client without framework HTTP debug hooks.
- [ ] Verify institution-selected OpenAI/Azure/local deployments and approved no-training/retention terms before enabling a real service.
- [x] Add saved-description rewrite and expansion, independent action grants, source-text validation and explicit copying that protects later staff edits.
- [x] Add optional saved-section readiness with a minimized preview, independent action permission, fresh consent, stale-source checks and no automatic publication changes. Unsaved dates/reasons remain outside this review.
- [x] Add optional saved-degree setup readiness for minimums, institutional policy selections and group-publication selections, with independent permission, exact preview and no student data. Group-content/coverage analysis remains outside this first review.
- [x] Add local next-step guidance to the captured degree-progress review, with no provider calls, extra record reads or academic mutations. External AI data sharing remains separate.
- [ ] Design contextual/movable student helpers that keep degree-progress data inside etsis. External student-progress AI is deferred by project direction; catalog-authoring AI remains separate. No automated academic decisions.

## 7. Scheduling, employment and office workflows

- [ ] Link published section meeting patterns to optional rooms/resources; support online/hybrid sections, multiple patterns, team teaching and institution timezone/DST behavior.
- [ ] Complete recurring booking authoring, calendar categories/timetable views and controlled notifications.
- [ ] Add employment position/pay-grade history and weekly timesheet exception/approval summaries; determine payroll calculation/export boundaries before implementing them.
- [ ] Add communications templates, office approvals, reviewed audience selection and scheduled dispatch/reconciliation without uncontrolled retries.
- [ ] Complete office ownership, resolution tasks and scoped delegation for holds and other shared queues; keep permission grants independent of case assignment.
- [ ] Provide contextual, privacy-safe support diagnostics and operational audit views. Raw logs and event payloads remain outside ordinary staff reporting.
- [ ] Review remaining legacy settings, custom-field/extension hooks and API use cases. Application-form fields already have a builder; other extension points need explicit typed, authorized contracts rather than legacy SQL/PHP execution.

**Done when:** supplied legacy module use cases have a usable replacement or an explicit documented scope decision, with accessible connected screens and no paid-module gating.

## 8. Release qualification and operations

- [ ] Run live-server migration/query/transaction acceptance for MySQL, PostgreSQL, SQLite, SQL Server and Oracle. Current abstraction/compiler coverage is not certification of every PDO driver.
- [ ] Exercise competing final-seat requests, large cohorts, long histories, reporting/export limits and failed/retried background work using bounded realistic fixtures.
- [ ] Complete independent authorization, privacy, upload, CSRF and security review; test keyboard/screen-reader flows, contrast, focus, errors, reflow and reduced motion against WCAG 2.2 AA.
- [ ] Exercise encrypted artifact and database backup/restore together, key loss/rotation recovery, failed rebuilds and deployment rollback/recovery procedures.
- [ ] Finish installation/upgrade/operations guides, office setup guides, policy configuration, safe monitoring and incident procedures.
- [ ] Run representative admissions-to-graduation and registration-to-billing-to-refund scenarios with fictional records, then institution-led acceptance of the agreed initial release scope.

**Done when:** the intended release scope is explicit and accepted, recovery is demonstrated, and operational/security/accessibility checks pass. Event sourcing supports evidence; it does not by itself certify legal compliance.

## Next implementation stages

1. **Section-version integration:** the [first publication stage](section-publication.md) now retains instructional dates and reviewed delivery/capacity/teaching/fee facts. The verified reference cache and explicit initial selection for new individual/self-service and group registrations are implemented. Reviewed amendments now preserve existing student references and charges while changing the selection for later registrations. Offering-plan review now includes section dates and offering facts; confirmation atomically retains their versions and activates empty drafts. Next, complete policy references and remaining definition fields; preserve unknown earlier definitions instead of backfilling invented ones. Full section/policy coverage in milestone 1 remains open.
2. **Policy and attempt integration:** retain the applicable section/policy references in new enrollment/academic facts, then complete reviewed replacements and remaining consequential lifecycle paths. Include reconciliation checks with each slice.
3. **Degree evaluation:** bounded available-source verification, current credit/GPA calculations, structural group placement and combined general minimum comparisons are implemented. Broader reconciliation, remaining completion/transfer chronology, grading compatibility and full degree-constraint integration remain open. Extend the supported deterministic evaluator and explanations, then establish reproducible retained results before presenting graduation eligibility.

Operational recovery work from milestone 1 continues throughout these stages. Admissions checklist/office enhancements and broader reports can follow as independent slices without bypassing historical protections. This order advances the highest-priority gaps rather than interpreting the number of completed screens as readiness for release.
