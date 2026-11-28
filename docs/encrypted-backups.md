# Encrypted backups and isolated recovery

Trusted deployment operators use `sis:backup` to capture the database, private application files and recovery keys in an encrypted [Restic repository](https://restic.readthedocs.io/en/stable/030_preparing_a_new_repo.html). The repository can be a private local directory or an HTTPS S3 bucket/prefix. A completed bundle pins its native database export, file inventory, deployment release and verified journal checkpoint. No browser, staff report, account impersonation or second event store is involved.

The implemented exporters are PostgreSQL and local file-backed SQLite. Other database engines stop explicitly; use their separately reviewed native backup procedure until an adapter is qualified. These logical backups complement deployment recovery. PostgreSQL cluster roles/tablespaces, physical replication and point-in-time recovery remain infrastructure operations.

Restoration creates a new private recovery directory containing the database export and recovered files. It verifies the saved hashes and leaves the running database, configuration and application admission unchanged. Import the database into an isolated database and complete the checks below before deliberately switching readers.

## Prepare the operator configuration

Install a reviewed Restic executable (qualified with 0.20.0) and the corresponding native client: `pg_dump`/`pg_restore` for PostgreSQL or `sqlite3` for SQLite. Use a client compatible with the server. PHP needs the application's normal extensions and process execution. No Composer package or global PHP configuration change is required.

Create owned private directories, normally mode `0700`, outside `public/`, caches and backed-up paths. Recovery configuration/password/credential files must be `0600`. Canonical absolute paths are required; symlinks and symbolic parents are refused. Use the actual executable path when a distribution supplies a symlink/wrapper.

Generate an independent repository password with an approved secret-management tool. A local example, run privately, is:

```sh
umask 077
openssl rand -base64 48 > /srv/etsis-recovery/operator/restic.password
php codex sis:history:anchor --action=keygen \
    --key-file=/srv/etsis-recovery/operator/checkpoint.key
```

Neither key is the application `.enc.key` or an event-history key. Preserve every original application/event key separately; generating replacements does not recover earlier ciphertext. Retain the independent checkpoint key and checkpoints under separate custody from the repository/password. Files on one disposable disk alone do not establish independent rollback evidence.

Create a private JSON file such as `/srv/etsis-recovery/operator/backup.json`:

```json
{
    "scope": "campus-production",
    "release": "reviewed-source-reference",
    "restic": "/usr/local/bin/restic",
    "repository": "/srv/etsis-recovery/repository",
    "password_file": "/srv/etsis-recovery/operator/restic.password",
    "work_directory": "/srv/etsis-recovery/work",
    "checkpoint_directory": "/srv/etsis-recovery/checkpoints",
    "checkpoint_key": "/srv/etsis-recovery/operator/checkpoint.key",
    "includes": [
        "/srv/etsis/.env",
        "/srv/etsis/.enc.key",
        "/srv/etsis/storage/app",
        "/srv/etsis/public/themes",
        "/srv/etsis/config",
        "/srv/etsis/composer.lock",
        "/srv/etsis/package-lock.json",
        "/srv/etsis-secrets/history.keys.json"
    ],
    "tools": {
        "pgsql": "/usr/bin/pg_dump",
        "sqlite": "/usr/bin/sqlite3"
    },
    "seconds": 120,
    "bytes": 1073741824,
    "files": 2000,
    "history_rows": 2000
}
```

Adapt every path and the reviewed deployment reference to the installation. Include only existing, intended sources. Remove the explicit keyring path if `EVENT_HISTORY_KEYS_FILE` is unset; otherwise include that exact configured file. If `storage/framework/media` exists, include it too. The configuration enforces `.env`, `.enc.key`, private application artifacts, themes and the configured external event keyring. Add other authoritative external file stores explicitly. Database-resident encrypted transcripts, profile photos and their chunks are captured by the database export. Restore the matching source release/dependencies; a backup is not a code upgrade.

Repositories, work files and independent operator credentials/checkpoints must remain outside included trees. Known Git/cache/session/cookie/log directories are refused. The application and keyring source files must already have private permissions. No backup permission is granted to an ordinary SIS role.

Set `SIS_BACKUP_CONFIG_FILE` to that private JSON path, or pass `--config-file` explicitly. Changing the example file does not enable a scheduled job or modify `.env`. Initialize a new repository once:

```sh
php codex sis:backup --action=init --config-file=/srv/etsis-recovery/operator/backup.json
```

Existing repositories are never reinitialized automatically. Keep repository passwords recoverable outside the repository itself.

## Optional S3 destination

Use an explicit HTTPS repository, for example `s3:https://s3.us-east-1.amazonaws.com/fictional-backups/campus-production`, and add `credentials_file` to the JSON. That separate private JSON file contains only the required AWS credential fields:

```json
{
    "AWS_ACCESS_KEY_ID": "configured-privately",
    "AWS_SECRET_ACCESS_KEY": "configured-privately",
    "AWS_DEFAULT_REGION": "us-east-1"
}
```

Temporary credentials may include `AWS_SESSION_TOKEN`. Credentials are passed privately to the reviewed child process, never command arguments, logs or queued jobs. HTTP, embedded URL credentials and query/fragment secrets are rejected. Configure the dedicated bucket/prefix, access and recovery custody with the institution; see [Restic's S3 backend](https://restic.readthedocs.io/en/stable/030_preparing_a_new_repo.html#amazon-s3). Restic encrypts content before upload. This setting chooses the destination; it does not also maintain a local repository or offload live SIS documents.

Use a separate repository/prefix and independent recovery credentials for each SIS installation.

No real cloud connection is provisioned by installing the feature. Live S3/IAM/KMS/network acceptance remains an operator qualification step. Local repositories and injected S3 configuration are covered by the tests.

## Capture one coherent bundle

1. Enter [maintenance mode](maintenance-mode.md), stop workers/schedulers and suspend every administrative, integration and direct-database writer for this boundary. A super administrator can still sign in during maintenance, so admission alone is not a database write lock.
2. Confirm required files and all recovery keys are present. Use the reviewed source release and explicit institutional limits.
3. Capture from the server:

   ```sh
   php codex sis:backup --action=capture --quiesced --json
   ```

4. Retain the returned opaque bundle and full snapshot reference, plus its separately encrypted `<bundle>.anchor` checkpoint. Preserve the checkpoint/key independently with the institution's recovery inventory. Resume writers only after the result is understood.

The command requires actual maintenance state and the explicit `--quiesced` attestation. It inventories/hashes private files, authenticates the protected journal and retains the independent checkpoint, streams the native export into Restic, captures the selected files and checks the saved database stream. It then rechecks files, journal and maintenance state. Only a successful complete boundary publishes the final manifest snapshot.

PostgreSQL uses a consistent custom-format export, no owner/ACL recreation, read-only session settings and bounded connection/lock/statement waits. Credentials stay out of arguments. SQLite uses its read-only native `.dump`. Restic's [producer-aware input](https://restic.readthedocs.io/en/stable/040_backup.html#reading-data-from-a-command) refuses a failed export rather than accepting an empty piped backup. No plaintext dump file is staged during capture. Before/after checks detect observed drift; operators still must actually quiesce independent writers.

List the latest twenty completed manifests with `php codex sis:backup --action=list --json`. Failure can leave encrypted component snapshots and an independent checkpoint, but no complete manifest is published before all boundary checks succeed. An uncertain reply after a remote final commit may leave a valid completed manifest; review the completed list rather than assuming remote rollback. A retry creates a new backup, without changing business history.

Each bundle references its database, file and final manifest snapshots. Retain all referenced components together. Generic per-path/time pruning can strand a manifest. Automatic pruning, bundle-aware retention/legal holds and repository-password rotation are separate reviewed operations; this feature performs no forget, prune or unlock. Set the institution's backup retention/custody policy before scheduling capture. Use approved Restic tooling and a tested dependency-aware deletion plan.

## Restore and qualify an isolated copy

Use an exact 64-character completed snapshot reference; `latest`, abbreviations and arbitrary restore options are refused:

```sh
php codex sis:backup --action=restore --snapshot=FULL_SNAPSHOT_REFERENCE \
    --destination=/srv/etsis-recovery/restores/new-isolated-copy --json
```

The destination must not exist and its parent must be an owned private directory outside public files. Supply the same installation `scope`. Cold restore does not require the lost source files, export tool, original live database or its current application/event keys. A minimal recovery installation still needs the SIS command/dependencies, Restic, the private operator configuration and repository credentials. Update those operator paths for the recovery host. Keep outbound integrations and users disconnected.

The command validates the manifest and file metadata before extraction, restores only into the new recovery tree, verifies Restic content plus every original file SHA-256/size and the database export digest, and writes `verified.json`. Regular restored files are made private. `database.dump` is a PostgreSQL custom archive or SQLite SQL export; files retain their original absolute hierarchy below `files/`. Source `.env` and keys are recovered there without being installed or evaluated. On failure, preserve the private partial output and `incomplete.json`; repeat into another new directory after resolving the cause.

If a broken environment still references a lost event-keyring file, use the minimal recovery configuration instead of bootstrapping that broken environment.

`verified.json` means the recovery package's bytes match the saved bundle. It does not mean a database has been imported, historical truth independently accepted or the SIS activated. Keep this material on protected recovery storage; it contains decrypted recovery files and configuration. The result explicitly returns `database_changed=false` and `activated=false`.

Complete recovery deliberately:

1. Inspect the retained release/configuration and native export. Restore the database into a **new isolated database** using the original engine's tools and private credentials. For PostgreSQL, use `pg_restore --no-owner --no-privileges --single-transaction --exit-on-error`; select the isolated destination explicitly. Native exports are trusted executable database input, so use only the institution's authenticated backup. For SQLite, feed the SQL to a new private database. Neither step is executed against the running application by `sis:backup`.
2. Recover the original `.enc.key` and complete event keyring under their original identifiers. Configure the isolated application to use the isolated database and recovered private-file locations. Correct copied connection/file paths privately before starting it; do not allow the copied `.env` to reconnect integrations or the production database.
3. Compare the independent `<bundle>.anchor` using the original checkpoint key and scope through [history checkpoint comparison](history-checkpoint-recovery.md#compare-an-isolated-restore). Do not replace a missing/mismatched checkpoint with a newly captured one.
4. Verify expected [issued transcript originals](transcript-recovery.md), selected projections and private documents/photos with their recovered keys. Confirm the configured filesystem, source release, business totals and institution-specific acceptance inventory. Do not replay side effects, regenerate official originals from today's catalog or fabricate missing history.
5. Only after institutional acceptance, plan the separate deployment switch and worker resumption. Existing source storage and rejected recovery copies remain preserved for investigation.

## Bounds and qualification

One nonblocking local operation lock prevents simultaneous commands using the same work directory. Defaults are 120 seconds, one GiB per component and 2,000 files; explicit limits accept 1–1,800 seconds, up to ten GiB and 10,000 files. Directory traversal and metadata are bounded too. The independent history check retains its existing 2,000-row default/10,000-row ceiling. Incomplete traversals never count as complete backups.

An archive beyond the current complete-history bound needs a separately approved backup/checkpoint procedure until resumable larger-inventory qualification is implemented. Increasing the file or process limit does not bypass journal authentication limits.

Child processes use one Go scheduler thread, a 96 MiB Go soft memory limit, one file reader, no compression/cache and a one-MiB/second upload cap. PHP remains separately capped by the operator. The Go limit is not an OS hard memory guarantee; set service/cgroup limits appropriate to the deployment. Use low priority and an outer process deadline for unattended runs. Native exporter waits are bounded. Larger installations require reviewed limits and load qualification rather than browser uploads or silent partial success.

Qualification uses real local Restic encryption, fictional populated SQLite databases, exact protected rows/private artifact keys and actual console/provider cold restore with live database access forbidden. The opt-in PostgreSQL case uses owned disposable databases, fictional records and the same server's installed DDL, restores all SIS rows and compares the independent journal checkpoint using only recovered event keys. It preserves the configured application database and roles. Live S3, other engines, production scale, physical/PITR recovery, retention/legal holds, independent custody/security review and institution-led cutover acceptance remain open.
