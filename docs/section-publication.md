# Published section details

Implemented 2026-11-09. Authorized catalog staff can review and retain a section's instructional dates and current offering facts as an immutable publication. Later publications preserve every earlier version. This is the first section-definition stage in the [development roadmap](development-roadmap.md), extending the existing [course publications and section bindings](course-publication.md).

## Staff workflow

1. Open **Academic catalog → Sections → a section → Published section details**.
2. If necessary, use **Choose course definition** first. Retain an explicitly selected published course version, then choose **Publish section details** from that screen. The publication workflow verifies both the binding decision and its cached course definition against protected history.
3. Enter **Instruction starts on** and **Instruction ends on**, with a reason for publication. The first setup suggests the term dates; staff must review the actual instructional period. Later setup starts from the latest section publication's dates.
4. Choose **Review section publication**. Check the section, instructional dates, term and term dates, capacity, delivery, faculty, section fee and retained course reference. Reviewing writes no publication.
5. Use **Revise section publication** to change dates or reason without losing the draft. **Publish reviewed section details** records the decision and required audit together.
6. Open **View section publications** to inspect retained versions. History is paged, oldest first. Paging preserves unsaved date/reason entries in page memory; browser storage is not used.

The source remains the existing section editor: save changes to capacity, delivery, teaching assignment, code or fee there before reviewing another publication. Actual instructional dates belong to the reviewed publication in this stage; no inferred date backfill or duplicate writable date column is introduced.

Publication takes effect on confirmation. Staff cannot backdate its decision time. The explicit instructional period may describe past or future teaching dates; those dates are not a claim that the publication decision occurred then. Dates outside the selected term are highlighted for explicit review rather than silently clipped or forbidden by an assumed institutional rule. Registration and drop deadlines still follow configured term policy.

## Facts retained

`SectionDefinitionWasPublished` schema 1 retains a new publication ULID, its predecessor reference and the following frozen definition:

| Facts | Historical meaning |
| --- | --- |
| Course and course-publication ULIDs | The exact previously retained course definition; its published titles, credits and prerequisites remain resolvable without copying that whole definition. |
| Term ULID, code and start/end dates | The term context seen for this publication. Later term edits do not replace it. |
| Section code and capacity | Offering facts explicitly reviewed now. Capacity here is a published value, not an assertion about past seat decisions. |
| Faculty person and sign-in account ULIDs, or both null | Canonical teaching identity is retained independently of the current login link. Current names help staff review; names are not copied into every event. Team teaching and dated workload assignments remain later work. |
| Delivery mode | In person, online, hybrid or explicitly unspecified. A physical room is not required. |
| Section fee in integer minor units and currency | The reviewed fee reference value. This publication makes no account or ledger posting. |
| Explicit instructional start/end dates | Actual offering dates confirmed by staff; the end must be on or after the start. |

The definition has strict, versioned fields and types. Course, retained course version and term ownership cannot switch within a section publication stream. Changed offering facts and dates can be published as subsequent versions under the same section identity; earlier definitions remain unchanged.

The trusted actor, protected reason, operation/correlation identity, effective time and recording time use the shared event context. Curated history returns definitions, version references and effective/recording times; it omits actors, reasons and raw envelopes. Historical teaching references remain available even if the current person-to-login link changes.

## Security, transactions and retries

`SectionPublicationService` belongs to Domain; the thin Application controller uses existing persistence ports and provider wiring. Reads, reviews and confirmation require current `sis:catalog:manage`. Registration or faculty authority alone does not grant publication. Commands recheck actor and permission after acquiring section, course and term locks in the existing offering lock order.

Authenticated encrypted review tokens bind purpose, section, staff member, operation, expected publication version, predecessor, definition, reason and a hash of the reviewed source context. An unconfirmed review expires after 20 minutes. Changes to section/term setup, course binding, faculty identity or displayed faculty context require another review. A competing publication invalidates an earlier unconfirmed review, even if visible settings later return to their original values.

An exact completed retry returns the original event receipt after expiry, later section edits, later publications or changes to the sign-in link. It never republishes an old version or restores earlier settings. Current permission and actor checks still apply. Publication and required metadata audit commit together; audit failure rolls back the event, stream head and durable operation receipt.

All events use **`sis_history_events`**, the existing authoritative journal. The original publication stage adds no migration. The indexed-reference stage below requires its projection migration; it adds no event table or current-row conversion. Existing event schemas and bytes remain unchanged. Bounded replay retains only the latest definition in aggregate state; history cursors freeze their initial committed boundary and cannot be reused for another section.

The four browser endpoints are POST requests under `/sis/sections/{id}/publication/`: `setup/`, `review/`, `commit/` and `page/`. They retain framework CSRF protection, private/no-store responses and debug suppression. Date/reason fields have native labels; review and history support keyboard interaction and narrow screens.

## Integration boundary and next stage

Published definitions now support explicit initial offering selection for new registrations, as described below. Existing unselected sections keep their staged behavior; no publication is retrospectively assigned to earlier enrollments or credits. Offering-plan confirmation now explicitly reviews and retains the section versions with activation; see [the connected plan workflow](course-publication.md#publish-offering-plans-with-retained-definitions). Operational section settings, teaching authorization and term deadlines remain separate from historical publication references.

New individual/self-service and group registrations now retain indexed section-version references when an initial offering version was explicitly selected. Reviewed future-registration amendments are now available below. Remaining stages must integrate broader policy definitions while preserving unknown earlier definitions and exact original receipts, invalidating pending reviews when the selected version changes, and retaining early-drop history without resurrecting removed transcript rows. Journal replay must stay out of routine per-student catalog lookups.

The entry-point review distinguishes these boundaries:

| Path | This stage and required follow-through |
| --- | --- |
| Direct section creation and catalog editing | Continue maintaining operational section rows and delivery. Publication reads and reviews their current values; it does not silently publish an edit. |
| Offering-plan draft save and activation | Review course definitions, per-section instructional dates and offering facts together. Confirmation atomically retains course bindings, section publications, initial selections, activation and the receipt; existing matching selections remain intact. |
| Individual/self-service, group registration and hiatus/drop effects | Continue their existing academic, seat and financial streams. Initial section-version selection now reaches new individual/self-service/group registrations and preserves references through drops, without changing earlier receipts or billing policy. |
| Gradebook and teaching access | Continue using the operational teaching assignment for authority. A retained publication reference is historical evidence, not a grant of present-day access. |
| Portal, staff lists, reports and transcripts | Continue their existing authorized sources. This stage adds the private catalog publication/history screen; it does not relabel today's section values as a student's retained offering. |

Further definition work includes grading and eligibility policy versions, normalized classifications, variable credits/CEUs, teaching teams, meetings/rooms, typed fee components and corrections that affect already enrolled students. Published instructional dates alone do not change add/drop precedence or institute a new refund policy.

## Verification

Fictional isolated tests cover explicit review/confirmation, confirmation-time attribution, protected reasons/payloads, preserved earlier event bytes, strict date/schema validation, repeated and competing confirmations, expiry, actor/permission checks, permission revocation during a wait, audit rollback, canonical faculty identity, binding/projection verification, incremental/framework replay, frozen history pages and preservation of existing enrollment/credit/financial history.

Real-provider/router checks verify CSRF, private responses, dependency resolution, permissions and exact receipts. Browser checks cover revision, unsaved drafts across history pages, explicit term/date differences, literal rendering, keyboard confirmation, accessibility scans, narrow-screen reflow and absence of browser storage. No working database or DDEV service was used as a fixture.

## Indexed section-version references

Apply `20261109000010_CreateSisSectionVersions` before deploying the reference-cache stage. It creates **`sis_section_versions`**, a reconstructible, normalized cache keyed by publication ULID, with an indexed unique section/stream-position pair. It contains the verified schema-1 definition and its section identity/position, not reasons, names, raw envelopes or a second event payload store. Faculty references deliberately have no foreign key to a replaceable login link. Rollback refuses to discard retained references; maintenance needs a verified rebuild instead.

New publication confirmation saves this cache in the same transaction as the event, receipt and required audit. A cache or audit failure rolls back the entire publication. Reusing a cached version requires an exact definition/section/position match; conflicting contents are rejected, not overwritten. Cached numeric values are validated without silently coercing malformed text.

Earlier actual publication events are left unchanged. The migration does not infer definitions from current section rows or backfill guessed versions. The internal, catalog-authorized `SectionPublicationService::cacheVersion(section, publication)` verifies the exact selected version by bounded protected-stream replay under the section lock, then retains its reference cache. It can prepare earlier recorded versions for a future explicit selection command. Repeating preparation changes no journal or audit facts. Unknown and cross-section publications are rejected. No HTTP endpoint exposes arbitrary cache writes.

Completed publication retries return their original receipts without repairing or creating cache rows. This preserves the behavior of confirmations accepted before the cache existed. Missing old cache entries require explicit verified preparation; conflicting existing entries require investigation. The cache has no independent authority, and its existence does not mean any student selected that version.

Initial registration/credit version selection, its offering-level pointer and batch-review invalidation are now implemented below. Reviewed future-registration amendments are implemented below; offering-plan integration is now described below, while controlled operational rebuild/swap tooling remains subsequent work. No per-student journal replay or automatic latest-version selection is introduced. Isolated checks cover atomic cache writes, earlier-version preparation, immutability, malformed numbers, foreign-section/permission rejection and projection-failure rollback. The 101 SIS table definitions compile through the framework's five schema compilers; runtime checks here use SQLite, not live engine certification.

## Select an initial offering version for new registrations

Implemented 2026-11-10. Apply **`20261110000001_CreateSisSectionOfferingSelections`** after the section-version migration. Open **Sections → a section → Published section details**, expand the intended retained version and choose **Use this version for new registrations**. Enter a reason, review the exact offering and dates, then **Confirm offering selection**. Revising preserves the reason and returning to the publications preserves the unsaved publication draft.

Selection requires current `sis:catalog:manage`, an empty section and no earlier offering selection. Any current enrollment record, including a closed late drop, prevents initial selection. A registration completed after review also prevents confirmation. Earlier removed early drops are not assigned a guessed definition; an explicitly selected version applies only to future registrations. Selecting is separate from publishing, activating, reserving a room or charging an account.

The selected version must match the current section/course binding and reviewed term/offering facts. Merely publishing a newer version does not switch the selection. Initial selection records the first decision; changing it requires the separate reviewed amendment workflow below. If code, capacity, faculty account, delivery or fee changes after selection, new registrations stop for registrar review rather than claiming that the changed offering matches the retained definition. Existing enrollment retries, drops, earlier academic facts and financial postings remain intact. Arrange these offering details before initial selection. After selection, publish and explicitly accept the changed offering for future registrations using the amendment workflow below. Operational activity and self-registration controls remain current eligibility checks.

### History and read models

**`SectionOfferingVersionWasSelected`** records the publication ULID and a canonical definition fingerprint in the existing authoritative journal, with trusted actor, protected reason, operation, and actual confirmation/recording times. Its stream is keyed by section identity and accepts one initial decision. Review tokens bind purpose, section, staff member, operation, source context and definition, and expire after 20 minutes. Commands recheck permission after the existing section/course/term lock order. Competing decisions cannot replace the accepted selection. Exact retries return the original receipt after enrollment, later publications or expiry, without new events, grants or postings.

The migration creates two reconstructible reference tables, with no event payloads or backfill:

- **`sis_section_offering_versions`** maps the section to its selected, verified publication.
- **`sis_enrollment_section_versions`** maps each newly accepted enrollment to that publication. Its foreign key uses retained registration routing rather than the removable current enrollment row, so restricted early-drop evidence survives. This table requires the same restricted access and retention treatment as registration history.

Catalog setup and section reads use indexed projections. Before accepting a new registration, the command verifies the protected latest selection decision against the selected reference, selection position and cached definition fingerprint. This is bounded independently of publication history: it does not replay the course or section publication stream for each student. Missing or conflicting selected references stop acceptance. The separate initial selection command verifies the actual selected publication history before creating its projection.

For a selected offering, new **`EnrollmentWasEstablished`** and **`InstitutionalCreditWasEstablished`** events carry the same optional `section_version_id` metadata reference. Their existing payload schema contracts remain unchanged; earlier events retain absent/unknown references. Enrollment and academic aggregates preserve the reference during replay, grade changes and drops; current projections are checked against the retained enrollment reference. No earlier application, enrollment, credit or issued transcript is relabeled.

Enrollment, retained reference, academic credit, seat ordering, applicable charge, receipts and required audit commit together. A reference-write or audit failure rolls everything back. Early drop removes current academic/enrollment projections and reverses the original course charge while retaining the protected reference. A new registration after an early drop receives a distinct enrollment identity and its own reference; late-drop billing policy is unchanged. An initial selection made after a group-registration review invalidates that review, even when visible prices or capacities match.

The two new POST endpoints are `/sis/sections/{id}/publication/selection-review/` and `select/`; they retain CSRF, private/no-store responses and debug suppression. Publication pages show the selected version without replaying its stream. History and initial selection remain explicit staff actions. Fictional checks cover both event references, replay, source changes, stale/expired/competing reviews, permissions, retries after enrollment, rollback, early-drop/retake preservation, corrupt/missing references and group-review invalidation. Real-provider checks exercise CSRF and authorization; browser checks exercise revision, keyboard confirmation, reflow and accessibility. The 103 SIS table definitions compile through the framework's five schema compilers; runtime fixtures use SQLite.


## Amend the offering for future registrations

Implemented 2026-11-10. Apply **`20261110000002_AddSisOfferingSelectionPosition`** after the initial-selection migration. It adds `stream_version` to the existing offering pointer, defaulting earlier pointers to position 1 because the preceding contract permitted exactly one initial selection. It creates no event table, rewrites no recorded event and assigns no inferred publication to a student. The alteration compiles for the framework's five supported databases; runtime upgrade checks use SQLite.

1. Save changed code, capacity, delivery, faculty or fee in the existing section editor, then publish those reviewed section details. For instructional-date changes alone, publish the new dates directly.
2. Open **Published section details**, expand the intended publication and choose **Use this version for future registrations**. The section must already have an initial selection; current enrollment does not prevent an amendment.
3. Enter a reason and choose **Review future offering amendment**. Compare the previously selected offering and proposed offering, including dates, fee and current enrollment count.
4. Choose **Revise future offering amendment** if needed, or **Confirm future offering amendment** to accept the change. New registrations use that version. **Offering selection history** shows the initial decision and amendments in private, bounded pages.

The selected publication must belong to the same section, course, retained course version and term, and match current operational offering facts. Publishing alone still does not change the selection. Returning to an earlier publication is an explicit new amendment and requires matching current setup. Selecting the already selected version is rejected as no change.

Existing enrollment and academic-credit references, grades and charges keep their original facts. This workflow does not reprice, refund, cancel or relabel an existing student's attempt. A reduced capacity does not remove enrolled students; normal capacity checks govern subsequent registrations. Corrections affecting existing students, course-version rebinding remain separate work. Offering-plan activation now uses this initial-selection foundation without implicitly amending existing selections. Historical faculty references do not grant current teaching access.

### Confirmation safeguards

Review and confirmation require current `sis:catalog:manage`, including a permission recheck after acquiring section, course and term locks. The encrypted review binds the reviewer, section, operation, previous/proposed publication fingerprints, expected selection position, reason, source context, enrollment count and committed seat-decision sequence. An unconfirmed review expires after 20 minutes. A registration or drop requires another review even if the headcount later returns to the same number. Changes to operational setup or an intervening selection also prevent stale confirmation. Group-registration reviews retain the selection position as well as the publication identity, so changing away and explicitly returning still requires a fresh group review.

**`SectionOfferingVersionWasAmended`** schema 1 retains the previous and proposed publication ULIDs plus the proposed definition fingerprint in the existing selection stream and authoritative journal. The trusted context retains actor, protected reason and actual confirmation time. The append, verified definition cache, compare-and-set selection pointer, operation receipt and required audit commit together. A failure rolls back all of them. Exact accepted retries return their original receipt after later amendments, enrollments or expiry; they do not change today's selection.

Amendment commands replay the selection chain in bounded pages, verify predecessor continuity and compare the resulting state with its projection. Registration's current-selection check reads the authenticated committed head and its self-contained event through indexed lookups. This verifies the current selection; it is not an inspection of every earlier event. Full replay and integrity/reconciliation checks remain necessary for historical verification and recovery. Course and section publication streams are not replayed for each student registration.

The three additional POST endpoints under `/sis/sections/{id}/publication/` are `amendment-review/`, `amend/` and `selection-page/`. They retain framework CSRF, private/no-store responses and debug suppression. Curated selection history omits actors, reasons and raw envelopes. Ordinary catalog setup still uses read models without journal replay or write locks.

Fictional checks cover old/new student references and separate charges, preserved event bytes, confirmation time, Unicode reasons, framework/incremental replay, expiry, actor/permission rechecks, audit rollback, conflicting setup, selection-position drift, registration/drop races, stale reviews after a return to an earlier selection, exact retries and frozen history pages. Actual provider/router checks exercise all three endpoints and their CSRF/privacy/permission guards. Browser checks cover before/after review, revision, keyboard confirmation, history, narrow-screen reflow, accessibility scans and no browser storage. No working database migrations or DDEV services were run.


## Offering-plan activation

The saved-plan **Review publication** workflow now includes per-section instructional dates and offering definitions. On confirmation, unselected empty drafts receive their reviewed section publication and initial selection before activation, within the same transaction. Matching independent selections are reused; a newer publication is never silently substituted. Drafts with student records require separate review and cannot receive a retrospectively inferred version. See [plan setup, safeguards and compatibility](course-publication.md#publish-offering-plans-with-retained-definitions).

The plan receipt retains exact selected section publication references. New registrations consume those references through the existing enrollment/academic-credit paths; activation itself reserves no seats, creates no credit awards and posts no charges. New publication/selection events name the plan as their causation in protected context. Earlier completed plan receipts remain readable and retryable without adding missing historical references. No new migration or event schema is introduced in this integration stage.
