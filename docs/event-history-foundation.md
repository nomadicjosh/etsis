# Single protected event store

The application has one authoritative event table: **`sis_history_events`**. Both the domain-owned `HistoricalEventStore` port and Codefy's `TransactionalEventStore` port use the same protected journal. `sis_history_streams` holds stream heads; `sis_history_operations` holds retry receipts. Neither support table is a second collection of domain events.

A stream head contains its aggregate identity, latest event position and integrity reference. It is not a snapshot of the aggregate's full business state. Current-state projections hold fields such as a user's latest email; the journal retains the changes used to reconstruct that state.

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

Initial migration qualification used isolated fixtures; consult migration status and the current handoff for the configured installation's applied state. Source-to-target checks verify a conversion, not the authenticity of plaintext records before this protection existed. Protect any backups, database snapshots and retained storage containing the original plaintext under the institution's recovery and retention policy.

## Ports, schemas and provenance

`Domain\Shared\EventSourcing\HistoricalEventStore` is bound to `Infrastructure\EventSourcing\SqlHistoricalEventStore`. `Infrastructure\Persistence\PdoTransactionalEventStore` implements Codefy's framework port by delegating every append and replay to that same domain-owned port; it contains no independent event-table SQL.

`StreamReference` carries the framework AggregateId and logical aggregate type. Pending events use the existing DomainEvent/AggregateChanged interfaces and playheads; restored events feed Codefy's EventStream/aggregate replay. All new row primary keys are ULIDs; original event IDs are preserved separately. Unique stream/type, event ID and stream/position indexes enforce isolated stream identity and event order.

Thirty-five reviewed event types are admitted at version 1: course-definition publication, explicit section/course-version binding, degree-requirement publication, independent reusable course-group publication, student requirement assignment, student exception approval/revocation, grading-scale changes, institutional credit establishment, final-grade decisions and institutional drops, transfer credit establishment/change, enrollment establishment/closure, student activity observation/decision, student program assignment/observation/status changes, primary-program observation/selection, seven finance schemas (account opening, posting/reversal, billing-rule definition/retirement and journal posting/reversal) and six User schemas (creation/deletion, name, email, password/token and role changes). The registry pins event classes to event types, aggregate roots and exact payload fields, allowing the oldest User creation shape without its later optional person link. Credential and profile event bodies remain encrypted. No arbitrary class deserialization or permissive upcaster fallback is allowed. The [course publication workflow](course-publication.md) also has an explicitly registered version-2 schema with nullable transcript title and description. The [degree-requirements builder](degree-audit-builder.md) also explicitly registers schema 2 for institutional repeat-credit, repeat-GPA, transfer-GPA and course-waiver policy inputs. The store retains each event’s registered schema version; version 1 is still read unchanged. Degree schema 3 composes exact published group-version references. Earlier inline schemas remain unchanged, and course groups retain schema 1 plus explicitly registered schema 2 for credit/course quantity and per-course point floors. Approval schema 2 adds exact-version course substitutions. Degree schema 4 adds the resolved grading-scale snapshot; its composition stays schema 3. No other event/version combination is implicitly admitted. Further academic schemas require explicit registration and review.

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

The [independent checkpoint and recovery workflow](history-checkpoint-recovery.md) now supplies new-only private checkpoint key/artifact files and bounded restored-journal comparison. Checkpoint keys are separate from application/event keys. Mixed-key reads, old cursors/receipts, preserved event bytes and recovery after missing/replaced keys are qualified with fictional fixtures; no real key was rotated during that check.

## Replay and integrity limits

`page(stream, cursor, limit)` accepts 1–100 events per page. The first page captures an authenticated upper version/hash; subsequent pages retain that boundary and the verified preceding position/hash. Later appends stay outside that replay. Readers verify contiguous positions, authenticated row bindings, schemas and the hash chain through the captured head. Effective time, original event creation time and journal insertion time remain separate. Sequence defines stream order.

The framework compatibility adapter verifies history using bounded SQL pages, including the prefix when loading from a later playhead. Codefy's EventStream still materializes the returned result in memory; callers needing fully bounded replay should use the domain page API. Replay restores events without executing postings or other side effects. Durable projector checkpoints, rebuild coordination and as-of academic projections remain future work.

An append checks the head and tail without rescanning the entire stream. A paged reader checks the records it consumes and completes verification at its captured head. A valid earlier whole-database restore may be undetectable to a fresh reader without an independent trusted checkpoint. Newer cursors detect a head rolled below their captured version. The [checkpoint command](history-checkpoint-recovery.md) adds a complete protected-inventory comparison against a retained independent artifact; operators must actually protect its custody separately. Restricted database roles, whole backup/artifact/projection restore, external custody and independent qualification remain necessary. No compliance or privileged-administrator tamper-proof guarantee is claimed. Remaining retention/legal-hold policies and key retirement stay open; no runtime purge API is provided.

## Read-only operator verification

### Selected institutional and transfer academic checks

Use one **academic credit ULID** for either institutional work or a transfer award:

```sh
ddev codex sis:history:verify --credit='<sis_academic_credits.id>' --events=500 --json
```

For retained early drops, use the original credit reference retained in academic stream routing; current enrollment and credit rows may correctly be absent. For transfers, use the credit reference, **not** the transfer award, equivalency, course, student or institutional number. A missing/unroutable credit is unverified, never a verified empty history. Operators need trusted deployment access to the database and history keys.

This exclusive selector rejects other subjects, global `--streams` and continuation cursors before resolving storage. Its default 500-event allowance is configurable from 1–2,000 events, with the existing shared 4 MiB/five-second cooperative replay budget. Indexed credit/source identities resolve one source without the whole-student inventory's 200-source ceiling. Queries and replay are bounded; a single source that exceeds the allowance remains incomplete. Database query deadlines remain a deployment concern.

The check reuses `AcademicInputVerifier` and the ordinary institutional-credit, registration and transfer readers. Institutional work compares protected credit/registration facts, retained source links, accepted offering/course and posting-term references, current projections and pending grade decisions. A transfer compares its protected award, credit and source projections through the existing transfer reader. Reasoned corrections and voids remain recorded facts. Retained early drops can verify with one source checked and zero current credit rows; a voided transfer still has a current row. An earlier untracked current observation reports **history_gaps** without creating events.

Reads run without a transaction or write locks. Before/after comparisons include selected routing, current credit/source rows, student/person routing, relevant reference/pending projections and journal heads. Detected changes return **changed**. Corruption or missing references return **unverified**; allowance exhaustion returns **limited**. These outcomes disclose no partial source/current count. Verified results exit 0, gaps/changes/limits exit 3, missing/unverified results exit 1 and invalid options exit 2. Output contains only scope/reference, status, event/source/current counts, earlier-history uncertainty, fixed issue codes and `records_changed: false`; it contains no grades, quantities, source IDs, reasons or payloads.

Staff can choose **Check selected academic history** from institutional **Grade history**, or a transfer award's **More** menu. All three duties are required: `sis:history:verify`, `sis:audit:read`, `sis:student:read`. Canonical-person and super-owner safeguards apply to both running checks and reading earlier receipts. Browser requests use the existing private/no-store, CSRF-protected support routes and fixed 200-event allowance. Verification itself is read-only; only the existing minimized support receipt and required audit are saved atomically afterward, with current authority/routing rechecked. No permission grants or schema changes are required.

**Back to record** returns to the captured student/gradebook context or selected transfer award through its existing reader. Checking is explicit, without polling; denied access clears earlier results and detached responses cannot replace the returned record. Nothing is stored in browser storage.

This is a selected source observation, not a projection repair, academic approval, full journal/receipt inventory, independent restore checkpoint, pursuit-attribution/equivalence review, degree audit or financial reconciliation. Sources whose credit and all routing facts disappeared together cannot be discovered by this selector. Broader historical reconciliation, reconstruction and archive/restore acceptance remain separate work. Ordinary reports continue to use authorized read models.

### Selected advisor and degree-selection checks

Trusted operators can compare one pursuit's advisor assignments or selected degree baseline with protected history:

```sh
ddev codex sis:history:verify --advisors=PURSUIT_ULID --events=500 --json
ddev codex sis:history:verify --requirements=PURSUIT_ULID --events=500 --json
```

These are mutually exclusive selected modes, including with other subject selectors. Use `--events=1..2000`; global `--streams` and continuation cursors do not apply. Both commands are read-only, resolve dependencies lazily and return the existing minimized selected-decision result. Neither invokes projectors, repairs a row, grants advisor access, assigns a baseline or evaluates graduation eligibility. A selected mismatch exits 1; empty/changed/limited observations exit 3, while verified results exit 0.

Advisor checks replay the exact pursuit history and compare its dated assignments with the current projection, including student routing. A previously recorded assignment list that was deliberately cleared can verify with zero current periods. An absent stream and empty projection produce **No recorded advisor assignment observed**; they do not establish earlier advising. Current staff/faculty eligibility and closed-pursuit status do not rewrite retained assignments, and are not certified by this check.

Degree-selection checks compare the exact retained selection with its projection and student/program routing, then resolve the selected degree, reusable-group and course publications, plus the retained program publication where present, under one shared allowance. They retain the selection's academic-year/program-publication context rather than substituting current catalog choices. Missing or corrupt referenced facts fail verification. An absent selection remains unrecorded, and a limited read returns no partial record count or requirements. This is not validation of every mutable publication cache, specialization/exception history, academic award, grading calculation or final evaluation.

Both verifiers share a bounded event/4 MiB/five-second replay allowance, reject enclosing snapshot transactions, and compare pursuit/person routing, selected projections and stream heads before/after the read. Changing sources are reported as **changed**, separately from a stable mismatch. Existing workflow readers use the same extracted replay/projection comparisons; no alternate model, event stream, historical backfill or silent repair is introduced.

Staff can run the same selected checks from **Academic advisors → Check advisor assignment history** and **Student degree requirements → Check degree selection history**. Each requires independent `sis:history:verify`, `sis:audit:read`, `sis:student:read` and its source duty (`sis:advising:manage` or `sis:degree-audit:assign`). Super-owner safeguards remain in force. Owners and dated advisor access alone receive no support authority. Browser checks use the fixed 200-event allowance and existing private, CSRF-protected transport; only a minimized restricted support receipt and required access audit are saved. The business journal and student facts remain unchanged.

**Back to record** restores the originating record. Advisor rows/reasons held in memory survive this detour; degree requirements close their modal before opening the shared support drawer and restore it on return. Closed/denied responses cannot restore private results, and no draft/proof/result enters browser storage. Previous receipts describe their observed point, not a continuing guarantee or an independent projection checkpoint.

### Selected recorded-progress checks

A trusted operator can verify one accepted degree-progress observation:

```sh
ddev codex sis:history:verify --progress-record=RECORD_ULID --events=500 --json
```

Use the saved review's reference, not the student or pursuit ID. This mode accepts 1–2,000 replay events, shares the existing 4 MiB/five-second cooperative replay allowance, and rejects other selected targets, `--streams` and continuation cursors. The accepted observation contains exactly one protected event; a valid selected check normally reads one event and compares one index row. Exit 0 means verified, 3 means changed/limited, 1 means missing/unverified/unavailable, and 2 means invalid input. A missing record is not a verified empty historical review.

`RecordedProgressReader` shares the ordinary workflow's authenticated replay and deterministic projection comparison. The check validates the protected accepted snapshot and its original actor/reason/date/intent context, then compares student/pursuit routing, recording dates and the original source fingerprint. Current student activity, a closed pursuit, today's catalog and later academic corrections do not rewrite or recalculate that observation. Before/after observations include the selected projection, pursuit/student/person routing and protected stream head. Changed boundaries are distinct from stable corruption; an enclosing snapshot transaction is refused. Failed, limited or changed checks expose no partial compared-record count.

Output is limited to the saved review reference, scope, status, observed position, event/record counts and fixed issue codes. No source fingerprint, course evidence, requirements, student identity values, protected reasons or raw snapshot is printed. The console path is read-only: no write locks, access audit, repair, replay side effect or event conversion. The check does not independently re-resolve every referenced publication, rerun the allocation engine, prove archive completeness, qualify a future engine, or certify graduation/restore readiness. Controlled reproduction and current progress remain separate operations.

Staff open **Students → Degree requirements → Recorded progress reviews → Open review → Check recorded progress history**. The shared support drawer requires **all four** duties: `sis:history:verify`, `sis:audit:read`, `sis:student:read` and `sis:degree-audit:record`, with current canonical-person ownership and super-owner protections. It uses the fixed 200-event browser allowance regardless of submitted override fields. Verification runs outside the separate transaction that saves a minimized existing support receipt and required audit. Routing and authority are rechecked through that transaction; failures roll back the receipt and audit. Business observations and the authoritative journal remain unchanged.

The originating requirements modal closes before the support drawer opens. **Back to record** returns directly to the selected saved review, rereads it under current access and focuses its summary; it does not run a fresh progress calculation. Late/denied responses cannot restore a prior result, and lost source-read access leaves no stale saved summary. Owners, advisors and student-read permission alone do not acquire support authority. No proof, snapshot or check result enters browser storage. No migration, new permission/grant, report or separate event store is needed.

### Selected waitlist request checks

Trusted operators can check one retained request with the existing command:

```sh
ddev codex sis:history:verify --waitlist-entry=REQUEST_ULID --events=500 --json
```

Use the retained request's ULID, not a student, enrollment or section ID. This selector is exclusive: do not combine it with another selected subject, `--streams` or `--cursor`. The default allowance is 500 events; `--events` accepts 1–2,000. Invalid input is rejected before resolving storage. The command remains a `ConsoleCommand` with plain untranslated console strings. Exit 0 means verified; 3 means changed or limited; 1 means missing/unverified/unavailable; 2 means invalid input.

`WaitlistProjectionVerifier` checks an active, cancelled, removed or registered request through the ordinary `WaitlistLedger`. The ledger authenticates the section's queue history, accepted policy/FIFO/bypass decisions and active projection. The selected discovery row, including a closed outcome, must exactly match its retained entry; current canonical student/person routing and section existence must agree. Before/after selected rows, current queue state, active places, routing and protected head are compared outside a snapshot transaction. An enclosing transaction or parallel legacy event table cannot produce a verified result. There is one replay allowance of the requested event count, four MiB and five cooperative seconds. Exact multi-page allowances work; excessive histories fail without partial success. These checks write no events, business facts, audit rows, receipts or notifications.

Output contains only status, the selected request reference, event count, observed section-stream position, one compared record on success and fixed issue codes. It contains no student/person identity, queue rank, outcome, private reason, enrollment reference or event payload. Missing/damaged evidence is unverified; concurrent boundary changes are changed; an exhausted allowance is limited. No request history is inferred from current registration, and a registered request can remain valid after an early drop removes its current enrollment. Registration/academic/financial integrity, disposable notices, every retry receipt, archive completeness and restore readiness have separate checks and acceptance requirements.

Staff open **Academic catalog → Sections → a section → Section waitlist → Check waitlist request history** beside a request. All five duties are required: `sis:history:verify`, `sis:audit:read`, `sis:student:read`, `sis:registration:waitlist` and `sis:registration:manage`. Current canonical-person and super-owner safeguards apply to checks and previous receipts. Browser checks use the fixed 200-event allowance and existing private/no-store CSRF-protected routes. Only a minimal existing support receipt and required audit are saved atomically after verification; final current actor, person/student and selected section/request routing are rechecked. This does not grant access or allocate seats.

**Back to record** rereads the originating queue page with its existing Previous/Next navigation. Access loss clears results; detached responses cannot reopen an old check. No request, proof or result enters browser storage. Closed requests remain available to the operator selector; the ordinary section queue still lists active requests only. This slice adds no schema, permission, report or separate event store. Populated fictional SQLite checks, actual-provider console/HTTP qualification and browser accessibility cover this workflow; populated native-server, long-lived queue, independent security and institutional retention/restore acceptance remain separate.

### Selected course and section publication checks

Trusted deployment operators can select **one** read-only mode:

```sh
/usr/local/bin/php84 codex sis:history:verify --course=COURSE_ULID --events=500 --json
/usr/local/bin/php84 codex sis:history:verify --section=SECTION_ULID --events=500 --json
```

Use the course/section identity, **not a publication-version identity**. These modes are exclusive with every other selected mode, global stream limits and continuation cursors. The event allowance is 1–2,000; browser checks fix it at 200. Invalid selectors/limits fail before storage/provider resolution. The command remains a normal `ConsoleCommand` with untranslated console strings.

Course checks replay the protected publication chain through existing aggregate/definition validators and compare all available cached versions, including retained subject/classification and prerequisite/credit/CEU facts. A publication need not have a cache row until another workflow retains it. This does not inventory every dependent section or academic record.

Section checks share one allowance across course publications, the original course-binding decision, section publications and offering-selection/amendment history. They compare retained references, source fingerprints and current section/course/term routing. A later course draft/publication does not replace the bound course, and later section publications do not silently replace the selected offering. Unselected offerings can verify; unpublished records remain an explicit empty observation with earlier history unknown.

Reads run outside an enclosing transaction/snapshot, acquire no write locks and invoke no projectors. Before/after comparisons cover routing, raw reference rows/retained labels and protected heads. One request shares a four-MiB protected replay budget and five-second cooperative limit. Reference inventories separately cap each relation at 200 rows and the captured inventory at four MiB. Larger inventories need offline review even with a larger event allowance. Driver/operator timeouts remain necessary for blocked SQL.

Results contain only the selected reference, fixed scope/status/issue codes, events checked and a publication count on success. Missing/damaged/unavailable evidence is unverified; changed boundaries and exhausted limits are incomplete observations without a partial publication count. Exit codes are 0 verified, 3 empty/changed/limited, 1 unverified/unavailable and 2 invalid input. Reasons, descriptions, fees, teaching identities, raw envelopes and rosters are omitted.

This does **not** validate today's draft, certify eligibility or every dependent academic record, verify finance/external synchronization, repair data, publish definitions, switch offerings or establish archive/restore completeness. Independent checkpoint and recovery acceptance stays separate.

Staff launch the existing support drawer from [course](course-publication.md#check-retained-publication-history) or [section](section-publication.md#check-retained-publication-history) publications. Every request requires `sis:catalog:manage`, `sis:history:verify` and `sis:audit:read` together. Only a minimal existing support receipt and required audit are written after observation, with final actor/permission and section/course/term routing checks. Receipts describe their recorded observation, not a continuing guarantee.

Catalog receipts intentionally have null person/owner/student routing. Personal checks still require their canonical person. The portable migration makes only the existing receipt person reference nullable, preserving rows/indexes and refusing destructive rollback. SQLite reuses the bounded nullable-ULID conversion, preserving original schema objects/values; room-reference migrations use the same current helper. No new receipt table, permission/grant, event type, report, journal or worker is added.

### Selected program and curriculum publication checks

Trusted operators can select one catalog record with the existing read-only command:

```sh
/usr/local/bin/php84 codex sis:history:verify --program=PROGRAM_ULID --events=500 --json
/usr/local/bin/php84 codex sis:history:verify --course-group=GROUP_ULID --events=500 --json
/usr/local/bin/php84 codex sis:history:verify --curriculum=REQUIREMENTS_DRAFT_ULID --events=500 --json
```

Supply the program, reusable group or degree/specialization requirements **draft identity**, rather than a publication-version identity. `--curriculum` checks a published requirements definition; the existing `--requirements` selector checks a student's assigned requirements under a pursuit. All selectors are mutually exclusive and reject cursors or an explicit `--streams` option before resolving storage.

Program checks replay genuine publications in sequence and compare each retained cohort definition, classification/ownership/location snapshot and offered-specialization context with the ordinary protected reader's projection. The latest publication for each academic year must match that year's current catalog pointer. An earlier year can remain current within its own year. Missing, damaged or orphan claimed publications require review; earlier published catalog rows without supporting events are never adopted as verified history.

Reusable-group checks verify the publication chain and the current version, retained name and position. Degree and specialization requirements checks verify their publication chain, program/year/component routing and current version/position. They distinguish degree and component draft identities and reject ambiguous or changed routing. Current working names, JSON drafts and reference-library label changes do not reinterpret retained publications.

These checks cover each selected stream and **its own projections**. They do not recursively certify referenced course/group/grading streams, evaluate a student, confer a degree, validate today's draft, establish archive completeness or repair records. Separate selected-source checks and degree-progress review remain necessary for those purposes. No publication, academic record or journal event is written.

The existing bounds apply: 1–2,000 replay events, four MiB of protected replay and five cooperative seconds; at most 200 rows in each program projection inventory and four MiB in the captured boundary. Browser checks use 200 events. Verification refuses an enclosing transaction, compares source heads and relevant projections before/after replay, and returns no successful publication count on damage, drift or a limit. SQL/operator deadlines are still required. Exit codes remain 0 verified, 3 empty/changed/limited, 1 unverified/unavailable and 2 invalid input. Outputs contain references, fixed statuses/issues and counts, with no definitions or private reasons.

Staff open **Check program publication history** under [published program details](program-publication.md#check-retained-publication-history), or **Check course group publication history / Check requirements publication history** under [published requirement versions](degree-audit-builder.md#check-retained-publication-history). Catalog, history-verification and audit duties are all required on every request. The shared support drawer retains its minimized receipt/audit workflow; final permission/routing or required-audit failure rolls back the receipt. Publication records and authorization context are locked for that final receipt transaction, while verification remains outside it. **Back to record** restores the publication page and focus; a requirements dialog closes before opening the support drawer.

No schema, permission/grant, report, event type, parallel journal, repair queue or worker is added. PostgreSQL qualification for this bundle covers server-enforced read-only checks of existing draft-only records, plus the unchanged global protected-history check. Populated publication/damage/receipt qualification uses fictional SQLite records; native populated replay and contention remain separate acceptance work.

### Selected graduate policy and approval checks

Trusted operators can select the institution's policy root or one program pursuit's graduate-course approval root:

```sh
/usr/local/bin/php84 codex sis:history:verify --graduate-policy --events=500 --json
/usr/local/bin/php84 codex sis:history:verify --graduate-approvals=PURSUIT_ULID --events=500 --json
```

Use the **pursuit identity**, not the student, section or approval-operation identity. These modes are mutually exclusive with all other selectors, `--streams` and continuation cursors. Invalid selectors and allowances fail before lazy provider/storage resolution. The existing `ConsoleCommand` uses plain untranslated console strings and performs no writes.

Policy verification replays protected publications through the ordinary aggregate and compares the technical policy counter. An intact zero-version root is an explicit empty observation, not an inferred earlier policy. A missing counter, malformed chain or mismatch is unverified.

Approval verification restores all recorded decisions for that pursuit, including revocations, and compares the technical approval counter and canonical pursuit/student/person routing. Each distinct retained context authenticates its original enabled policy/minima, published course and program academic levels, program-bound calculation publication and section/course definition. Available course and core section-definition caches are compared with genuine retained definitions. Today's GPA, advisor assignment and policy are not substituted for earlier accepted facts. The check does not recalculate a historical GPA, authenticate every underlying academic source position or retry receipt, certify every ancillary projection or archive completeness, or authorize registration.

One request shares **1–2,000 events, four MiB of protected payload and five cooperative seconds** across the selected root and referenced histories. Separate inventory limits allow at most 200 retained contexts, 200 reference selections, 200 rows per selected relation and four MiB of captured reference rows. Increasing `--events` does not remove those inventory limits. Reads refuse enclosing transactions, acquire no write locks and invoke no projectors. Before/after comparisons cover protected heads, technical counters, routing and selected reference inventories; driver/operator deadlines remain necessary for blocked SQL.

Results contain only the selected reference, scope, fixed status/issues, event count and a decision count on success. `unverified`, `changed` and `limited` return no partial successful record count. Exit codes are **0 verified, 3 empty/changed/limited, 1 unverified/unavailable, 2 invalid input**. Raw payloads, GPA/grade values, reasons, actors and academic source identities are omitted.

The [staff workflow](graduate-course-registration.md#check-retained-policy-and-approval-history) reuses these readers with a fixed 200-event allowance, conjunctive source/support/audit duties, private CSRF transport and minimized existing support receipts. Observation never publishes a policy, records an approval, alters an enrollment or repairs a projection. No migration, new grant, report, journal or background worker is required. Populated qualification uses fictional write-prohibited SQLite records; the configured PostgreSQL policy root is still unpublished and was checked under server-enforced read-only restrictions. Selected native graduate business/concurrency cases are [qualified separately](native-workflow-qualification.md#graduate-policy-approval-and-registration-recovery); broader policy/load and restore acceptance remain separate.

### Full protected journal checks

Deployment operators can check protected event chains with the existing console runner:

```sh
ddev codex sis:history:verify
ddev codex sis:history:verify --streams=10 --events=500 --json
```

This command reads indexed stream heads and verifies their events through the journal's existing authenticated paging. It checks protected head bindings, contiguous positions, event bindings, registered schemas and hash chains, and rejects event positions outside the committed head. It then independently pages through event references and checks that each referenced stream head and retry receipt exists and belongs to the same stream. This catches orphan events that head-only traversal cannot see, including a journal with events but no heads, and missing or incorrectly linked retry receipts.

Finally it traverses all retry receipts, authenticates their encrypted envelopes and verifies the recorded outcome against the exact original event IDs and positions. It authenticates the associated event rows, checks their operation/actor/context bindings and recomputes the original request fingerprint using the same canonical intent calculation as append. This uses retained facts rather than today's mutable catalog or profile values. Unknown framework/legacy actors remain unknown. Extra receipts without a matching head or event batch also fail verification. These receipts support returning the original result when a request is retried; checking them never resubmits that request.

The global chain/reference/receipt mode supports the shared User, financial, grading and academic streams. That mode does not invoke aggregate handlers, projectors, notifications or external integrations, and does not change database records. This is deployment-operator maintenance, not work added to ordinary page loads. No migration is required.

Each invocation visits at most `--streams` chains (default 10, allowed 1–50). `--events` is the shared check budget (default 500, allowed 1–2000): one chain event, one event-reference check or one whole receipt each consumes one unit. Chain and inventory pages contain at most 20 records. The reference inventory reads encrypted event envelopes in primary-key order to obtain their protected operation references; it checks each page's unique head and receipt references with bounded indexed lookups. It does not scan an entire table searching for missing references or output decrypted contents.

A receipt is an atomic verification unit containing at most 50 associated events, matching the append batch limit. At most 20 receipts are verified per invocation, even with a larger check budget; therefore receipt verification reads at most 1,000 associated events in that invocation, processing one receipt batch at a time. Those associated-event checks are reported separately rather than charged again against `--events`. A five-second work budget is checked between pages and receipts; this is not a hard database-query or receipt timeout. A stable journal with 100 events and 10 receipts normally consumes 100 chain checks, 100 reference checks and 10 receipt checks, with 100 associated-event checks during receipt validation. Memory and work remain bounded without materializing the complete journal.

JSON output contains `status`, `streams_completed`, `events_checked`, `inventory_rows_checked`, `receipts_checked`, `receipt_events_checked` and `next_cursor`. Counts describe that invocation. `inventory_rows_checked` counts event-to-head/receipt checks; `receipt_events_checked` counts associated events authenticated again while validating receipts. The sum of `events_checked`, `inventory_rows_checked` and `receipts_checked` never exceeds `--events`. A partially checked stream is counted as completed only in the batch that finishes it. Continuation resumes its stage without replaying already completed chains or receipts. When the status is `limited`, pass the exact opaque `next_cursor` into the same command:

```sh
ddev codex sis:history:verify --streams=10 --events=500 --json --cursor='<next_cursor from the preceding JSON output>'
```

The continuation is encrypted and purpose-bound. It retains the current stage and last traversed row, plus the current stream's verified position and captured upper version/hash when checking a chain. Text output omits it; only explicit `--json` output supplies it. Treat operator output and shell history as private maintenance information. A cursor grants no database or application access and is not a durable integrity attestation. No new checkpoint table or file is created. Cursors issued before the inventory stage was added are rejected; start a fresh pass after updating this alpha version.

| Exit code | Meaning |
| --- | --- |
| 0 | Chain, reference and receipt traversals finished for the rows visible to this pass. |
| 1 | Recorded history or a receipt could not be verified, or protected storage/configuration is unavailable. No repair is attempted. |
| 2 | Invalid limits or continuation. Start a fresh pass if the cursor cannot be validated. |
| 3 | This batch reached a limit; continue using its JSON cursor. |

Failures expose fixed messages and, when available during chain verification, the stream support-row reference. Inventory and receipt failures omit damaged references because a corrupted identifier could contain a person's identifier or arbitrary content. They do not return aggregate/person identifiers, operation IDs, event/receipt contents, keys or upstream exception chains. No missing head or receipt is recreated and no orphan event is deleted. Keep the command restricted to trusted deployment operators; there is no browser or reporting endpoint for it.

A pass is not a global database snapshot. Each stream is checked through the boundary captured when it is opened; later appends are outside that prefix. Concurrently committed heads, event rows or receipts behind a traversal position can also require a fresh pass. Reference or receipt checks of later appends do not extend a previously captured chain boundary. Previously checked rows changing between batches require a fresh check to detect. Follow the [maintenance-window procedure](maintenance-mode.md#a-stable-maintenance-window) and coordinate super-administrator edits when assessing a stable dataset; maintenance admission alone does not freeze the database.

This stage verifies chains reached through recorded heads, visible event references and retry receipts. It cannot prove archive completeness or detect every valid earlier whole-database restore; for example, deleting an entire stream together with all of its events and receipts requires independent evidence to detect. This global mode does not reconcile projections, replay business decisions, certify legal compliance or establish backup/restore readiness. Selected financial-account comparison is available separately below in the same command. Independent anchors, verified projection checkpoints and isolated rebuild/restore exercises remain open. Extend this operator tool as those checks become supported, rather than introducing separate recovery screens for each check.

## Selected financial account verification

Use the same command to compare **one** student account's identity, balance and posting read models with its authenticated recorded history:

```sh
ddev codex sis:history:verify --account='<sis_student_accounts.id>' --events=500 --json
```

The account reference is its internal ULID, not the student's institutional ID. This mode is for trusted deployment operators with access to the application database and history keys. It adds no workspace screen, browser endpoint, migration, event type, repair job or scheduled worker. It cannot be combined with `--streams` or `--cursor`. Invalid references/limits are rejected before resolving storage.

The check authenticates the selected account stream through the existing protected paging API, restores the financial aggregate in memory, and compares its student/term/currency identity with the canonical account row. It compares balance, charge/payment/refund totals and applied position; posting count/checkpoint; every original/replacement posting's stable ULID, operation identity, amount, signed effect, currency, reversal/correction/credit links, origin, recorded time and original sequence. Missing, extra, changed or partially initialized rows are unverified. A missing account never becomes a verified empty ledger.

Comparison shares the deterministic state calculation used by normal projection maintenance and conversion, but invokes only explicit read-only comparison methods. It never calls initialization, posts money, appends events, changes receipts, repairs a row, writes an audit record, sends a message or grants access. Populated tests run with SQLite writes prohibited and compare before/after journal, identity, projection and audit snapshots. Ordinary reports and screens still use indexed projections; this selected replay happens only when the operator invokes it.

The command captures and rechecks the selected stream head and account identity around replay/comparison. If a legitimate concurrent update changes either, the result is **changed**, not a claim of damaged projections. A verified result applies only to the observed position, not to later activity. Unrelated account activity does not invalidate that check. This is an observational check without write locks or a global maintenance lock; it does not freeze the system or supply an independently signed checkpoint.

Selected replay defaults to 500 events and accepts 1–2000. A known longer stream returns **limited** before loading it; no partial comparison is certified. There is also a 4 MiB retained event-payload/metadata allowance and a cooperative five-second work deadline checked between events. Database query timeouts remain deployment configuration; this is not a hard per-query deadline. Posting reads are paged and capped at 2,001 rows to detect an excessive set. Selected mode creates no continuation or persistent snapshot: retry a changed check or deliberately raise the event limit within the maximum; larger histories/byte limits need planned offline verification.

JSON includes only `status`, `scope`, `account_reference`, captured `position`, `events_checked`, `expected_postings`, `issues` and `records_changed: false`. Expected postings describe the reconstructed ledger, not a promise that damaged/missing rows matched; this count stays null when the ledger could not be reconstructed. Output contains no amounts, currency, person values, reasons, receipt details, event contents or upstream error messages. Treat the account reference and operator output as private maintenance information.

| Status / exit | Meaning |
| --- | --- |
| `verified` / 0 | The selected journal and current account/projection values matched at the observed position. |
| `unverified` / 1 | Required evidence was absent, inconsistent or unavailable; no row was repaired. |
| Invalid input / 2 | Use one canonical account ULID, a supported event limit and optional JSON output. |
| `changed` / 3 | The selected account changed during the check; rerun against its newer state. |
| `limited` / 3 | The replay/event-size/work allowance did not permit a completed check. |

Issue codes distinguish `journal`, `account_identity`, `balance`, `postings` and `unavailable`. They identify a component for investigation without printing its private values. Fixing a real financial decision still requires the authorized, attributed correction workflow; fixing a projection requires a deliberately reviewed reconstruction. Do not edit the event journal to make the check pass.

This selected mode does not validate every retry receipt, the whole event inventory, person/student histories, historical archive completeness, external bank settlements or backup/restore readiness. Run the global mode for its independent chain/reference/receipt traversal. Independently protected anchors, other domain projections and isolated rebuild/restore acceptance remain open; event sourcing and this command alone do not establish legal compliance.

On 2026-11-19, the configured DDEV MariaDB application correctly returned an unverified result for a missing account, including a canonical all-numeric ULID. Before/after checksums of the journal, support and financial projection tables were unchanged. That database had no financial accounts; populated comparisons, tampering and concurrent updates were verified with fictional SQLite fixtures, not a populated MariaDB ledger.

## Selected student academic verification

The same operator command can check one student's available registration, institutional-credit and transfer-award sources against protected history:

```sh
ddev codex sis:history:verify --student='<sis_students.id>' --events=500 --json
```

Use the canonical student ULID, not an assigned institutional number. This mode requires trusted deployment access to the application database and history keys. It rejects `--account`, `--person`, `--streams` and `--cursor` combinations before resolving storage. It adds no browser endpoint, ordinary reporting source, scheduled worker, schema, academic decision or repair operation.

The check reuses `AcademicInputVerifier`, the internal available-source verifier used by registration, degree progress and transcripts. Its six-source bounded inventory compares surviving institutional/transfer source links, retained academic and registration streams, projections and accepted version references. Early removals are checked internally and excluded from the current-record count. Recorded grades, course titles, source identities, removal counts, actors, reasons and raw history never appear in operator output. This mode does not filter work to a pursuit or validate attribution/reuse decisions, person identity history, degree requirements, financial postings or archive-wide completeness. Use the ordinary authorized pursuit reviews and separate financial/global checks for their distinct purposes.

This is an observation without write locks. It runs outside an existing transaction and compares the student’s existence, complete bounded source-routing inventory and relevant scalar journal heads before and after verification. A detected registration/drop/award change returns `changed` and discards the current count. It never calls the student write lock, initializes a projection or writes access-audit rows. This avoids SQLite's update-based locking reads and keeps the command usable with writes prohibited. A quiet maintenance window remains necessary for a stable acceptance pass; the check is not a transaction-wide snapshot or independently protected integrity checkpoint. An unchanged boundary cannot detect facts and all their routing references disappearing together or prove that a prior valid database was never restored.

Limits are shared across the selected student: 200 available source identities, 201 rows per inventory query to detect overflow, 400 replay pages, at most 20 events per page and 1–2,000 returned events (500 by default). Optional replay-budget controls add a 4 MiB serialized event-payload/metadata allowance and a five-second cooperative deadline. Checks occur before/after replay pages and after verification/boundary comparison; database query timeouts remain deployment concerns. Other workflows retain their existing budget defaults. No partial record count escapes a failed or exhausted verification, and no continuation/result file is created.

JSON contains only `status`, `scope: student_academic_inputs`, `student_reference`, `events_checked`, nullable `current_records`, fixed `issues` and `records_changed: false`. Event counts describe returned replay events, including repeated reads, not unique events or proof of full-chain completion after a limit. Treat output and shell history as private maintenance information.

| Status / exit | Meaning |
| --- | --- |
| `verified` / 0 | Available recorded sources and their projections agreed within the observed boundary. This can include retained early-drop history with zero current records. |
| `history_gaps` / 3 | Available sources agreed, but some earlier history was unknown or explicitly observed. No earlier events were invented. |
| `observed_empty` / 3 | The student exists but no available sources were discovered. This is not proof that no academic history ever existed. |
| `changed` / 3 | Routing or relevant heads changed; repeat the check against the newer state. |
| `limited` / 3 | The inventory, replay, payload or time allowance prevented a completed check. |
| `unverified` / 1 | Student identity, academic sources or protected storage could not be verified. Fixed issue codes are `student_identity`, `academic_sources` and `unavailable`; no exception contents are printed. |
| Invalid input / 2 | Use one canonical student ULID, a supported event limit and optional JSON output. |

Fictional fixtures verify recorded institutional and transfer work, earlier unknown transfer rows, early removals, projection/journal damage, limits, concurrent registrations and changed existing heads. Populated checks run with SQLite writes prohibited and unchanged journal/source/audit/finance snapshots. The native command is exercised through actual lazy providers. A bounded MariaDB check on 2026-11-20 correctly rejected a missing canonical student; that database contained no students and 20 journal events. All eleven inspected journal/support/academic/audit table checksums were unchanged. Populated MariaDB and other live-driver acceptance, isolated rebuilds, protected checkpoints and restore certification remain open.

## Selected person profile verification

Use the same operator command to compare **one canonical person** across their shared profile areas:

```sh
ddev codex sis:history:verify --person='<sis_persons.id>' --events=500 --json
```

Use the person ULID, not an institutional number, account ID, student ID or employment ID. This is a trusted deployment operation requiring database and history/decryption-key access. It adds no browser route, ordinary reporting field or staff permission. Exactly one of `--person`, `--student` and `--account` may be supplied; selected checks reject `--streams` and `--cursor` before resolving storage.

One authenticated person-profile replay feeds all current comparisons:

- Core names, directory restriction, institutional identifier/version and canonical account ownership.
- The linked account's current first/last names and unique person link. Credentials, tokens, email, sign-in status and the User credential stream are outside this check.
- Preferred name, phone and retained freeform mailing text.
- The complete dated address book and managed-mailing flag, including historical intervals still in that book. Today's derived mailing display is not stored as another authority.
- The ordered emergency-contact list, including an explicitly cleared list.
- Self-reported education and its retained review state. This does not verify credentials or award academic credit.

Encrypted projections are decoded through their existing strict repositories and compared with the already replayed `ProfileHistory` facts, including versions. A missing known row, different retained value, malformed encrypted payload or unexpected new address/emergency/education row is unverified. Legacy freeform contact rows without retained contact evidence remain explicitly unrecorded. Core profiles without an earlier stream and genuine first observations remain history gaps; the tool neither creates an observation nor silently adopts current rows. Matching current projections do not prove earlier history.

The default allowance is 500 events, selectable from 1–2,000. All profile areas share one replay, a 4 MiB payload allowance and a five-second cooperative guard. Projection reads select at most two rows per person/area, so duplicate rows fail rather than increasing work with the institution's population. Replay applies facts in memory without calling a profile editor, audit writer, messaging service, account setup or repair operation. The check refuses an enclosing transaction, reads without write locks and captures/rechecks the selected profile head, identity, account-link/name facts and encrypted projection rows. An intervening selected edit reports **changed** instead of damaged projections. Unrelated-person activity and credential/token updates do not invalidate this profile-only check.

| Status / application exit | Meaning |
| --- | --- |
| `verified` / 0 | Recorded creation and current profile projections match through the captured position; no observed/unrecorded earlier component was encountered. |
| `history_gaps` / 3 | Available current comparisons match, but earlier observations or unrecorded identity/contact facts prevent full verification. |
| `changed` / 3 | The captured head or selected current rows changed; repeat the check. |
| `limited` / 3 | Replay, payload or time allowance stopped the check; no partial component result is certified. |
| `unverified` / 1 | A component differs, protected history is damaged, or storage/keys are unavailable. No repair is attempted. |
| Invalid input / 2 | Invalid limits, reference or incompatible options; storage is not resolved. |

JSON contains only `status`, `scope`, the selected `person_reference`, captured `position`, `events_checked`, whitelisted `issues`, `unrecorded_components`, `earlier_history_unknown` and `records_changed: false`. Incomplete results withhold partial gap diagnoses. Issue codes are `journal`, `identity`, `account_link`, `contact`, `addresses`, `emergency_contacts`, `education` and `unavailable`. Output never includes names, assigned identifiers, phone/address/contact values, education/feedback, credentials, keys, ciphertext or underlying exception text. Keep the reference and shell output within private operator procedures. DDEV may wrap a nonzero application exit with its own failure message; `history_gaps` is an incomplete-verification outcome, not permission to synthesize missing history.

This observational check provides no independent checkpoint or global snapshot. It does not validate every retry receipt, prove archive completeness, traverse orphan records without a reachable person stream, reconcile academic/financial/HR decisions, or rebuild/erase data. Use the global mode for its separate chain/reference/receipt inventory. Investigate current differences through authorized domain workflows or a separately reviewed reconstruction; never edit history to make a check pass.

Bounded fictional verification covers populated and explicitly empty profiles with SQLite writes prohibited; all encrypted areas, missing/altered rows, privacy/identity/account drift, observed/untracked earlier facts, exact multi-page allowances, damaged/oversized history, provider failures and concurrent edits. Actual provider/console fixtures verify lazy invalid-input handling, text/JSON privacy and exit codes, alongside regression checks for existing global/account/student modes. Final checks passed **38 PHP tests / 246 assertions**, plus the separate student-command bootstrap check. Scoped production/new-test PHP coding standards and whitespace checks passed. Native DDEV MariaDB returned `history_gaps` for an earlier untracked profile, with all ten inspected event/support/profile/audit checksums unchanged; there were ten people, twenty journal events and no profile streams at that inspection. Populated native-server, rebuild and restore acceptance remain open.

## Verification and next steps

The schema compiles with the installed MySQL, PostgreSQL, SQLite, SQL Server and Oracle compilers. Fictional SQLite tests exercise populated and empty conversion, all thirteen existing event types, preserved identity/provenance, restart and source-prefix verification, failed conversion without source deletion, shared framework/domain replay, version conflicts, transaction rollback, tampering, cursor boundaries and key rotation. Other engines still need live multi-session locking, DDL recovery and LOB integration tests; compiler coverage is not live-driver certification.

Operator-verification tests additionally cover long and mixed streams, bounded continuation across all stages, concurrent appends, grading-scale stream lookup, altered events/heads, missing events, out-of-range positions, orphan events, missing/moved/altered receipts, exact outcome/actor/operation bindings, fingerprint recomputation, maximum-size batches, the receipt ceiling, safe failure output and unchanged stored rows. On 2026-11-13, a bounded check of the configured development MySQL database verified 17 streams, 20 events, 20 event-to-head/receipt references and all 20 receipts with their associated events; before/after checksums of the three journal tables were unchanged. This small live check does not establish cross-driver or production readiness.

The first [published course-definition slice](course-publication.md) is implemented for currently recorded fields. Next are the remaining immutable course/section definitions and version pinning from the [legacy field review](academic-history-field-review.md), explicit academic schemas and authorized domain commands. Grade/credit, registration and lifecycle conversions follow. Logging a mutable SQL update without making the retained academic history authoritative would not complete those conversions.

The [section binding workflow](course-publication.md#bind-a-section-to-a-publication) uses the same protected journal. Its normalized definition and section/credit reference tables are projections, not additional event tables. Existing academic rows are not retroactively assigned publication versions.

The [degree requirement builder](degree-audit-builder.md) publishes versioned program/academic-year definitions through the same journal. Separate group/composition authoring tables store drafts and publication metadata, not another event history. New degree schema-3 publications retain institutional policy choices and exact group-version references; earlier schemas remain unchanged. Explicit student-program requirement-version assignment now has its own protected decision stream and reconstructible projection. Student evaluation remains a subsequent stage.

The [student requirement assignment workflow](student-degree-requirements.md) registers `StudentRequirementsWereAssigned` schema 1 in this same journal. It retains actor/reason, trusted effective time, student-program scope, exact degree/cohort references, pursuit date and predecessor. The projection is verified against bounded replay; changes preserve original selections. No current-state student rows are converted into invented historical events.

[Student course waivers and minimum exceptions](student-degree-exceptions.md) add two schema-1 event contracts in the same authoritative table. The active query projection discards replaced/revoked rows while preserving their protected events. Approval references retain the original publication; waiver authority covers one pursuit and its course identity, while minimum reductions bind to a specific degree version.

[Grading-scale setup](grading-scale-setup.md) adds global/program policy decisions to this same journal, with a verified current projection. New degree publications retain their resolved scale; original academic grade/credit writers remain scheduled for event-sourcing conversion.

Grade-definition normalization retains all `GradingScaleWasChanged` schema-1 event bytes and receipts. `sis_grades` now contains immutable per-publication definitions with deterministic ULIDs; `sis_grading_scales` holds current metadata. Offline projection conversion verifies actual events and the earlier JSON projection before removing it. No additional event table or fabricated grade-policy event is introduced. [Setup/cutover](grading-scale-setup.md).

## Selected admissions application verification

Trusted deployment operators can run one read-only check:

```sh
ddev codex sis:history:verify --application='<sis_applications.id>' --events=500 --json
```

This reuses the admissions ledger's actual submission, decision, offer/review-state, retained answer-revision and matriculation validation. It compares current application identity/status/version/intake with those recorded facts and checks the original submission's intake projection using the same deterministic field mapping as its writer. Retained form definitions, original answer fingerprints and later submitted artifacts retain their existing protected validation. No artifact is filled, decrypted into the output or rewritten. Working requests, document contents/scanning, casework, every retry receipt and downstream student/program projections are outside this selected check; use their own checks and controls.

`--application` is mutually exclusive with the person, student and account selectors. It accepts `--events=1..2000` and optional `--json`, and rejects `--streams`/`--cursor` before resolving storage. The default allowance is 500 events; application and matriculation replay share it. The existing budget also limits replay payloads to 4 MiB and elapsed replay work to five seconds. Retained answer artifacts keep their existing per-artifact/revision bounds. The verifier rejects an enclosing snapshot transaction, holds no source write lock and captures/rechecks current application/person routing, both stream heads, intake fields and original artifact references. A concurrent source commit returns `changed`, rather than certifying the earlier observation or reporting the legitimate commit as corruption.

Output contains only the selected reference, fixed scope/status/component codes, checked-event count, observed decision/matriculation positions and an earlier-history flag on completed observations. Application outcomes, names, answers, reasons, credentials, exception text and raw events are excluded. A genuine tracked submission can verify; an earlier untracked application or explicitly observed earlier outcome remains `history_gaps`. No diagnostic invents an original application/submission time. Missing/altered intake fields require review even if the writer could deliberately populate a known earlier nullable index; this read never calls that write path.

Exit status is **0** for verified, **3** for history gaps/changed/limited, **1** for unverified/unavailable and **2** for invalid options. Text output explains the same limited scope. The command writes no domain event, projection, audit or support receipt and initiates no external effect. Browser checks below add only their separately authorized minimized operational receipt and required audit. Neither route certifies a complete archive, independent integrity anchor or successful restore.

Validation: **70 focused PHP tests / 558 assertions**, including actual provider/command/router/CSRF/privacy checks, populated SQLite fixtures with writes prohibited, original/revised answer corruption, current application/intake drift, shared matriculation allowance, earlier gaps, safe failures, changed routing and atomic receipt/audit rollback. **Eight distinct browser workflows** cover the four shared contexts plus application-specific return/delayed/access-loss behavior. The configured native MariaDB database was inspected read-only: zero applications/intake/admissions streams, twenty existing protected events and four existing support receipts. There was no native data/schema change; populated native application and multi-engine qualification remain open.

## Selected standing and enrollment-load verification

The existing `sis:history:verify` command now checks either a program pursuit's standing or one retained enrollment-load determination:

```sh
ddev codex sis:history:verify --standing='<sis_student_programs.id>' --events=500 --json
ddev codex sis:history:verify --load='<sis_load_determinations.id>' --events=500 --json
```

Use the internal pursuit/determination ULID, not an institutional student number. Each invocation accepts exactly one selected mode, optional `--events=1..2000` and optional `--json`. It rejects other selected modes, `--streams` and `--cursor` before resolving storage. Trusted deployment access and the existing history keys are required. Console output uses plain strings through `ConsoleCommand`.

Standing uses `StudentStandingLedger` to replay the selected pursuit's recorded/corrected decisions and compare the complete accepted periods with the current projection. It checks canonical pursuit/student/person existence and accepts closed pursuits. Renamed/retired library labels never replace retained labels. An existing pursuit with neither a standing stream nor projected periods returns `observed_empty` (exit 3): earlier standing remains unknown. A genuine later decision that removes all periods can verify with zero periods. Missing identities, unmatched projections and damaged history return `unverified` (exit 1), not an invented empty history.

Load uses the shared `LoadDeterminationLedger`, extracted from the existing reader/writer without changing the decision model. It validates the original one-event aggregate and retained intent, context, date, source references and exact snapshot; the same deterministic projection calculation compares every retained routing, term/program label, date, credit total, count, classification and policy-reference column. It does not recalculate a current load or require old registrations, current definitions or today's policy to match a former observation. It does not independently re-evaluate every source registration or referenced policy publication. A missing decision/projection fails verification. Each accepted determination remains its own immutable fact.

Both modes run outside an existing transaction, with no source write locks, audit writes, repairs or external effects. Small routing/projection/head reads before and after replay detect observed changes and return `changed` (exit 3), discarding counts. Checks share the existing 400-page/20-event-page allowance, the requested event cap, a 4 MiB serialized payload/metadata allowance and a five-second cooperative deadline. Known excessive heads return `limited` (exit 3) before replay; no partial record count is certified. Database query timeouts remain deployment configuration. An unchanged boundary is an observation, not a global snapshot, independent checkpoint, proof of archive completeness or protection against a valid whole-database restore.

Value-free JSON contains `status`, `scope`, the selected `pursuit_reference` or `determination_reference`, `events_checked`, observed `position`, nullable `records_checked`, fixed `issues` and `records_changed: false`. It withholds labels, dates of decisions, classifications, credit amounts, enrollment/source identities, policy details, protected narratives and raw events. A completed nonempty-history comparison returns `verified` (exit 0). Use authorized domain correction workflows for business facts; these checks never repair them. Global mode remains the separate chain/reference/retry-receipt inventory check.

Validation on November 23, 2026: **84 distinct PHP tests / 692 assertions** across selected verification/commands/support, actual router/provider/CSRF/private responses and existing standing/load/registered-credit/reinstatement flows; **18 distinct browser scenarios**, including five new support flows and five standing/load regressions. Populated fictional SQLite checks prohibit writes and preserve snapshots. Damage, every load projection component, source/routing changes, unknown earlier standing, replay bounds, source-duty conjunctions, super protection, post-audit authority loss and receipt rollback are covered. The configured native database was inspected read-only before/after: twenty protected events, four existing support receipts, no pursuits, standing periods or load determinations. No native decision, support receipt, grant, migration, schema or journal change was made; populated native/multi-engine and institutional restore qualification remain open.

## Contextual staff history checks

Authorized support staff can now use the same selected verifiers from the existing record workspace:

- **Person → More → Check profile history** compares selected profile projections and the linked account names with retained profile history.
- **Student → More → Check academic history** checks available academic inputs and projections. Removed early work stays restricted and excluded from visible current-record counts; this is not a full archive, pursuit-attribution or degree-completion evaluation.
- **Student account → Check account history** compares the selected account's retained postings/balance projections with its protected history. This does not verify an external processor, settlement or every retry receipt.
- **Admissions → Open application → More → Check application history** checks the selected application and retained evidence/intake projections using the same bounded operator verifier above. It returns through **Back to record**, retaining the ordinary application editor's leave guards. It does not decide, withdraw, matriculate or amend an application.
- **Student → Student programs → More → Check standing history** checks that pursuit's retained standing decisions and periods. It additionally needs `sis:standing:manage` and `sis:student:read`.
- **Enrollment load → Recorded determinations → selected term → Check retained load history** checks one accepted determination. It additionally needs `sis:load:determine` and `sis:student:read`. **Back to record** restores its original term and history page, including newer/older navigation.
- **Employment → Primary position → Check primary designation history** checks the retained designation, its projection and the referenced agreement. A changed/ended agreement can be internally consistent without being eligible as today's primary position. A proposed designation/reason is preserved through the support drawer; returning requires a fresh business review before confirmation.
- **Employment → Position assignments → Open agreement → More → Check position agreement history** checks that agreement and its projection. **Back to record** restores the agreement and its original paged assignment return.
- **Time entry → Check retained time decision** compares one protected accepted decision with its current entry/work-position reference. Returning preserves the time-entry/weekly-review route. This check does not replay today's policy or alter an earlier approval.
- **Registration → Undergraduate access to graduate courses → Check policy history** checks institutional policy publications and their counter, without personal receipt routing.
- **Graduate-course approval drawer → Check pursuit approval history** checks that pursuit's decisions, including revocations, and original retained definitions. [Source permissions and draft-preserving return](graduate-course-registration.md#check-retained-policy-and-approval-history) apply independently of ordinary advisor/owner access.

These entry points share one support panel and service. They do not expose the global journal/chain command in a browser, offer repair actions, reveal payloads or create separate recovery queues. The deployment command retains its trusted operator interface and read-only behavior. The staff facade additionally records minimal operational receipts and metadata-only audits after verification.

Grant the independent protected **`sis:history:verify`** duty and **`sis:audit:read`**, together with the source duties: `sis:person:manage` for profiles, `sis:student:read` for academic checks, both `sis:student:read`/`sis:finance:read` for accounts, `sis:admissions:manage` for applications, `sis:student:read`/`sis:standing:manage` for standing, `sis:student:read`/`sis:load:determine` for retained load, or `sis:hr:manage` for employment checks. Existing workspace sign-in requirements still apply. Record ownership, a report share or a teaching/advisor assignment grants none of these duties. Personal contexts protect super-linked people from non-super support operators. Earlier unlinked person records can be diagnosed without creating an account or inventing earlier profile history. No new role/user grant is assigned automatically; super retains existing implicit authority.

Opening the panel reads only its previous support receipts. **Run history check** deliberately starts one fixed **200-event** observation, reusing the existing byte, page/source and five-second cooperative replay guards. Browser-supplied larger limits do not change it, and there is no polling or automatic continuation. A limited result needs trusted operator review with the existing CLI's separately bounded allowance. Database query timeouts remain a deployment concern; the replay guard is not a hard query deadline.

The result distinguishes verified, history gaps, empty selected-record observations, unverified, changed and limited outcomes. It displays only safe component labels, counts, observed positions where applicable, observation time and a support reference. An empty count, earlier observation or exhausted limit is not complete verified history. No private values, names, money, grade details, restricted removal identities, account credentials, reasons or raw event/exception contents appear. Source failures return a minimized unavailable result rather than provider exception text.

The selected verifier runs **outside** a snapshot transaction and without support-held source locks, preserving its own concurrent-change detection. Afterward, a short role/person/student locking transaction rechecks the same actor, every duty, super protections and canonical routing, then atomically stores the support receipt and required audit. Authority/account/actor changes or audit failure roll this metadata back and return no result. The financial verifier now also rejects an enclosing snapshot transaction, matching the other observational modes. No verification appends a business event, changes a projection, posts money, changes a grade, grants access or sends a message.

`sis_history_checks` is an immutable operational observation receipt table, **not another domain event journal or an independently verified projection checkpoint**. Its ULID reference, scope/routing, authenticated recorder, status, bounded event count and UTC timestamp support attribution. It stores no replay result body, values, payload, reason, monetary total or credential. Previous checks use ten-row cursor pages, scoped to the current person/account/student routing; earlier routing receipts remain retained and hidden after a link change. Actor/routing IDs are omitted from browser receipt listings. A receipt describes its observed point, not a continuing guarantee that the record has stayed unchanged or approval for a later consequential action. Repeated deliberate runs are separate observations with new references; normal writes retain their own fresh concurrency/history checks.

The private POST routes retain framework CSRF, no-store and debug suppression. Draft/results stay in page memory. Leaving a pending check discards its late UI response, and denied access clears displayed results/history. Migration **`20261121000004_CreateSisHistoryChecks`** adds the minimized table/index and independent duty without converting existing source facts. Rollback refuses destructive receipt deletion. Institution-specific retention/disposition, complete archive reconciliation, independently protected anchors, isolated rebuild/swap and restore acceptance remain open.

Validation completed 2026-11-21: **47 focused PHP tests / 376 assertions**, separate real-container/router/CSRF/private-response checks, **five browser workflows**, clean scoped new/changed PHP coding standards, JS syntax and whitespace checks. Coverage includes real profile/academic/financial verifiers, source-duty conjunctions, super protection, missing earlier account links, wrong provider references/limits, safe failures, verification outside receipt transactions, damaged receipt rows, audit/authority/routing rollback, ten-row paging, all five schema compilers, narrow-screen accessibility, access-loss clearing and delayed-response navigation. Existing unrelated formatting in the shared console fixture/routes/config was preserved. Checks ran sequentially with low priority, timeouts, 96 MiB PHP and a single 192 MiB browser worker.

Applied **`20261121000004_CreateSisHistoryChecks`** on inspected configured `nomadicjosh_sis` MariaDB in **0.11 seconds**. All nine original journal/support/person/student/account/audit/grant checksums matched; twenty original journal events remain. The support table starts empty and the new protected permission has no new role/user grants. Native populated/load, live multi-engine and institutional retention/restore qualification remain separate.

## Selected employment checks for trusted operators

The same selected verifiers are available through the existing command. Supply one target and an explicit bounded allowance when needed:

```sh
ddev codex sis:history:verify --primary-position='<sis_employees.id>' --events=500 --json
ddev codex sis:history:verify --position='<sis_employee_positions.id>' --events=500 --json
ddev codex sis:history:verify --time-entry='<sis_timesheets.id>' --events=500 --json
```

These modes accept 1–2,000 events, a 4 MiB payload allowance and five cooperative replay seconds. They reject combining selected targets, `--streams` or `--cursor`. Exit 0 means verified; 3 means an empty observation, earlier-history gap, changed boundary or exhausted allowance; 1 means unverified/unavailable; invalid input exits 2. Outputs contain selected references, stream position, compared-record/event counts and fixed issue codes. They contain no HR reasons, names, compensation, work intervals, policies or raw payloads. Console strings remain plain operator text without translation helpers.

A primary check verifies the selected agreement under the same shared allowance. A position check verifies only that selected agreement. A time check uses its retained decision evidence, so a later policy, primary designation or appointment change does not invalidate an otherwise consistent original decision. Pending work without a decision and a designation with no recorded history produce an empty observation. An older approved/rejected row with no protected decision produces an explicit history gap; it does not become a verified approval. These observations do not establish earlier completeness, current appointment eligibility, payroll, every retry receipt or restore readiness.

The verifiers refuse enclosing snapshot transactions, compare current routing/projections/relevant heads before and after bounded reads, detect changes even after a failed scan, and never lock, repair or write business records. They reuse the normal primary, position and time-decision validators. The staff facade runs verification before its separate receipt transaction, then rechecks current routing, ownership/super safeguards and all duties around its required audit. A saved receipt describes the completed observation, not a continuing guarantee about the record.

Apply `20261123000007_ExpandSisHistoryCheckSubjects` with normal migrations. The earlier eight-character column could not store the existing eleven-character `application` subject on strict databases. The migration widens only support routing to 32 characters on MySQL/MariaDB, PostgreSQL, SQL Server/DBLIB and Oracle; SQLite already accepts longer VARCHAR text, so its populated table/indexes need no rebuild. Existing receipt values, journal data and grants remain unchanged. Downgrade refuses narrowing that could truncate observations.

### Controlled student-account projection reconstruction

The separate [account projection recovery guide](account-projection-recovery.md) describes bounded encrypted staging with original read rows, fresh comparison, deliberate maintenance-only atomic promotion, protected original-result retries and explicit artifact deletion. `sis:history:verify` remains read-only. Reconstruction uses the same deterministic balance/posting calculations and the existing protected journal, without business event replay side effects. Other projection rebuilds, native contention and complete restore acceptance remain open.
