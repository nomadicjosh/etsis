# Roadmap gap review: sections 1–8

Reviewed 2026-11-21 against the current roadmap, domain services, private routes, domain guides and focused test inventory. This is a source-level gap review, not a full test run, independent security audit or institutional release acceptance. Completed adjacent features do not close a broader unfinished item. Sections 9–10 are outside this requested scan.

| Section | Unfinished entries at scan | Assessment |
| --- | ---: | --- |
| 1 | 8 | Partial foundations plus remaining implementation/policy/qualification work |
| 2 | 8 | Partial foundations plus remaining implementation/policy/qualification work |
| 3 | 7 | Partial foundations plus remaining implementation/policy/qualification work |
| 4 | 6 | Partial foundations plus remaining implementation/policy/qualification work |
| 5 | 5 | Partial foundations plus remaining implementation/policy/qualification work |
| 6 | 10 | Partial foundations plus remaining implementation/policy/qualification work |
| 7 | 6 | Partial foundations plus remaining implementation/policy/qualification work |
| 8 | 6 | Partial foundations plus remaining implementation/policy/qualification work |

**Total: 56 unfinished entries.** Most represent remaining scope on working foundations, rather than entirely absent domains.

## Priority and implementation choice

1. Preserve source history and fail closed on unsupported evidence. Rebuild/restore, key independence and institution retention remain explicit gates.
2. Advance concrete core academic gaps rather than add more reporting/support wrappers. The selected bundle is class grade readiness, reviewed retained certification and protected history/staleness after later changes (section 2, with section 1 historical-integrity and section 8 verification obligations).
3. Keep full degree evaluation/retained evaluations, reinstatement, waitlists and institutional financial policies open until their complete contracts are implemented. Existing exact allocation is not a graduation decision.
4. Provider sandbox/native deployment/independent review gates require selected environments and policies. Do not consume real mailboxes, issue live payments, launch heavy multi-engine stacks or declare institutional acceptance from compiler tests.

## Evidence owners

- Sections 1–2: `src/Domain/Person`, `AcademicRecord`, `Registration`, `Student`, `Gradebook`; protected schema registry and selected verifiers; grade/registration/source-correction tests.
- Section 3: `DegreeProgressService`, `DegreeProgressEvaluator`, `CombinedAllocationEvaluator`, `CourseAllocationPlanner`, `RequirementAdjustmentService`; degree allocation/progress/exception tests. `AllocationPreparation` and `AcademicInputSet` are explicitly request-local and must not be dumped into a saved result.
- Section 4: admissions submission/revision/correction/offer/withdrawal/readiness services; document worker/scanner/storage ports; canonical person/account services; portal and publication routes.
- Section 5: `ReportQuery`, `ReportGraph`, `ReportSelectCompiler`, relational source/lifecycle/export services and mapped catalog import services; no unrestricted SQL execution.
- Section 6: finance command/review/billing/journal services; gateway port; Moodle administration/provisioning/section workers and plugin; optional assistance services.
- Section 7: section meeting plans, timetables, booking updates, employment/position/timesheet services, scoped office worklists and the shared support facade.
- Section 8: focused tests/compiler fixtures, DDEV alpha setup, existing guides; real deployment/institution qualification remains distinct.

## Section 1

| Roadmap item at scan | Existing evidence / remaining work |
| --- | --- |
| Line 72: Complete further role-specific profile decisions and operational profile rebuild/retention controls. | Profile histories and bounded verifiers exist. Remaining role facts, isolated rebuild/switch and retention need explicit contracts; support receipts are not checkpoints. Evidence: [person-identity-and-accounts.md](person-identity-and-accounts.md), [event-history-foundation.md](event-history-foundation.md). |
| Line 74: Finish normalized classifications: subjects, course departments/levels and program department/academic-level/award publications have setup, assignment and retained ref… | Schools, fields, campuses and specializations exist; general major/minor and location-eligibility relationships are missing. Evidence: [program-publication.md](program-publication.md), [course-classifications.md](course-classifications.md). |
| Line 87: Complete section publications: instructional dates, retained course/grading/registration policy, delivery classification, teaching assignments and fee references | Instructional dates, delivery, single faculty, fee amount and prospective selection exist. Wider grading/eligibility references, teaching teams and typed fee components remain. Evidence: [section-publication.md](section-publication.md). |
| Line 90: Support versioned fixed/variable-credit rules and student-selected credits; keep CEUs separate from academic credits | Current credit amounts are fixed. No published min/max/increment selection or separate CEU/reporting-term contract exists. Evidence: [academic-history-field-review.md](academic-history-field-review.md). |
| Line 96: Finish trusted actor, reason, effective-date and correlation context for remaining framework command paths and consequential transitions | Many consequential paths have actor/reason/proofs. Further framework/lifecycle paths need field-level attribution review; a broad checkbox is not evidence of a missing foundation. Evidence: [event-sourcing-architecture.md](event-sourcing-architecture.md). |
| Line 99: Provide bounded, read-only integrity/reconciliation tools and independently verified projection checkpoints; rebuild into isolated output, validate it and deliberately… | Selected/global checks exist. No independently verified checkpoint or isolated rebuild/switch workflow is complete. Evidence: [event-history-foundation.md](event-history-foundation.md). |
| Line 104: Implement and exercise key rotation/recovery, independently protected integrity anchors, least-privilege database access and backup/restore procedures. | Existing protected codec is not key rotation, independent anchoring, least-privilege deployment or proven recovery. Evidence: [event-history-foundation.md](event-history-foundation.md). |
| Line 105: Establish institutional retention categories, legal holds, protected artifact/document retention and access/export evidence, including backups | Specific artifact cleanup/holds exist; institution-wide retention categories, legal holds and backup disposition remain. Evidence: [admissions-workbench.md](admissions-workbench.md), [event-history-foundation.md](event-history-foundation.md). |

## Section 2

| Roadmap item at scan | Existing evidence / remaining work |
| --- | --- |
| Line 113: Complete remaining closed-credit lifecycle decisions and reconciliation, including reopening and reinstatement | Institutional source/grade/unit corrections exist. Reopening/closed-credit reinstatement and wider reconciliation are missing. Evidence: [academic-credit-history.md](academic-credit-history.md). |
| Line 141: Extend prerequisite eligibility-policy inputs beyond the supported flat/nested counted course grammar, including explicit course equivalence and broader institutional … | Flat/nested counted prerequisites, co-requisites and reviewed overrides exist. General explicit equivalence and further eligibility inputs remain. Evidence: [course-publication.md](course-publication.md), [registration-history.md](registration-history.md). |
| Line 142: Add waitlists with an explicit institutional ordering/allocation policy, accepted server ordering and atomic seat/billing decisions | No accepted server-ordered waitlist or atomic allocation workflow is implemented. Ordering/override/expiry policies must be explicit. Evidence: [registration-history.md](registration-history.md). |
| Line 143: Add explicit reviewed re-enrollment/reinstatement after closure, preserving original attempt and billing identities | Schedule replacement exists; closed attempt reinstatement with original attempt/billing lineage remains absent. Evidence: [registration-history.md](registration-history.md). |
| Line 160: Extend retained load decisions to explicit census/backdated determinations, historical policy selection, grandfathering and institutional treatment/correction of regis… | Current-term retained determinations exist. Census/backdating, historical selection, grandfathering and registered-quantity corrections remain. Evidence: [enrollment-load.md](enrollment-load.md). |
| Line 163: Add versioned automatic standing proposals and further institutional annotation selection | Manual retained standing, advising relationships and check-ins exist. Automatic versioned standing proposals and appointment/reminder policy remain. Evidence: [academic-standing.md](academic-standing.md), [academic-advising.md](academic-advising.md). |
| Line 183: Complete remaining closed-record corrections and replacements, including reopening, reinstatement and broader historical reconciliation | Per-student correction/approval and reviewed class posting exist. Closed-record reinstatement, additional gradebook policies and institution-wide certification policies remain distinct gaps. Bounded retained class certification was completed after this scan (see outcome below). Evidence: [academic-credit-history.md](academic-credit-history.md). |
| Line 189: Complete institution-approved automated transcript release rules and electronic delivery/signature integrations where selected. | Reviewed protected transcript issuance exists. Institution-approved automatic release and selected external delivery/signature acceptance remain. Evidence: [workflow-review.md](workflow-review.md). |

## Section 3

| Roadmap item at scan | Existing evidence / remaining work |
| --- | --- |
| Line 221: Implement deterministic evaluation against the student's retained degree, group, course and grading publications, using authoritative institutional/transfer credit facts. | Deterministic calculations, exact placement and combined curricula are implemented. Full grammar/equivalence/chronology coverage and retained reproducible evaluations remain. Evidence: [student-degree-progress.md](student-degree-progress.md), [degree-audit-allocation.md](degree-audit-allocation.md). |
| Line 230: Resolve unknown historical course bindings and broader cross-course equivalence beyond the current reviewed substitutions and transfer catalog | Reviewed substitutions and receiving-course corrections exist. Unknown earlier bindings and general equivalence cannot be inferred. Evidence: [student-course-placement.md](student-course-placement.md), [transfer-credit-history.md](transfer-credit-history.md). |
| Line 232: Extend the supported structural placement review to remaining historical equivalence and requirement rules; retain explanations for applied, unused, insufficient-grade… | Exact overlapping-group placement and explanations exist; unsupported historical equivalence/requirement rules still produce review outcomes. Evidence: [degree-audit-allocation.md](degree-audit-allocation.md). |
| Line 236: Complete remaining chronology, grading compatibility and residency rules beyond the current retained repeat-credit/GPA calculations, group quantities/grade floors and … | Retained repeat/GPA and basic institutional residency bounds exist. Additional chronology, compatibility and residency rules remain. Evidence: [degree-audit-calculations.md](degree-audit-calculations.md), [repeat-attempt-ordering.md](repeat-attempt-ordering.md). |
| Line 237: Carry the existing pursuit-scoped waivers, substitutions and minimum reductions through remaining requirement grammar and retained final evaluations | Waivers/substitutions/reductions flow through supported current and combined reviews. Unsupported grammar and retained final evaluations remain. Evidence: [student-degree-exceptions.md](student-degree-exceptions.md). |
| Line 253: Extend the current accessible progress reviews with remaining unmet-requirement explanations and controlled retained evaluation results, including enough input/version… | Current accessible explanations are substantial. Controlled retained results still need complete input/version context and explicit partial-result semantics. Evidence: [student-degree-progress.md](student-degree-progress.md). |
| Line 254: Add reviewed graduation eligibility, authorized award/credential decisions and later corrections | No authorized credential/award decision lifecycle follows an audit result. Graduation remains a separate consequential workflow. Evidence: [student-degree-progress.md](student-degree-progress.md). |

## Section 4

| Roadmap item at scan | Existing evidence / remaining work |
| --- | --- |
| Line 263: Extend public account-registration abuse controls and institution-specific identity matching | Registration throttle and canonical account creation exist. Wider abuse protection and institution-specific identity matching remain; never auto-merge people. Evidence: [person-identity-and-accounts.md](person-identity-and-accounts.md). |
| Line 267: Extend actionable correction requests to remaining applicant/profile/application facts | Contact/address/emergency/education follow-up exists. Further applicant/application corrections remain beyond those defined contracts. Evidence: [admissions-corrections.md](admissions-corrections.md), [person-identity-and-accounts.md](person-identity-and-accounts.md). |
| Line 272: Complete remaining institutional document/reference policies: retention categories, approved formal-evidence disposition, case/person-wide holds, backup/orphan disposi… | Private uploads, scanning interfaces and evidence controls exist. Actual scanner deployment and wider retention/hold/backup policy need operational acceptance. Evidence: [admissions-workbench.md](admissions-workbench.md). |
| Line 279: Add remaining application fact corrections and additional institutional approval stages | Answer revisions/outcome corrections/withdrawal return paths exist. Additional fact/approval stages and post-matriculation rescission need coordinated consequences. Evidence: [admissions-corrections.md](admissions-corrections.md), [admissions-decisions.md](admissions-decisions.md). |
| Line 280: Complete remaining applicant/student status, next-action views and institution-selected service requests, reusing completed backend rules | Many status, next-action and check-in flows exist. Broader institution-selected service requests remain undefined; reuse existing rules. Evidence: [campus-portal.md](campus-portal.md), [academic-advising.md](academic-advising.md). |
| Line 285: Complete Vihzhuo full-page approval/scheduling and remaining theme/block keyboard and accessibility acceptance beyond the implemented news workflow. | News publication workflow exists. Vihzhuo full-page approvals/scheduling and remaining theme/block keyboard acceptance are not equivalent to news approval. Evidence: [public-campus-events.md](public-campus-events.md), [campus-portal.md](campus-portal.md). |

## Section 5

| Roadmap item at scan | Existing evidence / remaining work |
| --- | --- |
| Line 298: Cover the remaining legacy SELECT acceptance cases: further financial aggregates at a declared grain, additional dated sources, configurable joins beyond approved path… | Typed relational builder, aggregates, named copies and date selection cover many cases. Further finance grains/joins/expression trees require explicit acceptance cases. Evidence: [legacy-reporting-review.md](legacy-reporting-review.md). |
| Line 318: Extend authorized datasets for further financial source distinctions, contact/admissions details, authoritative graduation facts, further scheduling analytics and othe… | Many curated datasets exist. Further contact/admissions/finance/scheduling/graduation sources need minimized schemas and independent source duties. Evidence: [legacy-reporting-review.md](legacy-reporting-review.md). |
| Line 322: Extend reviewed population handoffs to further authorized sources and larger background populations where needed | Bounded selected live-report handoffs exist. More authorized sources/larger populations need explicit selection and background rules. Evidence: [registration-history.md](registration-history.md), [communications.md](communications.md). |
| Line 333: Expand mapped imports to person/student/academic/financial data with their own identity/dependency reconciliation and historical/privacy rules | Reference/course/program mapped imports exist. Person/student/academic/financial conversion requires separate identity, dependency and protected-history reconciliation. Evidence: [catalog-imports.md](catalog-imports.md). |
| Line 334: Build and exercise legacy-data conversion/reconciliation, preserving verified recorded history and explicitly identifying unknown earlier facts. | No complete legacy institution conversion/reconciliation is certified; current rows cannot fabricate earlier events. Evidence: [backend-migration.md](backend-migration.md). |

## Section 6

| Roadmap item at scan | Existing evidence / remaining work |
| --- | --- |
| Line 344: Extend pricing with reviewed retrospective assessments, credit-load bands/caps and richer population rules | Reviewed current term/per-credit rules and shortlists exist. Retrospective assessment, bands/caps and richer pricing populations remain. Evidence: [workflow-review.md](workflow-review.md). |
| Line 348: Complete institution-defined refund schedules and installment arrangements | Reasoned linked credits/refunds exist. Institution-defined refund schedules and installments are not implemented policy. Evidence: [workflow-review.md](workflow-review.md). |
| Line 354: Complete automatic student-account-to-GL mappings, controlled period closing and reconciliation against retained postings. | Chart accounts and journal commands exist. Automatic GL mapping, controlled closing and reconciliation remain. Evidence: [workflow-review.md](workflow-review.md). |
| Line 355: Verify gateway checkout, uncertain outcomes, duplicate notifications, refunds and settlement reconciliation through selected provider test environments before any live… | Injected gateway fixtures do not establish selected-provider sandbox/settlement acceptance. No live financial operation should be used as a test. Evidence: [campus-portal.md](campus-portal.md). |
| Line 363: Implement background gradebook intake with explicit source-of-truth policy, reviewed authoritative grade decisions/corrections, immutable applicable definitions and id… | Provisioning/section sync/SSO exist. Background gradebook intake and reviewed authoritative source-of-truth corrections are missing. Evidence: [optional-moodle-integration.md](optional-moodle-integration.md). |
| Line 364: Verify native Moodle PHPUnit tests in a dedicated database and institution-specific upgrade, capabilities, enrollment, SSO/mail, outage and load acceptance | Dedicated Moodle test DB, upgrades/capabilities/outages/load and institution OIDC/logout acceptance are external qualification work. Evidence: [optional-moodle-integration.md](optional-moodle-integration.md). |
| Line 365: Add authorized operational workers/outbox consumers for other external effects where needed; store protected references rather than student payloads in jobs. | Some bounded reference-only workers exist. Other effects require their own authorized idempotent consumers, not generic payload jobs. Evidence: [communications.md](communications.md), [optional-moodle-integration.md](optional-moodle-integration.md). |
| Line 366: Optional integration with Zoom or Google Meet for online/hybrid courses | Published meeting plans/online occurrences exist. Automatic Zoom/Meet creation needs an optional provider, permissions and outage policy. Evidence: [section-publication.md](section-publication.md). |
| Line 367: Optional integration with Twilio and WhatsApp Business API integration: fee reminders, schedule changes, emergency alerts, etc. | Reviewed email exists. Twilio/WhatsApp require optional provider/consent/delivery policies and cannot reuse an email receipt as proof. Evidence: [communications.md](communications.md). |
| Line 375: Verify institution-selected OpenAI/Azure/local deployments and approved no-training/retention terms before enabling a real service. | Optional provider abstraction/role/consent controls exist. Real deployment terms, no-training and retention acceptance remain provider/institution work. Evidence: [optional-authoring-assistance.md](optional-authoring-assistance.md). |

## Section 7

| Roadmap item at scan | Existing evidence / remaining work |
| --- | --- |
| Line 399: Extend published meeting plans with additional resource types and dated team teaching, including conflicts for those assignments | Meeting-plan conflicts and single faculty are implemented. Extra resource types and dated team teaching need retained assignments and conflict inputs. Evidence: [section-publication.md](section-publication.md). |
| Line 411: Add optional automatic email/subscription policy and broader scheduling alerts | Private in-app decisions and deliberate campaigns exist. Automatic subscription/reminder/scheduling policy remains separate. Evidence: [booking-updates.md](booking-updates.md), [communications.md](communications.md). |
| Line 417: Define institution-specific employment approvals, primary-job and timesheet exception rules; settle payroll calculation/export boundaries before implementing them. | Employment dates/positions and timesheets exist. Institution primary-job/exception/approval and payroll/export boundaries need explicit decisions. Evidence: [human-resources.md](human-resources.md). |
| Line 429: Extend scoped delegation to other shared queues where needed, without granting permissions through case assignment | Office-scoped holds/casework exist. Other queues need independent delegation contracts; assignment must not confer permissions. Evidence: [registration-history.md](registration-history.md), [admissions-workbench.md](admissions-workbench.md). |
| Line 432: Extend contextual support diagnostics and operational audit views to remaining workflows | Selected person/academic/account support checks now exist. Broader contextual diagnostics/audit views remain; raw logs are not ordinary staff data. Evidence: [event-history-foundation.md](event-history-foundation.md). |
| Line 433: Review remaining legacy settings, custom-field/extension hooks and API use cases | Typed existing APIs/form fields exist. Broader custom-field/extension contracts remain; never recreate executable legacy SQL/PHP hooks. Evidence: [domain-organization.md](domain-organization.md). |

## Section 8

| Roadmap item at scan | Existing evidence / remaining work |
| --- | --- |
| Line 439: Run live-server migration/query/transaction acceptance for MySQL, PostgreSQL, SQLite, SQL Server and Oracle | Compiler coverage and native SQLite/MariaDB evidence exist. Live PostgreSQL/SQL Server/Oracle and broad transaction qualification are not certified. Evidence: [README](../README.md), [event-history-foundation.md](event-history-foundation.md). |
| Line 440: Exercise competing final-seat requests, large cohorts, long histories, reporting/export limits and failed/retried background work using bounded realistic fixtures. | Focused fictional concurrency/limits/retries exist. Representative competing seats, cohorts, histories and worker/export loads require bounded dedicated fixtures. Evidence: [registration-history.md](registration-history.md), [workflow-review.md](workflow-review.md). |
| Line 441: Complete independent authorization, privacy, upload, CSRF and security review; test keyboard/screen-reader flows, contrast, focus, errors, reflow and reduced motion ag… | Feature-local authorization/CSRF/upload/accessibility checks exist. Independent security and manual keyboard/screen-reader/WCAG acceptance remain release gates. Evidence: [workflow-review.md](workflow-review.md). |
| Line 442: Exercise encrypted artifact and database backup/restore together, key loss/rotation recovery, failed rebuilds and deployment rollback/recovery procedures. | Protected storage is not demonstrated database+artifact+key recovery, failed rebuild or deployment rollback. Isolated operational exercises remain. Evidence: [event-history-foundation.md](event-history-foundation.md). |
| Line 444: Finish upgrade/operations guides, office setup guides, policy configuration, safe monitoring and incident procedures. | Alpha setup and domain guides exist. Complete upgrade/policy/office/monitoring/incident runbooks remain. Evidence: [README](../README.md), [workspace-guides.md](workspace-guides.md). |
| Line 445: Run representative admissions-to-graduation and registration-to-billing-to-refund scenarios with fictional records, then institution-led acceptance of the agreed initi… | Representative flows have isolated coverage. Admissions-to-graduation requires the missing award lifecycle and institution-led acceptance of scope. Evidence: [student-degree-progress.md](student-degree-progress.md), [workflow-review.md](workflow-review.md). |


## Implementation outcome from this review

Completed [class-wide grade readiness, reviewed retained certification and protected history/freshness](academic-credit-history.md#certify-final-grades-for-a-class), connecting enrollment history, authoritative academic credit, teaching, protected authorization and the one event journal. The roadmap records three specific completed workflows. The 56 broader unchecked entries remain open: this slice does not finish closed-record reopening/reinstatement, mass corrections, large-class/long-archive qualification or institution-selected certification policies.

Verification includes fictional domain/replay/concurrency/expiry/authorization/rollback fixtures, a real-provider private HTTP/CSRF check, and one-worker browser scenarios for review/confirm/history, stale-review recovery and blocked pending corrections, including WCAG checks at 320 pixels. Native database/migration evidence and final check totals are recorded in the current conversation handoff. Compiler/source review and passing local fixtures do not replace section 8 deployment acceptance.

## Subsequent connected workload bundle

Completed [institutional time-review policy, documented exception approval and retained time decisions](human-resources.md#institutional-time-review-and-teaching-contact-policy), plus [weekly section contact time and immutable publication calculations](section-publication.md#weekly-teaching-contact-hours). The user selected a fifty-instructional-minute contact-hour fallback and weekly display; institutions can publish their own conversion/rounding. These are separate from clocked elapsed work and payroll. The roadmap adds four specific completed workflows. Broader section-publication/teaching-team scope, employment approval chains, primary-job rules and further institutional/payroll policy remain open. Native migration and focused verification evidence is in the latest handoff.
