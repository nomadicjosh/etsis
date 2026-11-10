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

This stage retains deliberately reviewed offering definitions. It does **not yet select a section publication for new registrations or academic-credit streams**, change operational section reads to use a publication, or connect publication into offering-plan activation. Existing registration, capacity, teaching authorization, term deadlines, grading and billing behavior continues using its existing source paths. No publication is retrospectively assigned to earlier enrollments or credits.

The next stage must introduce indexed, verified section-version references for new registration/academic facts and integrate all entry points together. It must preserve unknown earlier definitions and exact original receipts, invalidate pending reviews when the selected version changes, and retain early-drop history without resurrecting removed transcript rows. Journal replay must stay out of routine per-student catalog lookups.

The entry-point review distinguishes these boundaries:

| Path | This stage and required follow-through |
| --- | --- |
| Direct section creation and catalog editing | Continue maintaining operational section rows and delivery. Publication reads and reviews their current values; it does not silently publish an edit. |
| Offering-plan draft save and activation | Continue the existing course-version binding/activation transaction. Section publications need explicit integration into that review and receipt in the next stage. |
| Individual/self-service, group registration and hiatus/drop effects | Continue their existing academic, seat and financial streams. New section-version selection must be coordinated across these entry points without changing earlier receipts or billing behavior. |
| Gradebook and teaching access | Continue using the operational teaching assignment for authority. A retained publication reference is historical evidence, not a grant of present-day access. |
| Portal, staff lists, reports and transcripts | Continue their existing authorized sources. This stage adds the private catalog publication/history screen; it does not relabel today's section values as a student's retained offering. |

Further definition work includes grading and eligibility policy versions, normalized classifications, variable credits/CEUs, teaching teams, meetings/rooms, typed fee components and explicit amendments to accepted offering terms. Published instructional dates alone do not change add/drop precedence or institute a new refund policy.

## Verification

Fictional isolated tests cover explicit review/confirmation, confirmation-time attribution, protected reasons/payloads, preserved earlier event bytes, strict date/schema validation, repeated and competing confirmations, expiry, actor/permission checks, permission revocation during a wait, audit rollback, canonical faculty identity, binding/projection verification, incremental/framework replay, frozen history pages and preservation of existing enrollment/credit/financial history.

Real-provider/router checks verify CSRF, private responses, dependency resolution, permissions and exact receipts. Browser checks cover revision, unsaved drafts across history pages, explicit term/date differences, literal rendering, keyboard confirmation, accessibility scans, narrow-screen reflow and absence of browser storage. No working database or DDEV service was used as a fixture.

## Indexed section-version references

Apply `20261109000010_CreateSisSectionVersions` before deploying the reference-cache stage. It creates **`sis_section_versions`**, a reconstructible, normalized cache keyed by publication ULID, with an indexed unique section/stream-position pair. It contains the verified schema-1 definition and its section identity/position, not reasons, names, raw envelopes or a second event payload store. Faculty references deliberately have no foreign key to a replaceable login link. Rollback refuses to discard retained references; maintenance needs a verified rebuild instead.

New publication confirmation saves this cache in the same transaction as the event, receipt and required audit. A cache or audit failure rolls back the entire publication. Reusing a cached version requires an exact definition/section/position match; conflicting contents are rejected, not overwritten. Cached numeric values are validated without silently coercing malformed text.

Earlier actual publication events are left unchanged. The migration does not infer definitions from current section rows or backfill guessed versions. The internal, catalog-authorized `SectionPublicationService::cacheVersion(section, publication)` verifies the exact selected version by bounded protected-stream replay under the section lock, then retains its reference cache. It can prepare earlier recorded versions for a future explicit selection command. Repeating preparation changes no journal or audit facts. Unknown and cross-section publications are rejected. No HTTP endpoint exposes arbitrary cache writes.

Completed publication retries return their original receipts without repairing or creating cache rows. This preserves the behavior of confirmations accepted before the cache existed. Missing old cache entries require explicit verified preparation; conflicting existing entries require investigation. The cache has no independent authority, and its existence does not mean any student selected that version.

Registration/credit version selection, an offering-level selected-version pointer, coordinated stale-review checks and controlled operational rebuild/swap tooling remain subsequent work. No per-student journal replay or automatic latest-version selection is introduced. Isolated checks cover atomic cache writes, earlier-version preparation, immutability, malformed numbers, foreign-section/permission rejection and projection-failure rollback. The 101 SIS table definitions compile through the framework's five schema compilers; runtime checks here use SQLite, not live engine certification.
