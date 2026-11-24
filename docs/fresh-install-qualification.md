# Fresh installation qualification

On November 24, 2026, the current application completed a fresh **PostgreSQL 18.7** installation and the equivalent SQLite setup flow. Both use isolated fictional storage and the real framework commands, providers, registration route, authentication and access controls. They copy no installed application DDL or student rows. This complements the [native registration/finance qualification](native-workflow-qualification.md), which deliberately uses installed definitions.

## Completed setup flow

The installation runner:

1. Creates a private application directory with its own environment, storage, caches and newly generated encryption key.
2. Runs ordinary environment setup twice, preserving the accepted base path and environment.
3. Applies all **208 migration files** through `codex migrate`, then repeats the command. The native check confirms exactly 208 ledger entries and validated PostgreSQL constraints.
4. Rejects public registration without CSRF before any account write; rejects a supplied `super` role while creating the ordinary account and exactly one canonical person linked by the genuine account ULID.
5. Selects the existing fictional account through the interactive first-administrator command without printing its identity. Only the first bootstrap succeeds.
6. Signs in with the real authentication route and encrypted cookie, opens the backend, and verifies the private/no-store response.
7. Creates and deactivates a subject through the authorized CSRF-protected catalog routes, exercising numeric flags through the actual providers and repositories.
8. Verifies that authentication/reference edits leave the original profile events intact, authenticates the journal, confirms the legacy `event_store` table is absent, and preserves the newly generated encryption key.

The guard also refuses an unowned ordinary database name before connection. A marked fixture containing a sentinel table is refused before migrations; its original row remains and no migration ledger is created. The test deliberately removes that fictional sentinel before proceeding with the empty installation. It never clears an arbitrary database to make setup succeed.

These checks establish selected fresh-install and initial-access behavior. They do not qualify every native workflow, upgrades with institutional data, other engines/versions, external mail/Moodle/payment services, full restore, browser accessibility or institution-led acceptance.

## Corrections exposed by the check

**Numeric flags.** Ten migrations used native boolean columns while their repositories persist numeric `0`/`1` values. PostgreSQL rejected the first boolean default with SQLSTATE 42804. The definitions now use tiny integer flags and numeric defaults, matching the application persistence contract and the previously converted PostgreSQL schema. This affects offering templates, subjects/classifications, Moodle ownership, curriculum choices/reports, booking categories, account-entry reports, course-number provenance and public-program visibility. All existing flags and business meanings remain the same. The configured development database already has numeric columns, so this bundle applies no migration or data conversion to it.

**Column changes.** The installed PostgreSQL compiler inherited `MODIFY COLUMN` syntax, causing SQLSTATE 42601 when making application forms program-independent. The existing PostgreSQL connection now supplies a small application compiler using `ALTER COLUMN … TYPE` and independent `DROP/SET NOT NULL` actions. Unspecified nullability and defaults are preserved. Vendor source and other database compilers are unchanged. Fresh migrations and focused compiler tests cover the correction; populated institutional upgrade acceptance remains separate.

**Explicit account identity.** Xdebug observed SQLSTATE 42P01 at the library's `lastInsertId` boundary during fresh public registration: the user projection had relied on the default numeric `id` convention. Its explicit `user_id` metadata exposed a second issue, SQLSTATE 23502: the library's generic `set()->save()` omits primary keys from dirty fields. The projection now inserts the genuine event-supplied user ULID directly and declares its real key. Email/ID/token lookups set user-key metadata on their own table query rather than mutating the shared builder used by later catalog/finance work. Existing events, accounts, passwords and person links are not rewritten.

An adjacent fixture correction gives admissions evidence its own private temporary storage root and cleanup. It prevents a test from bootstrapping the configured application merely to resolve a storage path. Production document storage is unchanged.

## Run the selected checks

Use the configured PHP interpreter with PDO SQLite and the selected native driver. `phpunit.install.xml` selects only `AlphaInstallTest`; it uses the same explicit opt-in and owned database lease as the native workflow runner. For the current PostgreSQL workstation:

```sh
ETSIS_WORKFLOW_NATIVE_QUALIFICATION=1 \
ETSIS_QUALIFICATION_ENDPOINT='127.0.0.1:54329' \
ETSIS_QUALIFICATION_CONTROL_FILE='/absolute/private/control.json' \
PHP_INI_SCAN_DIR='/etc/php84/conf.d:/absolute/project/storage/framework/runtime/php84-pgsql/qualification-ini' \
XDEBUG_MODE=off timeout --kill-after=15s 65s nice -n 19 \
    /usr/local/bin/php84 -d memory_limit=128M -d zend.exception_ignore_args=1 \
    vendor/bin/phpunit -c phpunit.install.xml --stop-on-error --stop-on-failure
```

The private control file contains only the database-creation operator's `username` and `password`, with mode 0600 in a mode-0700 directory. Follow the [native runner's control-file procedure](native-workflow-qualification.md#run-the-selected-cases). Creation uses the same server as the configured application; the runtime application role receives no CREATEDB grant. Each test targets a generated, marker-verified `etsis_qualify_…` database. Native settings reach the installation runner through private stdin; they are not process arguments or copied into an institutional `.env`. Child processes receive the fixture connection through their private process environment; external-service and history-key settings are explicitly overridden. The old `ETSIS_TEST_INSTALL_DSN/USER/PASSWORD` override mechanism is removed.

Normal child stages have 128 MiB and 30-second limits. One stage runs at a time within the installation runner; the native parent has a 60-second limit. The existing PostgreSQL container remains capped at 256 MiB and half a CPU. The complete three-case installation run passed in **11.116 seconds**, with **18 MiB reported by the PHPUnit parent** and **15 assertions**, plus the internal route/identity/history checks above. This is not a measurement of combined child/server peak memory.

The ordinary test configuration runs the SQLite and pre-connection refusal cases and skips the native case unless explicitly enabled. The MySQL branch can use the same owned runner, but this bundle does not claim a fresh MySQL/MariaDB rerun. Other engine acceptance remains on the roadmap.

## Preservation, cleanup and regression evidence

The runner uses mode-0700 temporary application directories and restrictive file creation. Fictional keys, passwords, cookies, endpoint settings and responses stay out of routine output. Failed stages expose only fixed stage/migration names and SQLSTATE categories. Mail, Moodle, AI and gateway effects are disabled. Do not use an institution's mailbox, artifacts or student records as fixtures.

Successful completion removes the temporary application tree, owned database and ownership lease. If execution is forcibly interrupted, retain the private lease and verify its exact database/marker before cleanup using the existing native procedure. Never delete by wildcard or reuse the configured application database as an installation target. Temporary control credentials used for qualification are removed after validation; the original PostgreSQL service secret, application keys and encrypted conversion backups remain intact.

Read-only before/after checks confirmed the configured application still has **17 protected streams, 20 events and 20 receipts**, with an unchanged inventory fingerprint and a passing authenticated verifier. No generated PostgreSQL fixture databases remain.

Adjacent regression validation covers **182 distinct SQLite/unit cases / 3,431 assertions**: 75 migration/compiler/report cases and 47 account/identity/authentication cases overlap in two compiler cases; 62 additional admissions cases cover the evidence-fixture change. The installation suite adds its three separate cases, including the native PostgreSQL flow. Coding-standard and whitespace checks cover the changed/new code. No browser UI, report dataset, permission, event format or live business-data migration is added.
