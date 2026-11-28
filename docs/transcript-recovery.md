# Verify a restored transcript original

An issued transcript retains its encrypted original PDF, accessible HTML and preparation evidence in
`sis_transcript_documents`. Recorded handoff evidence is encrypted separately in `sis_transcript_deliveries`.
These particular artifacts are part of the **database backup**, not regenerated files in a cache or theme folder.
Other private documents and application files still require their own backup and restore procedures.

The [encrypted backup and isolated recovery workflow](encrypted-backups.md) captures the complete database alongside configured private files and recovery keys. After importing its verified package into an isolated database, use the selected transcript checks below for expected issued originals.

Trusted deployment operators can check one retained document without rendering it again, comparing it with today's
grades, accessing a mail provider or writing any audit/history record:

```sh
ddev codex sis:history:verify --transcript-document='<sis_transcript_documents.id>' --json
```

Use the document identifier, not the transcript-request identifier. This selector is exclusive: do not combine it
with other subjects, `--events`, `--streams` or `--cursor`. It authenticates bounded stored artifacts rather than
replaying events. The existing exact `sis:history:verify` command is available during maintenance mode.

## What the result establishes

The check shares the retained-packet reader used by ordinary transcript reads. It verifies authenticated decryption,
the document identifier and issue-time binding, retained layout description, HTML hash/encoding, PDF hash/signature
and size, and recorded preparation release-check evidence. It also checks that the referenced request exists with
a compatible revision counter. If delivery evidence exists, it verifies its document/operation/request relationships,
consistent delivery timestamp and retained institutional confirmation. The original remains valid after later grade,
catalog or layout changes; this command does not recalculate or release it.

| Status | Exit | Meaning |
| --- | --- | --- |
| `verified` | 0 | The selected retained artifact and available evidence pass the declared checks. |
| `unverified` | 1 | A required record, key or consistent authenticated artifact is unavailable. Fixed issue codes identify `document`, `request`, `delivery` or `unavailable`; no content is printed. |
| `changed` | 3 | A captured row changed during the check. Retry against quiesced or isolated storage. |
| `limited` | 3 | The ciphertext or cooperative time allowance prevented completion. No partial success is reported. |

Invalid options return the existing command's `failed`/`invalid_input` error with exit 2 before resolving storage.
JSON returns fixed status/component metadata, the selected opaque document reference and booleans; it excludes
names, student/request identities, grades, recipient details, evidence text, hashes, ciphertext, keys and document
contents. `records_changed` is always false. Plain output uses fixed English operator messages.

`delivery_present=false` means no delivery was recorded for this document; that is valid for a prepared original.
A null value means the check did not reach delivery inspection. `preparation_release_recorded` and
`delivery_release_recorded` explicitly distinguish earlier absent confirmations. The reader preserves those earlier
packet shapes without inventing confirmations or rewriting artifacts. On any incomplete result, checked/release
booleans do not claim a successful partial verification.

This verifies internal consistency of the selected stored artifact. It does not independently prove historical
issuance, every request/student/actor association, archive completeness, actual recipient receipt or fresh academic
eligibility. No current catalog/template is substituted, no missing document is reconstructed and no delivery,
notification, refund, access grant or business decision is repeated.

## Exercise database and key recovery together

1. Follow the [independent checkpoint procedure](history-checkpoint-recovery.md#prepare-a-checkpoint-for-a-backup-or-upgrade).
   Pause writes and workers, capture the protected journal boundary and take the database/private-file backups while
   the same boundary remains quiescent. Maintenance mode alone is not a global write lock.
2. Keep the original application encryption key, complete event keyring and independent checkpoint key recoverable
   under their separate custody rules. Event keys authenticate journal history; the application key decrypts retained
   transcript artifacts. Replacing one key with a newly generated value does not recover its earlier ciphertext.
3. Record the expected document references in the protected backup inventory. A selected check needs a known
   reference: a restored database that omitted an entire document cannot identify that missing original by itself.
4. Restore to an isolated copy, supply the original keys and leave users, outbound integrations and workers disconnected.
   Run the [checkpoint comparison](history-checkpoint-recovery.md#compare-an-isolated-restore) with the retained checkpoint
   and installation scope. Do not capture a new checkpoint to excuse a mismatch.
5. Run the selected artifact command for the expected original documents in bounded individual invocations. The
   diagnostic acquires no business write locks and works with writes prohibited. An already open transaction is
   refused, so an inherited repeatable-read snapshot cannot disguise concurrent changes during its source rechecks.
6. Investigate a missing, damaged or unverifiable artifact in isolation. Restore the correct original backup/key
   material, then repeat both checks. Do not regenerate an old official transcript from today's academic facts.
7. Complete the separate projection, private-file, deployment and institution-owned acceptance checks before switching
   readers or resuming workers. This command performs no switch or repair.

The fictional recovery exercise copies an entire populated SQLite database to a private isolated file, preserves a
separately encrypted journal checkpoint and verifies the prepared PDF/HTML plus actual recorded delivery there.
It demonstrates that missing/wrong application keys block artifact reads while the journal can still match; missing
event keys block the journal comparison while the artifact can still decrypt. Restoring the correct providers
reestablishes both checks without rewriting the source. Damage to the restored document is detected even when the
journal checkpoint still matches. The original database, artifacts and recorded events remain unchanged.

## Bounds and remaining qualification

Each invocation reads one document, its small request metadata and at most one delivery record, then rechecks the
captured rows. SQL limits each read to one row and each ciphertext to 13 MiB plus one detection character; oversized
ciphertext is refused before decryption. Existing payload/PDF/HTML limits remain 6 MiB, 4 MiB and 1 MiB respectively.
The five-second cooperative budget includes source rechecks; it cannot interrupt a blocked database call. Configure
deployment statement/lock deadlines and an operator process limit separately.

Five-driver SQL cases cover bounded reads and the native PostgreSQL lookup queries are exercised read-only.
The configured development database currently contains no transcript documents; its rows remain unchanged.
The populated restore exercise uses fictional SQLite records. This completes selected transcript-artifact diagnosis
and that recovery scenario, not every engine, separate filesystem-artifact restoration, large archives, independent
custody, a complete backup inventory or institution-led recovery acceptance. Those remain on the
[development roadmap](development-roadmap.md#8-release-qualification-and-operations).
