# Expired account-setup notice cleanup

Initial account invitations contain a private password-setup link and recipient address. After the link expires,
the system can clear that temporary encrypted notification content even when email delivery is disabled.
This completes notice cleanup for [staff-created person accounts](person-identity-and-accounts.md#staff-created-accounts);
it does not delete the person, account or recorded creation history.

## What is cleared and retained

The cleanup considers only `queued` or interrupted `sending` setup notices whose stored expiry is at least
24 elapsed hours old. The existing link lifetime is 48 hours. The additional grace affects notification storage;
it never extends a link's validity. Each run freezes its cutoff in UTC, including across daylight-saving changes.
An expiry exactly at the cutoff qualifies.

Before clearing anything, the worker verifies the existing authenticated grant against the exact setup reference,
person, account, token hash, credential binding, request time and expiry. Dates must be valid, canonical UTC values,
with request time before expiry. Unverifiable records are retained for operator investigation. Missing encryption
keys are not grounds for deletion or for issuing a replacement link.

| Notice state | Cleanup result |
| --- | --- |
| Verified expired `queued` notice beyond the grace | Clear `encrypted_notice`; mark `expired`. |
| Verified expired `sending` notice beyond the grace | Clear `encrypted_notice`; mark `uncertain`. Delivery success is unknown. |
| Active link, recently expired link within the grace, or already cleared content | Leave unchanged. |
| Unverifiable binding or inconsistent consumed/request/expiry facts | Retain the record and report an aggregate problem count. |

The cleanup reads bounded binding metadata and at most 8,193 characters of the grant, accepting at most 8,192.
It neither fetches nor decrypts the notification body. It never calls a mail transport, resends an uncertain
delivery, changes credentials or appends business events. Existing setup identifiers, grant bytes, hashes and
request/expiry values stay intact, as do users, people, history streams and original operation receipts.

The small setup row remains necessary: **People → Account setup** must still show a pending expired invitation
and permit an authorized, deliberate reissue. Reissue creates a fresh link through the existing permissions,
super-account protection and cooldown. A concurrent reissue is preserved. Retrying the original person-creation
operation returns the original person without recreating or sending an invitation.

## Preview and apply

Run these commands as a trusted deployment operator:

```sh
ddev codex sis:accounts:prune-setup --json
ddev codex sis:accounts:prune-setup --apply --limit=50 --grace-hours=24 --json
```

The default is a read-only preview. `--apply` explicitly permits clearing. The options are:

| Option | Meaning |
| --- | --- |
| `--limit` | Candidates examined in this invocation: default 50, minimum 1, maximum 100. One extra reference may be read to detect another page. |
| `--grace-hours` | Elapsed hours after stored link expiry: default 24, minimum 1, maximum 8,760. |
| `--after` | Resume after a valid opaque setup reference returned as `next_after`. |
| `--json` | Fixed counters, mode, UTC cutoff, time-limit flag and optional continuation reference. No recipient, body, link, token or credential values. |

The result includes `examined`, `eligible`, `cleared`, `invalid`, `changed`, `limited` and `next_after`.
`eligible` counts verified preview candidates or successfully cleared rows in apply mode. Invalid options are
rejected before resolving storage and are not echoed. Exit codes are 0 for a completed clean batch, 2 for invalid
options, 3 for retained unverifiable/concurrently changed rows or a cooperative time stop, and 1 for an unexpected
storage/runtime failure. A successful limited-size page can still return `next_after`.

Each changed row has its own transaction, lock, current-binding comparison, conditional update and final check.
A changed source is retained; a failed final check rolls back the row. A later failure leaves earlier completed
rows cleared. Rerunning is safe because cleared notices leave the candidate set. A five-second cooperative budget
stops further work between reads/updates; it is not a hard database statement or lock timeout. Configure ordinary
database/process deadlines for deployment. No parallel worker or full-table payload load is required.

When `next_after` is present, copy it into a subsequent bounded invocation. The reference orders this scan; it is
not a count or business identifier. A time stop before the first completed row can have a null reference: retry
the same bounded invocation. Each invocation recalculates its own UTC cutoff, so this is an operational scan,
not a historical snapshot. Retained invalid rows need attention: repeated default scans can encounter them again,
and an explicit cursor can continue past them without discarding them.

## Scheduling and operational limits

The normal Codefy scheduler registers `sis:accounts:prune-setup --apply --limit=50` hourly with a single-instance
guard, outside the email-enabled branch. Configure the deployment's usual scheduler runner for automatic cleanup.
The exact cleanup command is also available to operators during maintenance mode; ordinary mail dispatch remains
paused. There is no new browser endpoint, permission grant, report or user-facing settings screen.

No migration or key change is required. Focused fixtures exercise actual clearing, corrupt bindings, large damaged
notice content, interrupted delivery, concurrent deliberate reissue, per-row rollback and original creation retries.
Read-only checks also exercise the new candidate/metadata reads against the configured PostgreSQL database; it
currently contains no setup rows. Native mutation qualification on every supported engine remains release work.

Clearing a live database column is not a promise of secure erasure from backups, database logs, replicas or storage
media. Institution-approved disposition of retained setup rows/bindings, backups, legal holds, broader historical
facts and encryption-key recovery remains part of the [open retention and recovery roadmap](development-roadmap.md#1-historical-definitions-and-securityrecovery).
