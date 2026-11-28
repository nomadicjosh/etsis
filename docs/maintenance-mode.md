# Maintenance mode

Maintenance pauses general access while keeping sign-in available to **current super administrators**. They can use the normal administration screens to perform maintenance. An ordinary administrator, a custom role with broad permissions, or a stale `super` value in an account/cookie does not qualify. The protected, current database role assignment is authoritative.

## Enable, inspect and reopen

Run commands from the project root through the configured interpreter, as the application service account. This checkout uses `/usr/local/bin/php84`; other deployments should use their configured path.

```sh
/usr/local/bin/php84 codex sis:maintenance status
/usr/local/bin/php84 codex sis:maintenance down --retry-after=300
/usr/local/bin/php84 codex sis:maintenance up
```

`status` is the default action and reads without creating storage. `down` accepts an optional retry interval from 30 through 3,600 seconds; the default is 300. The interval supplies `Retry-After`, not an automatic reopening time. Repeating `down` changes the interval and preserves the start of the current maintenance window. `up` explicitly reopens admission and can be repeated safely.

The command extends Codefy's `ConsoleCommand`, uses its argument/output helpers and uses plain console strings. The recovery entry point uses Codefy's console application without booting ordinary providers or connecting to the database. Valid base application configuration and access to private operational storage are still required.

## Sign-in and existing sessions

- The configured sign-in page, password authentication and logout remain available. The sign-in page explains that only super administrators can sign in and hides the ordinary account-registration link.
- Password verification, rate limiting and framework CSRF protection remain in place. Valid credentials for a non-super account and invalid credentials produce the same failure response. A refused sign-in receives no new account cookie.
- A permitted maintenance sign-in opens `/admin/`. Account status and the protected super assignment are checked before issuing the new cookie and again before returning it.
- Existing sessions require a valid encrypted cookie, its matching account token, current sign-in authority and a currently enabled super account. Revoked/expired sessions, revoked super assignments, disabled accounts and legacy role text cannot bypass the pause. Checks run again on subsequent requests; the browser stores no maintenance authority.
- Existing non-super sessions receive the maintenance response. Maintenance does not revoke all accounts or sessions; still-valid sessions can resume normal access after reopening. Ordinary authorization, ownership and reviewed academic/financial command checks continue to apply to super administrators.

General browser requests receive a private HTTP 503 page with a link to the configured super-administrator sign-in page. SIS/API clients receive a small JSON response with `status: maintenance`, a fixed public message and `retry_after`. Responses include `Retry-After`, no-store, noindex and restrictive content-security headers; HEAD has no body. They contain no person values, supplied URLs, credentials or internal exceptions. The page needs no external assets or scripts and supports keyboard focus, narrow screens and enlarged text. Browser strings use the project's translation helpers.

Anonymous requests are stopped before ordinary provider/session initialization or request-body parsing. Existing-cookie candidates are verified before route matching; an untrusted cookie does not gain access even to an unknown URL. Authentication/database failures during that verification return the private maintenance response.

Payment webhooks and Moodle's server session channel stay paused even if a browser cookie accompanies the request. Their 503 response requests a later retry; maintenance never acknowledges or processes a payment notification as successful.

## Background work and recovery tools

The exact `sis:backup` operator command remains available during maintenance. [Encrypted capture](encrypted-backups.md) additionally requires actual maintenance state and an explicit attestation that every worker and administrative writer is paused. Initialization, listing and isolated recovery do not resume admission or activate a restored database.

New application/background command launches return exit code **75** while maintenance is active or its state cannot be read. This includes account-setup mail, campaign delivery, document processing, report exports/pruning, Moodle synchronization/provisioning, the scheduler and generic queues. Programmatic application-kernel dispatch uses the same gate. No queued work is consumed by enabling, inspecting or reopening maintenance.

The exact-name recovery allowlist retains:

- `sis:maintenance` and the bounded, read-only `sis:history:verify` tool;
- the existing, console-only `sis:access:bootstrap` recovery command, which retains its own transactional/audited safeguards and refuses a new bootstrap when a super account already exists;
- `migrate`, `migrate:check`, `migrate:status`, `migrate:up`, `migrate:down` and `migrate:rollback`;
- command help/listing, route listing and schedule listing.

Use full command names. An abbreviated worker name cannot bypass the gate. Inspect the configured database and the proposed migrations before executing schema changes; maintenance does not itself run a migration or authorize destructive cleanup.

## A stable maintenance window

1. Enable maintenance and inspect its status. Confirm the super-administrator sign-in journey and the general-user pause.
2. Stop scheduled launches and drain already-running HTTP requests, workers and external consumers. Maintenance does not interrupt an accepted transaction, kill a running process or cancel an in-flight external request.
3. Coordinate super-administrator activity. Administrators can still make authorized changes, so the pause alone does **not** freeze the database or establish a consistent historical snapshot. Suspend those edits before stable checks, backups or cutover.
4. Use the existing bounded history checks, review required deployment/schema changes, and follow the institution's backup/restore procedure. Keep history checks read-only; do not replay notifications, money movements or access grants.
5. Check the deployment through a super session, explicitly run `up`, and resume approved workers from their existing checkpoints. Unaccepted reviews that expired or whose sources changed need a fresh review. Original accepted receipts retain their ordinary retry behavior.

Full multi-database migration/concurrency qualification, independently protected integrity checkpoints, isolated rebuild/cutover, key rotation and backup/restore certification remain separate roadmap acceptance work.

## Private storage and deployment

The marker is `storage/framework/maintenance/down.json`; `control.lock` serializes control changes. This is operational state, separate from caches, application records and the authoritative database event journal. It contains only the retry interval and UTC activation time. Reads are bounded to 512 bytes and do not modify it. Controls use a private temporary file, atomic replacement and a nonblocking lock. No credentials, reasons, account IDs, student facts or browser preferences are stored there.

The directory uses mode 0700 and its files use 0600 on platforms supporting those permissions. Run controls as the service account that owns this storage so PHP can read it. Keep this namespace out of public mounts and cache-clearing jobs. Existing storage-root mounts/symlinks remain supported; the maintenance directory, marker and lock themselves must not be symlinks or special files.

A missing marker means normal operation. Malformed, oversized, unreadable or unsafe state pauses general admission with the default retry interval. `status` reports the problem without dumping its contents or private paths. Explicit `up` can remove a malformed regular marker; unsafe paths/permissions require operator inspection. Never recursively clear business storage as a maintenance fix.

All application nodes need the same protected operational storage or coordinated controls on every node. Stop independent consumers as part of the window. This implementation does not certify distributed filesystem behavior or an institution's load-balancer/backup configuration.
