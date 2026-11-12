# Sign-in and Moodle launch reference retention

`ddev codex sis:auth:prune-sessions` previews one bounded batch of expired authentication support records. The default preview changes no records. These records connect browser sign-ins to Moodle access; they are separate from academic history and the authoritative event store.

```sh
# Preview, using a 24-hour grace period after the original sign-in expiry.
ddev codex sis:auth:prune-sessions

# Explicitly remove one eligible batch.
ddev codex sis:auth:prune-sessions --apply

# Example of a longer institutional retention window and a smaller batch.
ddev codex sis:auth:prune-sessions --limit=50 --grace-hours=168
```

Without DDEV, use `codex sis:auth:prune-sessions` with the application's configured runtime. The command extends Codefy's `ConsoleCommand` and is registered through the application configuration. It has no browser or API route. Its storage dependencies are resolved only after valid options are supplied, so registering the command or asking for help adds no retention database work.

## Bounds and eligibility

The default limit is **100 examined candidates per table**; permitted values are 1–500. One run can therefore remove at most 200 rows by default, or 1,000 with the maximum limit. It does not drain an entire table in a loop. The existing `(expiry, id)` indexes and server-side SELECT limits bound the candidate reads. Output contains aggregate counts and the UTC cutoff, without account identifiers, cookies, tickets or credentials.

The default grace period is **24 elapsed hours beyond original expiry**. Institutions can choose 1–8,760 hours through `--grace-hours`. The command freezes one UTC cutoff for the invocation. Use a correctly synchronized server clock, and preview again after any significant clock correction. Only expiry values strictly older than that cutoff qualify; equality is retained. Activity and observation timestamps do not shorten retention. Revoking a sign-in does not make an unexpired revocation record eligible for deletion.

Moodle launch records use their **session deadline**, not their one-use ticket deadline. A consumed ticket may already represent an active Moodle session, so its 60-second ticket lifetime alone is insufficient grounds for cleanup. Unconsumed launches use the same conservative retention window in this implementation.

## Applying a batch

1. Select at most the limit of expired Moodle launch candidates, validating their canonical identifiers and stored UTC deadlines. Invalid examined metadata is retained and counted.
2. Remove eligible launch references using prepared, single-record conditional deletes that recheck the selected expiry and cutoff.
3. Select at most the limit of expired sign-in candidates. Retain every sign-in that still has any Moodle launch reference, including references outside this batch or with future/invalid deadlines.
4. Remove eligible sign-ins with another conditional expiry check and an atomic `NOT EXISTS` reference guard. Commit the batch together. A failure rolls back both tables' deletions.

Preview accounts for the launch references its own candidate batch could remove, so it can show the parent sign-ins that would become eligible in the same batch. A preview is an observation, not a reservation: an explicit application selects and checks current state again. Changed records can be skipped, and concurrent operators may make the later counts differ.

The only deletion targets are `sis_moodle_launches` and `sis_login_sessions`. Person links, installation approvals, provisioning receipts, accounts, academic facts and domain events remain outside this command. Missing support tables stop the command with a fixed diagnostic; apply the existing sign-in/launch migrations first. No new migration is required for this feature.

## Operations

A **candidate limit reached** message means additional rows may remain; it does not prove that every examined candidate was removable. With many expired launches for one sign-in, several bounded runs may remove its launches before the sign-in can be removed. Invalid metadata or retained references can occupy the oldest candidate window. Inspect those conditions through authorized database/support procedures rather than increasing limits indefinitely or deleting active references manually. The command intentionally does not scan the rest of the table to find records past such a window.

Choose retention and scheduling through institutional operations policy. No schedule is enabled automatically. Preview first; if appropriate, schedule one small `--apply` invocation at a time through the institution's scheduler, with its normal execution timeout and database monitoring. Lock contention or database availability can still delay a bounded batch. The command makes no Moodle network request and does not hold academic stream locks.

Expired native Moodle sessions remain Moodle's responsibility. This command does not directly clear browser cookies or delete Moodle sessions. A removed, expired etSIS reference cannot reactivate its expired cookie or authorize a new Moodle launch. Any future integration that retains sign-in references must be included in the parent-reference guard before enabling cleanup for that integration.

The automated checks use fictional SQLite data and exercise default preview behavior, actual console registration, option limits, grace boundaries, daylight-saving transitions, consumed/unconsumed launches, active sign-ins and revocations, invalid metadata, changed expiry, reference guards, transaction rollback and refusal of expired cookies after cleanup. The SQL uses the framework's prepared operations and bounded SELECT adapters for its five supported databases. Live application verification uses a read-only MySQL preview; it does not establish live cleanup acceptance on other database engines.
