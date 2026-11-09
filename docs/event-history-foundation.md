# Single protected event store

The application has one authoritative event table: **`sis_history_events`**. Both the domain-owned `HistoricalEventStore` port and Codefy's `TransactionalEventStore` port use the same protected journal. `sis_history_streams` holds stream heads; `sis_history_operations` holds retry receipts. Neither support table is a second collection of domain events.

This implements the shared persistence slice of the [event-sourcing architecture](event-sourcing-architecture.md). Existing User and financial repositories now use protected storage through their existing framework interface. Course publications, grading policies and institutional credit/grade/drop decisions now also use this journal; accepted enrollment/seat decisions now also have protected streams and committed section order; remaining lifecycle conversions and request/waitlist policy remain. See [institutional credit history](academic-credit-history.md). No current-state rows are turned into invented historical events.

## Installation and existing event conversion

`20261108000002_CreateSisHistoricalEventTables` creates the journal and its two support tables. It then converts actual recorded events from `event_store`, verifies the converted history against its source and drops `event_store` only after verification succeeds. An empty old event table is removed as well. Fresh installations and upgrades finish with the same single event table. The migration is irreversible: `down()` refuses to delete authoritative history or recreate an outdated plaintext store. Restore a verified backup and its matching application version for rollback.

Before applying this migration:

1. Back up the database and the required encryption keys, and verify the recovery procedure.
2. Stop web traffic, workers and every other event writer for the duration of the migration. This is an **offline cutover**, not a live dual-write upgrade.
3. Configure the encryption key or private keyring described below. The standard `bootstrap/phpmig.php` supplies the codec to the migration; custom migration runners must supply it as `sis.history_codec` in the migration object map.
4. Apply the migration through the normal project migration runner. Inspect a failure using its sanitized error and protected source data; do not print payloads, tokens or keys into ordinary logs.
5. Resume application traffic only after the migration succeeds. Normal journal reads and writes refuse to operate while `event_store` still exists.

The converter reads 20 stream identities at a time and 50 source events per page. It checks stream positions, registered event schemas, original event IDs and metadata bindings. Each copied event is encrypted and committed atomically with its stream head and receipt. Conversion can resume after interruption: existing target prefixes are replayed and compared with the original source before any missing suffix is appended. Changed source prefixes, missing events, unknown classes, malformed payloads/metadata, unavailable keys, failed authentication and conflicting target history stop the migration. The source table remains in place on conversion or verification failure; previously verified target prefixes may remain for retry. Keep writers stopped until recovery completes.

The original event ID, aggregate identity, type, playhead, payload and recorded event time are retained. The original transaction ID, original JSON text and SQL timestamp are also retained in an encrypted provenance record. The old adapter interpreted its timezone-less event metadata clock as UTC; conversion preserves that interpretation and the original text, without claiming that the historic writer's timezone was independently verified. Journal insertion time records the conversion time separately. No actor, effective date, approval or earlier policy version is invented when the original record omitted it.

The migration has **not** been applied to a working database during development. Its source-to-target checks verify a conversion, not the authenticity of plaintext records before this protection existed. Protect any backups, database snapshots and retained storage containing the original plaintext under the institution's recovery and retention policy.

## Ports, schemas and provenance

`Domain\Shared\EventSourcing\HistoricalEventStore` is bound to `Infrastructure\EventSourcing\SqlHistoricalEventStore`. `Infrastructure\Persistence\PdoTransactionalEventStore` implements Codefy's framework port by delegating every append and replay to that same domain-owned port; it contains no independent event-table SQL.

`StreamReference` carries the framework AggregateId and logical aggregate type. Pending events use the existing DomainEvent/AggregateChanged interfaces and playheads; restored events feed Codefy's EventStream/aggregate replay. All new row primary keys are ULIDs; original event IDs are preserved separately. Unique stream/type, event ID and stream/position indexes enforce isolated stream identity and event order.

Thirty reviewed event types are admitted at version 1: course-definition publication, explicit section/course-version binding, degree-requirement publication, independent reusable course-group publication, student requirement assignment, student exception approval/revocation, grading-scale changes, institutional credit establishment, final-grade decisions and institutional drops, transfer credit establishment/change, enrollment establishment/closure, student activity observation/decision, seven finance schemas (account opening, posting/reversal, billing-rule definition/retirement and journal posting/reversal) and six User schemas (creation/deletion, name, email, password/token and role changes). The registry pins event classes to event types, aggregate roots and exact payload fields, allowing the oldest User creation shape without its later optional person link. Credential and profile event bodies remain encrypted. No arbitrary class deserialization or permissive upcaster fallback is allowed. The [course publication workflow](course-publication.md) also has an explicitly registered version-2 schema with nullable transcript title and description. The [degree-requirements builder](degree-audit-builder.md) also explicitly registers schema 2 for institutional repeat-credit, repeat-GPA, transfer-GPA and course-waiver policy inputs. The store retains each event’s registered schema version; version 1 is still read unchanged. Degree schema 3 composes exact published group-version references. Earlier inline schemas remain unchanged, and course groups retain schema 1 plus explicitly registered schema 2 for credit/course quantity and per-course point floors. Approval schema 2 adds exact-version course substitutions. Degree schema 4 adds the resolved grading-scale snapshot; its composition stays schema 3. No other event/version combination is implicitly admitted. Further academic schemas require explicit registration and review.

`EventContext` distinguishes three sources:

- **command**: requires the authorized actor, business effective instant/timezone and correlation ID; allows protected reason, causation and approval references.
- **framework**: the compatibility interface has no complete command context. It retains the framework transaction ID and an actor if recorded in the event payload; absent actor/effective/correlation details stay null. Further command/service retrofits must close these attribution gaps.
- **legacy**: retains the complete source row under encryption and the original transaction ID, with explicitly unknown context left null.

Context/event DTOs redact diagnostic and JSON serialization. Neither a context nor a replay cursor grants access. Calling domain workflows must authorize every read and command; no raw history HTTP or general reporting endpoint is supplied. Existing User/account projections are not encrypted by this event-store change.

## Append and retry contract

`append(stream, operation, expectedVersion, context, events)` accepts 1–50 contiguous events starting at `expectedVersion + 1`; a new stream's expected version is zero. Maximum position is 2,000,000,000. Serialized event descriptions are limited to 64 KiB each and 256 KiB per batch. Payloads are validated and frozen inside the unit of work before SQL writes. Recorded event actor fields must agree with the context actor. Even a caught nested validation failure marks the enclosing transaction for rollback.

Writes acquire a current per-stream locking read, verify the protected head and committed tail, and enforce the expected version. No global event-write lock is used. Stream creation, events, authenticated head and operation receipt share the existing unit-of-work transaction and roll back with enclosing financial/projector work. The journal performs no external sends, projections or charges.

A direct operation ID is global; its encrypted receipt is bound to stream, actor and canonical business intent, including context and expected version. An unchanged retry returns the original event IDs/version range, even after later appends. Regenerated technical event IDs/creation times alone do not duplicate the business action. Changed intent or another stream is rejected; another actor cannot recover the receipt.

The framework adapter retains transaction IDs, appends each recorded event through the same journal within one enclosing transaction, and uses event IDs for its internal receipts. It cannot supply the caller's durable business-operation identity through Codefy's existing interface. Higher-level command retries still need their domain operation/receipt policy; a new framework commit is not a replacement for that policy.

## Encryption and key rotation

The installed Defuse authenticated-encryption component protects versioned envelopes with public key identifiers. Separate purposes protect event bodies, heads, receipts and cursors. Event bodies bind indexed IDs, position/type/schema, journal timestamp, predecessor hash, stream identity and context. Changed indexed columns, substituted ciphertext, missing keys and failed authentication cannot fall back to plaintext. Keys never enter the tables.

`config/event_history.php` initially uses the existing application encryption key under **app-v1**. For a separate or rotated keyring, configure:

- `EVENT_HISTORY_ACTIVE_KEY_ID`: the identifier used for new encrypted records.
- `EVENT_HISTORY_KEYS_FILE`: a private JSON file mapping key identifiers to Defuse key strings. Keep it outside `public/` and version control, restrict access and include it in protected recovery procedures. The bounded loader reports failures without file contents or key material.

Retain every key needed for stored events, heads, receipts and cursors. When leaving the default application-key configuration, retain its original key as `app-v1` in the private ring before selecting a new active ID. Never replace key bytes under an existing identifier. Reload long-running workers after configuration changes. Removing an old key makes its records unreadable; there is no fallback. Re-encryption and key retirement require a separate verified maintenance procedure.

## Replay and integrity limits

`page(stream, cursor, limit)` accepts 1–100 events per page. The first page captures an authenticated upper version/hash; subsequent pages retain that boundary and the verified preceding position/hash. Later appends stay outside that replay. Readers verify contiguous positions, authenticated row bindings, schemas and the hash chain through the captured head. Effective time, original event creation time and journal insertion time remain separate. Sequence defines stream order.

The framework compatibility adapter verifies history using bounded SQL pages, including the prefix when loading from a later playhead. Codefy's EventStream still materializes the returned result in memory; callers needing fully bounded replay should use the domain page API. Replay restores events without executing postings or other side effects. Durable projector checkpoints, rebuild coordination and as-of academic projections remain future work.

An append checks the head and tail without rescanning the entire stream. A paged reader checks the records it consumes and completes verification at its captured head. A valid earlier whole-database restore may be undetectable to a fresh reader without an independent trusted checkpoint. Newer cursors detect a head rolled below their captured version, but do not replace protected external anchors, restricted database roles, backups and restore verification. No compliance or privileged-administrator tamper-proof guarantee is claimed. Policy-driven retention/legal holds and external integrity anchors remain outstanding; no runtime purge API is provided.

## Verification and next steps

The schema compiles with the installed MySQL, PostgreSQL, SQLite, SQL Server and Oracle compilers. Fictional SQLite tests exercise populated and empty conversion, all thirteen existing event types, preserved identity/provenance, restart and source-prefix verification, failed conversion without source deletion, shared framework/domain replay, version conflicts, transaction rollback, tampering, cursor boundaries and key rotation. Other engines still need live multi-session locking, DDL recovery and LOB integration tests; compiler coverage is not live-driver certification.

The first [published course-definition slice](course-publication.md) is implemented for currently recorded fields. Next are the remaining immutable course/section definitions and version pinning from the [legacy field review](academic-history-field-review.md), explicit academic schemas and authorized domain commands. Grade/credit, registration and lifecycle conversions follow. Logging a mutable SQL update without making the retained academic history authoritative would not complete those conversions.

The [section binding workflow](course-publication.md#bind-a-section-to-a-publication) uses the same protected journal. Its normalized definition and section/credit reference tables are projections, not additional event tables. Existing academic rows are not retroactively assigned publication versions.

The [degree requirement builder](degree-audit-builder.md) publishes versioned program/academic-year definitions through the same journal. Separate group/composition authoring tables store drafts and publication metadata, not another event history. New degree schema-3 publications retain institutional policy choices and exact group-version references; earlier schemas remain unchanged. Explicit student-program requirement-version assignment now has its own protected decision stream and reconstructible projection. Student evaluation remains a subsequent stage.

The [student requirement assignment workflow](student-degree-requirements.md) registers `StudentRequirementsWereAssigned` schema 1 in this same journal. It retains actor/reason, trusted effective time, student-program scope, exact degree/cohort references, pursuit date and predecessor. The projection is verified against bounded replay; changes preserve original selections. No current-state student rows are converted into invented historical events.

[Student course waivers and minimum exceptions](student-degree-exceptions.md) add two schema-1 event contracts in the same authoritative table. The active query projection discards replaced/revoked rows while preserving their protected events. Approval references retain the original publication; waiver authority covers one pursuit and its course identity, while minimum reductions bind to a specific degree version.

[Grading-scale setup](grading-scale-setup.md) adds global/program policy decisions to this same journal, with a verified current projection. New degree publications retain their resolved scale; original academic grade/credit writers remain scheduled for event-sourcing conversion.

Grade-definition normalization retains all `GradingScaleWasChanged` schema-1 event bytes and receipts. `sis_grades` now contains immutable per-publication definitions with deterministic ULIDs; `sis_grading_scales` holds current metadata. Offline projection conversion verifies actual events and the earlier JSON projection before removing it. No additional event table or fabricated grade-policy event is introduced. [Setup/cutover](grading-scale-setup.md).
