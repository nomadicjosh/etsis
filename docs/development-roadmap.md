# Development roadmap

Last reviewed: **2026-11-10**, after reviewed program publication, retained student/report labels and the applied program-definition migration.

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
| Student records | Program assignments, primary-program selections, activity history, requirement-version assignment, waivers, substitutions and explicit minimum exceptions. |
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
- [ ] Link degree-requirement publications to explicitly selected program-definition publications, preserving earlier requirements and assignments. Program catalog details and degree-audit requirements currently retain independent publication facts.
- [ ] Complete section publications: instructional dates, retained course/grading/registration policy, delivery classification, teaching assignments and fee references. Review later changes against affected students.
- [ ] Support versioned fixed/variable-credit rules and student-selected credits; keep CEUs separate from academic credits. Add explicit reporting-term/group relationships where required.
- [ ] Finish trusted actor, reason, effective-date and correlation context for older framework/financial command paths. Course publication now uses confirmation time, expiring reviews and permission rechecks while preserving earlier completed receipts/events. Preserve real existing events and mark unknown earlier facts honestly.
- [ ] Reconcile remaining consequential hiatus/return and policy/status writers into canonical streams without duplicating actions or discarding existing case evidence.
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

- [ ] Implement deterministic evaluation against the student's retained degree, group, course and grading publications, using authoritative institutional/transfer credit facts.
- [ ] Allocate work across overlapping requirements with explicit double-counting rules; explain each applied, unused, insufficient-grade and in-progress course. Do not assume a greedy allocation produces the best valid result.
- [ ] Apply the retained repeat-credit, repeat-GPA and transfer-GPA policies. Evaluate credit/course-count quantities, per-course grade-point floors, residency and GPA minimums without converting grade scales implicitly.
- [ ] Apply pursuit-scoped waivers, substitutions and minimum reductions. A course waiver removes that required course wherever it appears within the pursuit, including outside a group; it creates no earned credit/GPA and does not itself lower minimums.
- [ ] Complete specialization relationships/exclusions and any remaining requirement grammar against the agreed specification. Existing publications keep their original interpretation.
- [ ] Build accessible student/registrar progress views, explainable unmet requirements and retained evaluation results with enough input/version context to reproduce them.
- [ ] Add reviewed graduation eligibility, authorized award/credential decisions and later corrections. An audit result alone must not automatically confer a degree.

**Done when:** the same retained inputs reproduce the same explained result; later catalog changes leave earlier results interpretable; dual-degree pursuits remain separate; an authorized graduation decision is retained independently.

Authoring and exception inputs already exist: [builders](degree-audit-builder.md), [student requirement assignment](student-degree-requirements.md), [exceptions](student-degree-exceptions.md). Student evaluation is still outstanding.

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
- [ ] Add authorized operational workers, a durable outbox and idempotent consumers for external effects where needed. Store protected references rather than student payloads in jobs.

**Done when:** internal balances and external settlements reconcile; retries and replay cannot repeat charges/refunds; pricing and approval changes preserve the policy used for prior transactions. Installed SDKs and fictional gateway tests do not establish operational readiness.

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
3. **Degree evaluation:** begin with a deterministic evaluator for the supported published grammar and authoritative completed credits; add explained allocation/policy/exception behavior before presenting graduation eligibility.

Operational recovery work from milestone 1 continues throughout these stages. Admissions checklist/office enhancements and broader reports can follow as independent slices without bypassing historical protections. This order advances the highest-priority gaps rather than interpreting the number of completed screens as readiness for release.
