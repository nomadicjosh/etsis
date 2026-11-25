# Recover one student account's projections

Trusted deployment operators can reconstruct one account's balance, charge/payment/refund totals, posting rows and posting checkpoint from its genuine protected financial events. The workflow is **stage → check → explicitly apply → discard**. It preserves the original journal, stream head, operation receipts, account identity and audit records. It invokes no business command, event handler, external provider, refund, notification or access grant.

This is a console recovery tool, separate from ordinary student reporting and the [read-only financial verification](event-history-foundation.md#selected-financial-account-verification) command. It does not repair financial history, missing accounts or other academic/financial projections, infer events from SQL rows, or certify a complete institutional restore.

## Prepare and stage

1. Use the deployment's configured PHP interpreter and database. Run migrations normally. Back up the database, original application/event keys and necessary private artifacts; use the [independent journal checkpoint procedure](history-checkpoint-recovery.md) when establishing a recovery boundary. Run the global `sis:history:verify` traversal to authenticate operation receipts as well as events. The selected reconstruction authenticates the account stream and compares original receipt storage bytes; it does not independently recertify every business receipt or the completeness of an older backup.
2. Identify the **financial account ULID**, rather than the student's ULID. Check the current problem using `sis:history:verify --account=ACCOUNT_ULID`. Projection disagreement can justify reconstruction; journal damage requires investigation of the protected source and backup instead.
3. Build an isolated stage:

   ```sh
   ddev codex sis:projections:account --action=stage --account=ACCOUNT_ULID --events=500 --json
   ```

   Retain the returned `stage_reference`. Database writes are unnecessary for staging and checking. These actions work with a read-only database connection. Ordinary concurrent activity can make a stage stale; it never becomes an accepted partial reconstruction.

4. Check the stage against fresh history and the original current projections:

   ```sh
   ddev codex sis:projections:account --action=check --stage=STAGE_ULID --events=500 --json
   ```

   `ready` means that the exact original rows and reconstructed output still match the stage at this observation. `requires_repair` distinguishes disagreement from an already healthy account. Checking does not reserve the account or authorize a later stale promotion.

Staging captures the original three projection tables and their fully calculated replacements in an authenticated encrypted file. It does not store a second event journal. Files live in `storage/app/projection-rebuilds/`, outside public assets, with an owned `0700` directory, exclusive creation and `0600` files. Symlinks, broad permissions, copied identities, malformed envelopes and existing destinations are refused. Use the same configured connection and original application encryption key to read the stage. Debug/JSON inspection of its value object is redacted; console output omits financial values, personal identities, reasons, paths and ciphertext.

## Deliberately apply during maintenance

Quiesce workers and other deployment/database writers. Enable maintenance through the existing command:

```sh
ddev codex sis:maintenance down
ddev codex sis:projections:account --action=apply --stage=STAGE_ULID --events=500 --json
```

The tool requires maintenance to be **available and down**, both before and after its writes. Unavailable maintenance cannot authorize recovery. It never changes maintenance itself. Super administrators can still sign in under the existing maintenance policy; coordinate their work. Use deployment connection statement/lock timeouts as well as an outer process deadline so blocked SQL cannot wait indefinitely.

The stage expires twenty minutes after creation, including time spent in maintenance preparation. Applying acquires the account identity lock and the same protected stream-head lock used by event appends, replays and compares the stage again, and constructs all output before replacing any row. A single SQL transaction installs only that account's balance, posting checkpoint and complete posting set, then verifies the actual installed rows through the shared deterministic projectors. Other accounts remain untouched.

Final checks include account/student/term/currency identity, source position, original protected event/head/receipt storage fingerprints, maintenance, expiry and exact replacement inventory. Failures roll back the replacements and completion receipt together. Missing or stale stages, changed current rows, unknown retained projection columns, damaged source evidence and exhausted limits require a fresh review or separate source investigation. Existing business events and earlier unknown provenance remain unchanged.

Successful application stores one authenticated encrypted operational receipt in `sis_account_projection_repairs`, keyed by the stage ULID. It records original counts, scope, time, stage/projection hashes and the explicit executor `system:deployment_projection_recovery`. This represents trusted deployment execution, not an invented signed-in staff action; retain deployment/OS access records identifying the responsible human. The table is support evidence, separate from the authoritative business journal. Its migration refuses destructive rollback.

## Retry and clean up

If a response was lost, repeat **apply with the same stage reference**. `already_applied` returns the original protected outcome without replaying or replacing anything, even after the stage expires, its file is discarded, maintenance reopens or legitimate later financial postings occur. It describes that earlier completion, not a new integrity check of today's account. Damaged or detached completion evidence fails closed.

After reviewing the result, run the selected read-only financial check again and follow the deployment's normal reopening procedure. Discard the disposable stage explicitly:

```sh
ddev codex sis:projections:account --action=discard --stage=STAGE_ULID
ddev codex sis:maintenance up
```

Discard physically removes only the named private file, is harmless when that file is absent, and needs no database connection. It preserves protected history and completed receipts. Expiry prevents use; it does **not** automatically delete files. Keep expired/abandoned stages under a deliberate private-artifact cleanup policy. Preserve the application key needed for retained operational receipts when planning key lifecycle and backups. These files use application encryption; they are not independently keyed integrity anchors.

## Bounds and outcomes

Each stage/check/new apply shares a five-second cooperative work allowance, a requested 1–2,000-event replay limit (default 500), four MiB of decrypted replay data, at most 2,000 postings and four MiB per original/replacement projection inventory. Protected source fingerprints traverse ten-row keyset pages with a combined sixteen-MiB event/receipt ceiling and at most 2,000 receipts. Serialized stages are capped at eight MiB before encryption and sixteen MiB on disk. SQL/filesystem waits still require deployment timeouts. Larger accounts need separately designed offline recovery; increasing the event allowance does not bypass other limits.

| Outcome | Exit | Meaning |
| --- | --- | --- |
| `staged`, `ready` | 0 | Private output saved or fresh comparison completed; database records unchanged. |
| `applied` | 0 | Atomic completion and protected receipt committed; `projections_changed` identifies actual projection replacement. |
| `already_applied` | 0 | Original completion found; no work repeated. |
| `discarded` | 0 | Named disposable stage removed or already absent. |
| `invalid_input` | 2 | Correct exclusive account/stage selectors, action or allowance. Explicit event allowances are unavailable for discard. |
| `changed`, `limited` | 3 | Review expired, sources changed or bounded work could not finish; no promotion completed. |
| `not_verified` | 1 | Source, storage, key, maintenance or transaction requirements failed; no promotion completed. |

The command extends the shared `ConsoleCommand` and uses untranslated operator strings. Only its exact name bypasses the ordinary maintenance command pause. No browser route, role grant, report, worker or new business event type is added.

Qualification covers populated fictional SQLite reconstruction, source drift, write/receipt failures, original retries, actual console/provider wiring and five migration dialect compilers. Configured PostgreSQL qualification covers additive DDL and original-row preservation with an empty receipt table. A subsequent [installed-schema normalization](postgresql-conversion.md#database-choice-and-current-native-collation-policy) removes the converted collation workaround; this migration now uses ordinary default-collation definitions. Populated native contention, forced process loss, larger histories, every other projection and complete backup/artifact restore acceptance remain separate roadmap work.
