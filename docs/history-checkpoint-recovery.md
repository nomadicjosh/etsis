# Independent history checkpoints and recovery

Implemented November 24, 2026. Trusted deployment operators can capture a bounded, independently encrypted checkpoint of the existing protected journal, stream heads and operation receipts. Comparing an isolated restored database with that retained checkpoint detects an earlier valid journal inventory, including a whole missing stream that an ordinary chain check alone cannot identify.

This is an operator command, not a staff report or browser API. It appends no event, runs no projector or event handler, repairs no record and changes no access or financial decision. It does not create a database or document backup, verify all application projections/files, certify archive completeness before capture or establish an independent integrity anchor unless operators actually retain the checkpoint and its independent key under separate protected custody.

## Prepare a checkpoint for a backup or upgrade

Use paths visible to the PHP interpreter running the command. Each destination directory must already exist, be owned by that operating-system user and have private permissions, normally `0700`. Files use `0600`; symbolic links, public-tree destinations, nonprivate files/directories and existing output files are refused. Use canonical absolute paths without symlinked parents or `..` segments. Keep recovery material outside version control and ordinary application artifact cleanup.

1. Choose a stable installation scope such as `campus-production`. Use the identical scope after restore, even if the database host changes. It is an operator-chosen label, not a student's identity or a newly assigned tenant/support ID.
2. Pause application writes through the existing maintenance procedure, stop background workers and prevent other administrative/database writes during the checkpoint and backup. Maintenance admission alone is not a database lock: a super administrator can still sign in. The command does not pause the application automatically.
3. Generate an independent checkpoint key once in a private recovery directory:

   ```sh
   ddev codex sis:history:anchor --action=keygen --key-file=/private-recovery/checkpoint.key
   ```

4. Capture a new checkpoint using a different new file for each backup boundary:

   ```sh
   ddev codex sis:history:anchor --action=capture \
       --key-file=/private-recovery/checkpoint.key \
       --file=/private-checkpoints/pre-upgrade.anchor \
       --scope=campus-production --rows=2000 --json
   ```

5. Take the database and private-artifact backups through the deployment's approved backup tools while writes remain quiesced. Retain the matching checkpoint reference/time with the backup inventory. Protect the checkpoint independently of the database backup and keep the independent key separately recoverable. A file beside the database on the same disposable volume does not provide independent rollback evidence.

The new key is an authenticated-encryption key for checkpoints only. Key generation prints no key bytes, and an existing key or checkpoint is never overwritten. Checkpoint writes reject any key that matches the currently configured application key or a loaded event-history key. Operators must also keep it distinct from other keys outside that loaded set. This command never replaces application/event keys or edits `.env`.

## Compare an isolated restore

Restore into isolated output and configure that copy with the required original application/event keys. Do not expose it to users or resume workers while checking it. Supply the independently retained checkpoint, its key and the original installation scope:

```sh
ddev codex sis:history:anchor --action=compare \
    --key-file=/private-recovery/checkpoint.key \
    --file=/private-checkpoints/pre-upgrade.anchor \
    --scope=campus-production --rows=2000 --json
```

| Outcome | Meaning and next action |
| --- | --- |
| `matched`, exit 0 | The complete authenticated journal/head/receipt inventory matches the retained checkpoint. Continue the separate projection, encrypted-artifact, deployment and institution-specific restore checks before deliberately switching readers. |
| `mismatch`, exit 1 | The authenticated inventory or installation scope differs. A genuinely older backup or legitimate writes after capture can cause this. Keep the copy isolated and review backup selection/scope; the command does not overwrite either side. |
| `not_verified`, exit 1 | Private files, independent/event keys, inventory limits, journal authentication or stability did not permit a complete check. Resolve the cause and repeat; a limited traversal never becomes a successful checkpoint. |
| `invalid_input`, exit 2 | Correct the action, required paths/scope or row bound. Invalid options are rejected before storage resolution. |

Plain output uses fixed operator messages. JSON returns only outcome, checkpoint ULID/time and aggregate stream/event/receipt counts, or just the failure status. It omits keys, paths, scope, digest, event identities/payloads and cursors. The encrypted checkpoint contains a strict format, its own ULID, UTC time, installation scope, counts and inventory digest, not event bodies or a parallel journal. Automatic debug/JSON inspection of its value object is redacted.

An ordinary `sis:history:verify` can succeed on an older internally consistent database. The independent checkpoint adds evidence that its complete protected inventory differs from the expected backup boundary. A fresh checkpoint created after restoring cannot establish that the restore matches a previously retained state. Losing the independent checkpoint/key requires a separate recovery decision; silently replacing them destroys that comparison evidence.

## Event-key rotation and recovery

The existing [event keyring](event-history-foundation.md#encryption-and-key-rotation) supports a new active key while retaining older key identifiers/bytes. This workflow does not re-encrypt history or retire keys.

1. Back up the application key, complete event keyring, independent checkpoint material, database and encrypted private artifacts under the institution's recovery policy. The application key still protects other artifacts and must not be replaced as a shortcut for event-key rotation.
2. In a private editor or approved key-management process, prepare the event keyring with every existing key under its original identifier and the new Defuse key under a new identifier. Never put key bytes in shell arguments, logs, queued jobs or source control. Preserve the original application key as `app-v1` when leaving the default event configuration.
3. Select the new `EVENT_HISTORY_ACTIVE_KEY_ID`, retain the complete `EVENT_HISTORY_KEYS_FILE` and reload processes. Subsequent envelopes use the new key; existing event bytes, protected heads, receipts and captured cursors still require their original keys.
4. Exercise the transition on an isolated restore first. Verify earlier streams, original receipts and a pre-rotation cursor; then verify a post-rotation checkpoint after new records exist. Removing an old key or replacing its bytes must fail authentication. Recover the original key bytes and repeat the check without rewriting events.

The fictional recovery cases prove mixed-key reads and new-key writes, preserved original event bytes, old cursor continuation, receipt verification, missing/replaced-key failure and recovery after restoring the correct ring. The configured MariaDB check generated only an ephemeral independent checkpoint key; it did not rotate any real application/event key.

## Bounds and qualification

The default inventory allowance is **2,000 combined rows** across the three tables; `--rows` accepts **1–10,000**. Rows are read in keyset pages of at most ten. The inventory has a **16 MiB** serialized-byte ceiling and a five-second cooperative deadline. The service requires complete existing chain/reference/receipt verification, with at most 64 bounded verification passes and a ten-second cooperative overall allowance. Deadline checks occur between blocks/queries; configure database timeouts separately and use a process deadline for a hard operator limit. A blocked driver call cannot be interrupted by a PHP clock check.

Capture fingerprints the inventory before and after complete authentication and refuses changes or incomplete verification. The verifier retains its own five-second per-call bounds. This requires a stable journal; it is not a global database snapshot/lock or proof against an administrator who controls all keys, backups and checkpoint custody. Larger archives, resumable complete-inventory anchors, external/WORM custody, trusted capture procedures and independent security review remain qualification work.

The fingerprint includes all persisted columns of the three protected tables, normalizing scalar PDO representations while preserving ciphertext and nulls. A schema/envelope rewrite can intentionally change it. Cross-driver restores or future formats require explicit reconciliation rather than accepting a mismatched digest automatically. No schema migration, event conversion or key retirement is part of this feature.

Focused tests use disposable complete SQLite database copies and real journal services, with read-only restored connections. A native MariaDB session restricted to read-only transactions also captured and matched the configured twenty-event inventory without changing its checksum; its temporary artifacts were removed. This qualifies those bounded journal checks, not all PDO engines, Windows/private-filesystem behavior, encrypted-artifact restoration, full database/projection recovery or production load.
