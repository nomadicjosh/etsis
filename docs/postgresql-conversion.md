# MariaDB to PostgreSQL conversion

The configured development application moved from MariaDB to PostgreSQL on November 24, 2026. `.env` now selects `DB_CONNECTION="pgsql"`. The existing encryption key, identity keys, business publications and protected history were preserved. No application records were replaced with test fixtures.

## Verified cutover

The conversion copied **220 tables and 1,040 rows**, retained **220 primary keys**, **143 unique indexes** and **309 foreign keys**, and validated every foreign key. Before switching, every table's column values, NULLs, empty strings, count and SHA-256 fingerprint matched the source. An independent pass repeated those checks against the committed target and authenticated the encrypted backup files.

The existing read-only history verifier completed all **17 streams, 20 protected events and 20 operation receipts**, including their event references. The original encrypted event bytes and their ordered digest remained unchanged. The application migration manager recognized the copied ledger with no pending entries. The homepage and sign-in page both returned HTTP 200 after maintenance was cleared. This checks public availability and stored history; it does not establish successful password authentication for every account or qualify every institutional workflow on PostgreSQL.

Three isolated native conversion tests passed with 15 assertions. Their generated MariaDB/PostgreSQL databases contained fictional rows only and were removed afterward. They cover exact values and NULL/empty distinctions, unsigned big integers, defaults, next identity values, case/accent-insensitive lookup and uniqueness, foreign-key deletion, complete transactional rollback for an unsupported column, and rejection of a populated destination. The focused migration/compiler, maintenance, sign-in authority and history regression batch passed 58 tests with 2,804 assertions. The final maintenance-policy check passed two tests with 42 assertions. Coding-standard checks cover the conversion implementation and its tests.

The subsequent [native workflow qualification](native-workflow-qualification.md) now covers eleven selected PostgreSQL cases and repairs ordinary account-opening inserts. Broader native workflow/concurrency qualification, independent backup custody and full restore acceptance remain open on the roadmap. Selected fresh PostgreSQL/SQLite migrations and initial access now pass the [fresh-install checks](fresh-install-qualification.md).

## Local services and connections

The new application database runs in `ddev-etsis-postgres`, with PostgreSQL 18.7 at cutover. The local `.ddev/docker-compose.postgres.yaml` service retains its data in the `ddev-etsis_etsis-postgres-data` named volume. It allows **256 MiB memory, no additional swap, half a CPU and 20 connections**, with parallel query workers disabled. The host port is bound only to `127.0.0.1:54329`; containers use `ddev-etsis-postgres:5432`. The application role is `etsis_app`; its password stays in the private environment file.

The main DDEV database remains MariaDB as the preserved rollback source. Therefore `ddev mysql` and the existing MariaDB IDE connection still refer to the old database. Application code uses PostgreSQL through `.env`. Do not change DDEV's primary database type, remove either database volume or assume the two databases stay synchronized. Copy the ignored local service definition separately when moving this workstation setup. A reference service definition is supplied in [config/development/postgres.yaml](../config/development/postgres.yaml); place it at `.ddev/docker-compose.postgres.yaml` after preparing the private secret and target volume. The volume is explicitly external so service recreation retains it; a new workstation must create that named volume before starting the service. Compose manages the running container and its health check.

A new PhpStorm datasource, **etSIS PostgreSQL (127.0.0.1:54329)**, selects the PostgreSQL driver, database `nomadicjosh_sis` and role `etsis_app`. The connector cannot transfer the private password into the IDE credential store. Enter the current `DB_PASSWORD` from `.env` in that datasource's password field and save it in the IDE password store. Do not put it in a JDBC URL. Its connection test still needs completion in the IDE.

The configured host PHP interpreter lacked PDO PostgreSQL. A compatible extension was built once from verified PHP 8.4.17 sources, without changing the system interpreter. Host commands currently need:

```sh
/usr/local/bin/php84 \
    -d extension=storage/framework/runtime/php84-pgsql/pdo_pgsql.so \
    -d memory_limit=96M codex COMMAND
```

The compiled module is local runtime material and must be rebuilt for a changed PHP ABI. DDEV's PHP 8.4 already provides PDO PostgreSQL, so normal `ddev codex` commands need no extra extension flag. When running host PHP, the container hostname is unavailable: override only the DSN with the published endpoint, leaving credentials in `.env`:

```sh
DB_DSN='pgsql:host=127.0.0.1;port=54329;dbname=nomadicjosh_sis' \
    XDEBUG_MODE=off timeout 20 nice -n 19 \
    /usr/local/bin/php84 -d memory_limit=96M -d zend.exception_ignore_args=1 \
    -d extension=storage/framework/runtime/php84-pgsql/pdo_pgsql.so \
    codex sis:history:verify --streams=50 --events=200 --json
```

## Conversion operation and limits

`sis:database:postgres` extends the ordinary `ConsoleCommand` and is an explicit deployment operation. It requires maintenance mode, a MySQL-family source selected by `.env`, and an empty PostgreSQL destination. A private JSON file under `storage/app/database-migration/` supplies `dsn`, `username`, `password`, and, for `--switch`, `application_dsn`. Restrict that directory to mode 0700 and the file to 0600. Never put credentials in shell arguments, fixtures, logs or version control.

Stop or drain application writers, workers and already-running commands before conversion. Maintenance alone does not terminate existing work. At cutover the web service was stopped, and a separate MariaDB connection held read locks across the source tables until verification, encrypted backup and the atomic `.env` replacement finished. A consistent read-only source snapshot and one PostgreSQL transaction protect the copy. Table locks have a three-second wait; target statements have a ten-second timeout. Run one process with a 128 MiB limit and a short external deadline.

The converter supports the inspected schema's integer, varchar, text, date and datetime columns and ordinary BTREE indexes. Unsigned values retain nonnegative checks and enough target range. Legacy counters retain identity sequences; ULIDs remain unchanged. Text uses the ICU `und-u-ks-level1` nondeterministic collation for case/accent-insensitive comparisons. This approximates the source collation's behavior; it is not a promise of identical Unicode sorting for every possible future string. PostgreSQL 18 is required for the application's `LIKE` searches with that collation; see the [PostgreSQL matching documentation](https://www.postgresql.org/docs/18/functions-matching.html). Index and foreign-key names are generated deterministically within PostgreSQL's identifier limit; maintenance SQL must inspect the resulting names rather than assume MariaDB's names.

The inspected source had no views, triggers or check constraints. Unsupported column types, generated columns, collations, views, prefix indexes or non-BTREE indexes stop the conversion. The operator must inspect other schema objects before using this narrow converter on another installation. This is not a general-purpose database migration utility. It refuses more than 300 tables, 10,000 total rows or 8 MiB of canonical row data; larger institutions need a separately designed bounded streaming migration.

`--source-endpoint=127.0.0.1:PORT` changes only the source connection endpoint when host PHP cannot resolve its container hostname. `--switch` replaces the four database settings only after the target verifies and encrypted backups are saved. Without it the source remains the application connection. A committed target whose backup or environment switch subsequently fails remains populated: inspect it privately before recovery; do not rerun against it or discard it blindly.

## Private backups and rollback

The private `storage/app/database-migration/` directory retains:

- `mariadb-snapshot.enc`: original table DDL and every original row, encrypted with the existing application key.
- `mariadb-environment.enc`: the original environment for the source connection.
- `postgres-environment.enc`: the accepted target environment.
- `verified-manifest.enc`: encrypted per-table counts, byte counts and fingerprints.
- `postgres-admin-password`: the mode-0600 PostgreSQL container secret, required for service initialization/recreation.

Keep `.enc.key` and the environment/history protection keys with an institution-approved backup process. These local files and the original MariaDB volume provide a development rollback basis; they are not independently stored disaster recovery evidence. The administrator password is a service secret, not an encrypted backup, and must remain private. Temporary conversion/test connection files can be removed after verification; retain no plaintext row dumps.

A rollback requires a maintenance window, drained writers, a fresh backup of PostgreSQL and a check for decisions recorded after cutover. Reconcile those decisions before switching back. Restoring the source environment without that review would hide later PostgreSQL work. A trusted operator can authenticate/decrypt the saved source environment with the original key, validate the preserved MariaDB database, and replace `.env` atomically with mode 0600. Keep PostgreSQL intact until the recovery is accepted. Do not generate new history keys or fabricate events to make a comparison pass.

## Repeat the fictional conversion tests

The default test suite skips `tests/Native/PostgresDatabaseConversionTest.php`. Opt in with `ETSIS_POSTGRES_CONVERSION_TEST=1` and point `ETSIS_CONVERSION_TEST_CONFIG` at a private mode-0600 JSON file. It contains `mysql` and `pgsql` objects with `dsn`, `username` and `password`, using explicitly selected `127.0.0.1` server ports and database names. Each server role must be allowed to create/remove its own generated test databases. The configured application database is never a write target.

Run only that file with the configured interpreter, both PDO extensions, a 96 MiB memory limit and a 30-second deadline. The harness writes a private ownership lease before creating each unpredictable `etsis_convert_…` database. Successful completion removes both databases and the lease. If the process is forcibly terminated, retain the lease and verify the exact generated names before manual cleanup; never use an application database or wildcard deletion as recovery.
